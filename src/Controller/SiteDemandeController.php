<?php

namespace App\Controller;

use App\Entity\DemandeDevis;
use App\Entity\ProduitConfiguration;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\GrilleTarifaire;
use App\Service\Site\ContactSite;
use App\Service\Site\DemandesSite;
use App\Service\Site\FormulaireDemande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contact et demande de devis sans compte (maquette 11). La demande est
 * enregistrée, monte au local et y devient un prospect ou la fiche du
 * client du même numéro, avec un devis brouillon que l'équipe vérifie.
 * Aucun prix n'est montré ni envoyé au visiteur.
 *
 * Contre les robots : jeton CSRF, champ piège, temps de saisie minimum,
 * limite par adresse IP et contrôle de chaque champ côté serveur.
 */
final class SiteDemandeController extends AbstractController
{
    private const SESSION = 'site_demande_envoyee';

    public function __construct(
        private readonly NouvelleCommande $catalogue,
        private readonly DemandesSite $demandes,
        private readonly ContactSite $contact,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire(service: 'limiter.site_demande_ip')] private readonly RateLimiterFactoryInterface $limite,
    ) {
    }

    #[Route('/contact', name: 'app_site_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        $objet = (string) $request->query->get('objet', '');
        $saisie = ['nom' => '', 'telephone' => '', 'objet' => isset(DemandeDevis::OBJETS[$objet]) ? $objet : 'devis', 'message' => ''];
        $traitement = $this->traiter(DemandeDevis::NATURE_CONTACT, $request, [], $saisie);
        if ($traitement instanceof Response) {
            return $traitement;
        }

        return $this->page('site/contact.html.twig', $traitement + [
            'objets' => DemandeDevis::OBJETS,
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
            'lienPlan' => $this->contact->lienPlan(),
        ]);
    }

    #[Route('/demander-un-devis', name: 'app_site_devis', methods: ['GET', 'POST'])]
    public function devis(Request $request): Response
    {
        $produits = $this->catalogue->produits();
        $prerempli = FormulaireDemande::preremplir($request->query->all(), $produits);
        $saisie = ['nom' => '', 'telephone' => '', 'email' => '', 'message' => ''] + $prerempli;
        $traitement = $this->traiter(DemandeDevis::NATURE_DEVIS, $request, $produits, $saisie);
        if ($traitement instanceof Response) {
            return $traitement;
        }

        return $this->page('site/devis.html.twig', $traitement + [
            'groupes' => self::groupes($produits),
            'fiches' => self::fiches($produits),
            'calculateur' => $request->isMethod('GET') && '' !== $prerempli['produit'],
            'tailleMax' => DemandesSite::tailleMaxFichier(),
            'extensions' => FichiersClient::extensions(),
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
        ]);
    }

    /** Confirmation (une fois) : numéro de la demande, jamais de prix. */
    #[Route('/demander-un-devis/envoyee', name: 'app_site_devis_envoyee', methods: ['GET'])]
    #[Route('/contact/envoye', name: 'app_site_contact_envoye', methods: ['GET'])]
    public function envoyee(Request $request): Response
    {
        $envoi = $request->hasSession() ? $request->getSession()->remove(self::SESSION) : null;
        $devis = 'app_site_devis_envoyee' === $request->attributes->get('_route');
        if (!\is_array($envoi) || ($envoi['nature'] ?? null) !== ($devis ? DemandeDevis::NATURE_DEVIS : DemandeDevis::NATURE_CONTACT)) {
            return $this->redirectToRoute($devis ? 'app_site_devis' : 'app_site_contact');
        }

        return $this->page('site/demande_envoyee.html.twig', [
            'envoi' => $envoi,
            'devis' => $devis,
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
        ]);
    }

    /**
     * Contrôle et enregistre un envoi. Renvoie la redirection vers la
     * confirmation, ou de quoi réafficher le formulaire avec ses erreurs.
     *
     * @param list<ProduitConfiguration> $produits
     * @param array<string, mixed>       $saisie   valeurs affichées par défaut
     *
     * @return Response|array{saisie: array<string, mixed>, erreurs: array<string, string>, jeton: string}
     */
    private function traiter(string $nature, Request $request, array $produits, array $saisie): Response|array
    {
        $maintenant = time();
        if (!$request->isMethod('POST')) {
            return ['saisie' => $saisie, 'erreurs' => [], 'jeton' => FormulaireDemande::jetonDelai($maintenant, $this->secret)];
        }

        $donnees = $request->request->all();
        $jeton = (string) $request->request->get('_t', '');
        // Le jeton reçu reste valable pour le renvoi (pas de nouvelle attente), sauf s'il est faux ou trop vieux.
        $jetonRenvoi = null === FormulaireDemande::verifierDelai($jeton, $maintenant + FormulaireDemande::DELAI_MIN_SECONDES, $this->secret)
            ? $jeton : FormulaireDemande::jetonDelai($maintenant, $this->secret);
        $vue = static fn (array $erreurs, array $saisie): array => ['saisie' => $saisie, 'erreurs' => $erreurs, 'jeton' => $jetonRenvoi];

        // Envoi plus gros que ce que le serveur accepte : PHP a tout jeté.
        if ([] === $donnees && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            return $vue(['_' => \sprintf('Le fichier est trop lourd pour être envoyé ici (%d Mo au plus) : remplissez de nouveau le formulaire, et envoyez le fichier par WhatsApp ou collez un lien dans le message.', intdiv(DemandesSite::tailleMaxFichier(), 1024 * 1024))], $saisie);
        }

        $saisie = self::saisie($nature, $donnees) + $saisie;
        if (!$this->isCsrfTokenValid('site-demande-'.$nature, (string) $request->request->get('_token'))) {
            return $vue(['_' => 'La page a expiré : vérifiez votre demande puis envoyez-la à nouveau.'], $saisie);
        }
        if (FormulaireDemande::estPiege($donnees)) {
            // Un robot : rien n'est enregistré, et il ne le sait pas.
            $request->getSession()->set(self::SESSION, ['nature' => $nature, 'numero' => null, 'accuse' => false]);

            return $this->redirectToRoute(DemandeDevis::NATURE_DEVIS === $nature ? 'app_site_devis_envoyee' : 'app_site_contact_envoye', [], Response::HTTP_SEE_OTHER);
        }
        if (null !== $erreur = FormulaireDemande::verifierDelai($jeton, $maintenant, $this->secret)) {
            return $vue(['_' => $erreur], $saisie);
        }

        ['valeurs' => $valeurs, 'erreurs' => $erreurs] = FormulaireDemande::valider($nature, $donnees, $produits);
        $fichier = DemandeDevis::NATURE_DEVIS === $nature ? $request->files->get('fichier') : null;
        $fichier = $fichier instanceof UploadedFile ? $fichier : null;
        if (null !== $fichier) {
            try {
                DemandesSite::verifierFichier($fichier);
            } catch (\DomainException $e) {
                $erreurs['fichier'] = $e->getMessage();
            }
        }
        if ([] !== $erreurs) {
            if (null !== $fichier && !isset($erreurs['fichier'])) {
                $erreurs['fichier'] = 'Choisissez de nouveau votre fichier : il n\'est pas gardé tant que la demande n\'est pas envoyée.';
            }

            return $vue($erreurs, $saisie);
        }

        if (!$this->limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $vue(['_' => 'Trop d\'envois depuis cette connexion. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.'], $saisie);
        }

        try {
            $demande = $this->demandes->enregistrer($nature, $valeurs, $fichier, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            return $vue(['fichier' => $e->getMessage()], $saisie);
        }

        $request->getSession()->set(self::SESSION, [
            'nature' => $nature,
            'numero' => $demande->getNumero(),
            'accuse' => null !== $demande->getAccuseEnvoyeLe(),
            'resume' => $demande->estDevis() ? self::sansDemo($demande->getResume()) : $demande->getLibelleObjet(),
            'fichier' => null !== $demande->getFichier(),
        ]);

        return $this->redirectToRoute(DemandeDevis::NATURE_DEVIS === $nature ? 'app_site_devis_envoyee' : 'app_site_contact_envoye', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Ce que le visiteur a tapé, pour le lui remontrer s'il faut corriger.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private static function saisie(string $nature, array $donnees): array
    {
        $texte = static fn (string $cle, int $max): string => mb_substr(\is_scalar($donnees[$cle] ?? null) ? (string) $donnees[$cle] : '', 0, $max);
        $saisie = ['nom' => $texte('nom', 120), 'telephone' => $texte('telephone', 20), 'message' => $texte('message', DemandeDevis::MESSAGE_MAX)];
        if (DemandeDevis::NATURE_CONTACT === $nature) {
            return $saisie + ['objet' => $texte('objet', 20)];
        }
        $finitions = \is_array($donnees['finitions'] ?? null) ? $donnees['finitions'] : [];

        return $saisie + [
            'email' => $texte('email', 180),
            'produit' => $texte('produit', 12),
            'largeur' => $texte('largeur', 12),
            'hauteur' => $texte('hauteur', 12),
            'quantite' => $texte('quantite', 12),
            'finitions' => array_values(array_map('intval', array_filter($finitions, static fn ($v): bool => \is_scalar($v) && ctype_digit((string) $v)))),
        ];
    }

    /**
     * Produits du catalogue publié, par univers (« (démo) » retiré des noms affichés).
     *
     * @param list<ProduitConfiguration> $produits
     *
     * @return list<array{nom: string, produits: list<array{id: int, nom: string}>}>
     */
    public static function groupes(array $produits): array
    {
        $groupes = [];
        foreach ($produits as $p) {
            $groupe = self::sansDemo((string) ($p->getProduit()?->getCategorieProduit()?->getNom() ?? 'Autres produits')) ?: 'Autres produits';
            $groupes[$groupe][] = ['id' => (int) $p->getId(), 'nom' => self::sansDemo(GrilleTarifaire::libelle($p))];
        }

        return array_map(static fn (string $nom, array $liste): array => ['nom' => $nom, 'produits' => $liste], array_keys($groupes), array_values($groupes));
    }

    /**
     * Ce que le script de la page doit savoir de chaque produit : taille
     * demandée, quantité minimum, finitions. Aucun prix.
     *
     * @param list<ProduitConfiguration> $produits
     *
     * @return array<int, array{taille: bool, largeur: ?float, hauteur: ?float, min: int, finitions: list<array{id: int, nom: string, obligatoire: bool, parDefaut: bool}>}>
     */
    public static function fiches(array $produits): array
    {
        $fiches = [];
        foreach ($produits as $p) {
            $f = NouvelleCommande::fiche($p, false);
            $fiches[(int) $p->getId()] = [
                'taille' => $f['taille'],
                'largeur' => $f['largeur'],
                'hauteur' => $f['hauteur'],
                'min' => $f['min'],
                'finitions' => array_map(static fn (array $fi): array => ['id' => $fi['id'], 'nom' => self::sansDemo($fi['nom']), 'obligatoire' => $fi['obligatoire'], 'parDefaut' => $fi['parDefaut']], $f['finitions']),
            ];
        }

        return $fiches;
    }

    public static function sansDemo(string $nom): string
    {
        return trim((string) preg_replace('/\s*\(démo\)/u', '', $nom));
    }

    /** Pages de formulaire : jamais en cache, ni indexées une fois envoyées. */
    private function page(string $vue, array $parametres): Response
    {
        $statut = [] !== ($parametres['erreurs'] ?? []) ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
        $reponse = $this->render($vue, $parametres, new Response(null, $statut));
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
