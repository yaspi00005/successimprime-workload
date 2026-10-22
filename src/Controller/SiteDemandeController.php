<?php

namespace App\Controller;

use App\Entity\DemandeDevis;
use App\Entity\DemandeDevisLigne;
use App\Entity\ProduitConfiguration;
use App\Entity\Commandes;
use App\Service\Catalogue\NomCommercial;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\SuiviSansCompte;
use App\Service\Messagerie\ReglesReponse;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\PiecesParMetreCarre;
use App\Service\Site\ContactSite;
use App\Service\Site\Calculateur;
use App\Service\Site\DemandesSite;
use App\Service\Site\FormulaireDemande;
use App\Service\Site\JetonsSite;
use App\Service\Site\PanierDevis;
use App\Service\Site\RappelsSite;
use App\Service\Site\SuiviSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contact et demande de devis sans compte (maquette 11). La demande est
 * enregistrée, monte au local et y devient un prospect ou la fiche du
 * client du même numéro, avec un devis brouillon que l'équipe vérifie.
 * La page du devis n'écrit aucun prix : le prix indicatif de chaque ligne
 * du panier vient de l'estimation (tarif public, « à confirmer par
 * l'imprimerie ») ; jamais de montant par SMS.
 *
 * Contre les robots : jeton CSRF, champ piège, temps de saisie minimum,
 * limite par adresse IP et contrôle de chaque champ côté serveur.
 *
 * Le produit et les finitions passent par leur jeton (JetonsSite), dans
 * l'adresse préremplie par le calculateur comme dans le formulaire ; une
 * ancienne adresse ?produit=<identifiant> est redirigée (301).
 */
final class SiteDemandeController extends AbstractController
{
    /** Confirmation montrée une fois (aussi après « Valider mon modèle » de l'Atelier 3D). */
    public const SESSION = 'site_demande_envoyee';

    public function __construct(
        private readonly NouvelleCommande $catalogue,
        private readonly DemandesSite $demandes,
        private readonly ContactSite $contact,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire(service: 'limiter.site_demande_ip')] private readonly RateLimiterFactoryInterface $limite,
        private readonly PiecesParMetreCarre $piecesM2,
        private readonly JetonsSite $jetons,
        private readonly RappelsSite $rappels,
        private readonly SuiviSansCompte $suivi,
        private readonly EspaceClient $espace,
        private readonly ReglesReponse $regles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/contact', name: 'app_site_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request): Response
    {
        $objet = (string) $request->query->get('objet', '');
        $saisie = ['nom' => '', 'telephone' => '', 'objet' => isset(DemandeDevis::OBJETS[$objet]) ? $objet : 'devis', 'numero_commande' => '', 'message' => ''];
        $traitement = $this->traiter(DemandeDevis::NATURE_CONTACT, $request, [], $saisie);
        if ($traitement instanceof Response) {
            return $traitement;
        }

        return $this->pageContact($traitement, null);
    }

    /** Demande de rappel de l'écran « atelier fermé » (maquette EC06). */
    #[Route('/contact/rappel', name: 'app_site_contact_rappel', methods: ['POST'])]
    public function rappel(Request $request): Response
    {
        if (!$this->rappels->actif()) {
            return $this->redirectToRoute('app_site_contact', [], Response::HTTP_SEE_OTHER);
        }
        $traitement = $this->traiter(DemandeDevis::NATURE_RAPPEL, $request, [], ['nom' => '', 'telephone' => '', 'creneau' => '', 'message' => '']);
        if ($traitement instanceof Response) {
            return $traitement;
        }

        return $this->pageContact(['saisie' => ['objet' => 'devis', 'numero_commande' => ''] + $traitement['saisie'], 'erreurs' => [], 'jeton' => $traitement['jeton']], $traitement);
    }

    /**
     * Page Contact (maquette EC04) ; atelier fermé, l'écran de rappel
     * (EC06) prend le haut de la page s'il est activé.
     *
     * @param array{saisie: array<string, mixed>, erreurs: array<string, string>, jeton: string}      $formulaire
     * @param array{saisie: array<string, mixed>, erreurs: array<string, string>, jeton: string}|null $rappel     envoi de rappel à corriger
     */
    private function pageContact(array $formulaire, ?array $rappel): Response
    {
        $maintenant = new \DateTimeImmutable();
        $horaires = $this->contact->horaires($maintenant);
        $creneaux = !$horaires['ouvert'] || null !== $rappel ? $this->rappels->creneaux($maintenant) : null;

        return $this->page('site/contact.html.twig', $formulaire + [
            'objets' => DemandeDevis::OBJETS_COURTS,
            'horaires' => $horaires,
            'lienPlan' => $this->contact->lienPlan(),
            'positionPlan' => $this->contact->position(),
            'delaiReponse' => $this->contact->delaiReponse(),
            'repere' => $this->contact->repereAdresse(),
            'tailleMax' => DemandesSite::tailleMaxFichier(),
            'extensions' => FichiersClient::extensions(),
            'creneaux' => $creneaux,
            'rappel' => $rappel ?? ['saisie' => ['nom' => '', 'telephone' => '', 'creneau' => '', 'message' => ''], 'erreurs' => [], 'jeton' => $formulaire['jeton']],
        ]);
    }

    /**
     * Demande de devis : un panier de 1 à PanierDevis::LIGNES_MAX produits
     * (docs/site-vitrine.md), chacun avec sa quantité, ses finitions et son
     * fichier. Sans script, une ligne se remplit et s'envoie comme avant ;
     * les boutons « Ajouter un produit » et « Retirer » renvoient la page.
     */
    #[Route('/demander-un-devis', name: 'app_site_devis', methods: ['GET', 'POST'])]
    public function devis(Request $request): Response
    {
        $requete = $request->query->all();
        if ($request->isMethod('GET') && JetonsSite::contientIdentifiants($requete)) {
            return $this->redirectToRoute('app_site_devis', $this->jetons->encoderParametres($requete), Response::HTTP_MOVED_PERMANENTLY);
        }
        $produits = $this->catalogue->produits();
        $prerempli = FormulaireDemande::preremplir($this->jetons->decoderParametres($requete), $produits);
        $saisie = ['nom' => '', 'telephone' => '', 'email' => '', 'message' => '', 'lignes' => [0 => $prerempli + self::ligneVide()]];
        $traitement = $this->traiterPanier($request, $produits, $saisie);
        if ($traitement instanceof Response) {
            return $traitement;
        }

        // Ce que la page montre : jetons à la place des identifiants.
        $traitement['saisie']['lignes'] = array_map(fn (array $l): array => $this->jetons->encoderParametres($l), $traitement['saisie']['lignes']);

        return $this->page('site/devis.html.twig', $traitement + [
            'groupes' => array_map(fn (array $g): array => ['produits' => array_map(fn (array $p): array => ['id' => $this->jetons->encoder(JetonsSite::CONFIGURATION, $p['id'])] + $p, $g['produits'])] + $g, self::groupes($produits)),
            'fiches' => $this->fichesPubliques(self::fiches($produits)),
            'calculateur' => $request->isMethod('GET') && '' !== $prerempli['produit'],
            'tailleMax' => DemandesSite::tailleMaxFichier(),
            'tailleMaxTotale' => PanierDevis::tailleMaxTotale(),
            'fichiersMax' => PanierDevis::fichiersMax(),
            'lignesMax' => PanierDevis::LIGNES_MAX,
            'extensions' => FichiersClient::extensions(),
            'horaires' => $this->contact->horaires(new \DateTimeImmutable()),
        ]);
    }

    /**
     * Prix indicatif d'une ligne du panier pour le script de la page : le
     * prix que le devis brouillon aura (tarif public, pièces au m²
     * comprises), jamais le prix B2B. Mêmes paramètres qu'une ligne, le
     * produit et les finitions par leur jeton ; un produit inconnu est une
     * erreur 422, une saisie refusée revient avec son message.
     */
    #[Route('/demander-un-devis/estimation', name: 'app_site_devis_estimation', methods: ['GET'])]
    public function estimation(Request $request): JsonResponse
    {
        $donnees = $this->jetons->decoderParametres($request->query->all());
        $produit = null;
        foreach (isset($donnees['produit']) ? $this->catalogue->produits() : [] as $p) {
            if ($p->getId() === $donnees['produit']) {
                $produit = $p;
                break;
            }
        }
        if (null === $produit) {
            return $this->estimationReponse(['total' => null, 'texte' => null, 'erreur' => 'Choisissez un produit.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $espacement = $this->piecesM2->espacement();
        ['valeurs' => $v, 'erreurs' => $erreurs] = FormulaireDemande::validerLigne(['produit' => (string) $produit->getId()] + $donnees, [$produit], $espacement);
        if ([] !== $erreurs) {
            return $this->estimationReponse(['total' => null, 'texte' => null, 'erreur' => (string) reset($erreurs)]);
        }
        $prix = PanierDevis::prixIndicatif($produit, $v['quantite'], $v['largeur'], $v['hauteur'], $v['finitions'], $espacement);

        return $this->estimationReponse([
            'total' => $prix,
            'texte' => null !== $prix ? Calculateur::fcfa($prix) : null,
            'erreur' => null === $prix ? 'Prix donné par l\'imprimerie avec le devis.' : null,
        ]);
    }

    /** @param array<string, mixed> $donnees */
    private function estimationReponse(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->setPrivate();
        $reponse->setMaxAge(60);
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    /** @return array<string, mixed> */
    private static function ligneVide(): array
    {
        return ['produit' => '', 'largeur' => '', 'hauteur' => '', 'quantite' => '', 'unite_quantite' => 'piece', 'quantite_m2' => '', 'finitions' => [], 'description' => ''];
    }

    /**
     * Contrôle et enregistre un panier de devis : mêmes protections que
     * les autres formulaires (CSRF, champ piège, temps de saisie, limite par
     * adresse IP), plus le nombre et le poids total des fichiers.
     *
     * @param list<ProduitConfiguration> $produits
     * @param array<string, mixed>       $saisie   valeurs affichées par défaut
     *
     * @return Response|array{saisie: array<string, mixed>, erreurs: array<string, string>, jeton: string, info: ?string}
     */
    private function traiterPanier(Request $request, array $produits, array $saisie): Response|array
    {
        $maintenant = time();
        if (!$request->isMethod('POST')) {
            return ['saisie' => $saisie, 'erreurs' => [], 'jeton' => FormulaireDemande::jetonDelai($maintenant, $this->secret), 'info' => null];
        }

        $brut = $request->request->all();
        $jeton = (string) $request->request->get('_t', '');
        $jetonRenvoi = null === FormulaireDemande::verifierDelai($jeton, $maintenant + FormulaireDemande::DELAI_MIN_SECONDES, $this->secret)
            ? $jeton : FormulaireDemande::jetonDelai($maintenant, $this->secret);
        $vue = static fn (array $erreurs, array $saisie, ?string $info = null): array => ['saisie' => $saisie, 'erreurs' => $erreurs, 'jeton' => $jetonRenvoi, 'info' => $info];

        // Envoi plus gros que ce que le serveur accepte : PHP a tout jeté.
        if ([] === $brut && (int) $request->server->get('CONTENT_LENGTH') > 0) {
            return $vue(['_' => \sprintf('Vos fichiers sont trop lourds pour partir ensemble (%s Mo au plus en tout, %s Mo par fichier) : remplissez de nouveau le formulaire, puis envoyez les plus lourds par WhatsApp ou collez un lien dans le message.', PanierDevis::mo(PanierDevis::tailleMaxTotale()), PanierDevis::mo(DemandesSite::tailleMaxFichier()))], $saisie);
        }

        // Chaque ligne : produit et finitions en jetons (les identifiants restent lus : un envoi n'est pas une adresse).
        $donnees = $brut;
        $donnees['lignes'] = [];
        foreach (FormulaireDemande::lignes($brut) as $cle => $ligne) {
            $decodee = $this->jetons->decoderParametres($ligne, JetonsSite::PARAMETRES_PRODUIT, true);
            if ('' !== trim(\is_scalar($ligne['produit'] ?? null) ? (string) $ligne['produit'] : '') && !isset($decodee['produit'])) {
                // Jeton refusé : « Choisissez un produit de la liste », pas « Autre projet ».
                $decodee['produit'] = '0';
            }
            $donnees['lignes'][$cle] = $decodee;
        }
        $saisie = self::saisiePanier($donnees) + $saisie;

        // Sans script : ajouter ou retirer une ligne renvoie la page, sans rien contrôler ni enregistrer.
        if ($request->request->has('_ajouter')) {
            if (\count($saisie['lignes']) < PanierDevis::LIGNES_MAX) {
                $saisie['lignes'][max(array_keys($saisie['lignes'])) + 1] = self::ligneVide();
            }

            return $vue([], $saisie, 'Produit ajouté en bas de la liste. Les fichiers déjà choisis sont à choisir de nouveau.');
        }
        if ($request->request->has('_retirer')) {
            $cle = $request->request->getInt('_retirer', -1);
            if (\count($saisie['lignes']) > 1 && isset($saisie['lignes'][$cle])) {
                unset($saisie['lignes'][$cle]);
            }

            return $vue([], $saisie, 'Produit retiré. Les fichiers déjà choisis sont à choisir de nouveau.');
        }

        if (!$this->isCsrfTokenValid('site-demande-'.DemandeDevis::NATURE_DEVIS, (string) $request->request->get('_token'))) {
            return $vue(['_' => 'La page a expiré : vérifiez votre demande puis envoyez-la à nouveau.'], $saisie);
        }
        if (FormulaireDemande::estPiege($brut)) {
            // Un robot : rien n'est enregistré, et il ne le sait pas.
            $request->getSession()->set(self::SESSION, ['nature' => DemandeDevis::NATURE_DEVIS, 'numero' => null, 'accuse' => false]);

            return $this->redirectToRoute('app_site_devis_envoyee', [], Response::HTTP_SEE_OTHER);
        }
        if (null !== $erreur = FormulaireDemande::verifierDelai($jeton, $maintenant, $this->secret)) {
            return $vue(['_' => $erreur], $saisie);
        }

        // Fichiers : un par ligne (lignes[i][fichier]), ou « fichier » de l'ancien formulaire.
        $fichiers = [];
        $envoyes = \is_array($request->files->all()['lignes'] ?? null) ? $request->files->all()['lignes'] : [];
        foreach ($envoyes as $cle => $champs) {
            $f = \is_array($champs) ? ($champs['fichier'] ?? null) : null;
            if ($f instanceof UploadedFile && isset($donnees['lignes'][$cle])) {
                $fichiers[$cle] = $f;
            }
        }
        if ([] === $fichiers && ($ancien = $request->files->get('fichier')) instanceof UploadedFile) {
            $fichiers[array_key_first($donnees['lignes']) ?? 0] = $ancien;
        }

        $espacement = $this->piecesM2->espacement();
        ['valeurs' => $valeurs, 'erreurs' => $erreurs] = FormulaireDemande::validerPanier($donnees, $produits, $espacement, array_keys($fichiers));
        foreach ($fichiers as $cle => $f) {
            try {
                DemandesSite::verifierFichier($f);
            } catch (\DomainException $e) {
                $erreurs['lignes.'.$cle.'.fichier'] = $e->getMessage();
            }
        }
        if (!self::aErreurFichier($erreurs) && null !== $ensemble = PanierDevis::verifierEnsemble(array_values($fichiers))) {
            $erreurs['_'] = $ensemble;
        }
        if ([] !== $erreurs) {
            foreach (array_keys($fichiers) as $cle) {
                $erreurs['lignes.'.$cle.'.fichier'] ??= 'Choisissez de nouveau ce fichier : il n\'est pas gardé tant que la demande n\'est pas envoyée.';
            }

            return $vue($erreurs, $saisie);
        }

        if (!$this->limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $vue(['_' => 'Trop d\'envois depuis cette connexion. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.'], $saisie);
        }

        // Prix indicatif de chaque ligne (celui du devis brouillon), pour la confirmation et l'équipe.
        $lignes = [];
        $resumes = [];
        foreach ($valeurs['lignes'] as $l) {
            $prix = null !== $l['produit'] ? PanierDevis::prixIndicatif($l['produit'], $l['quantite'], $l['largeur'], $l['hauteur'], $l['finitions'], $espacement) : null;
            $fichier = $fichiers[$l['cle']] ?? null;
            $lignes[] = ['valeurs' => $l, 'fichier' => $fichier, 'prix' => $prix];
            $resumes[] = PanierDevis::resume($l, self::nomsFinitions($l), $prix, $fichier ? FichiersClient::nomPropre((string) $fichier->getClientOriginalName()) : null);
        }

        try {
            if (\count($lignes) <= 1) {
                // Un seul produit (ou un projet décrit dans le message) : la demande comme avant le panier.
                $l = $lignes[0]['valeurs'] ?? null;
                $message = implode("\n\n", array_filter([$l['description'] ?? null, $valeurs['message']]));
                $simple = ['objet' => null, 'numeroCommande' => null, 'rappelLe' => null, 'message' => '' === $message ? null : $message] + $valeurs
                    + ($l ?? ['produit' => null, 'largeur' => null, 'hauteur' => null, 'quantite' => null, 'quantiteM2' => null, 'finitions' => []]);
                $demande = $this->demandes->enregistrer(DemandeDevis::NATURE_DEVIS, $simple, $lignes[0]['fichier'] ?? (reset($fichiers) ?: null), new \DateTimeImmutable());
            } else {
                $demande = $this->demandes->enregistrerPanier($valeurs, $lignes, new \DateTimeImmutable());
            }
        } catch (\DomainException $e) {
            return $vue(['_' => $e->getMessage()], $saisie);
        }

        $total = PanierDevis::total(array_column($lignes, 'prix'));
        $request->getSession()->set(self::SESSION, [
            'nature' => DemandeDevis::NATURE_DEVIS,
            'numero' => $demande->getNumero(),
            'accuse' => null !== $demande->getAccuseEnvoyeLe(),
            'resume' => self::sansDemo($demande->getResume()),
            'fichier' => $demande->nombreFichiers() > 0,
            'prenom' => self::prenom($demande->getNom()),
            'message' => null !== $demande->getMessage() ? mb_strimwidth($demande->getMessage(), 0, 240, '…') : null,
            'lignes' => $resumes,
            'total' => null !== $total['total'] ? Calculateur::fcfa($total['total']) : null,
            'totalComplet' => $total['complet'],
            'le' => $demande->getCreeLe()->getTimestamp(),
        ]);

        return $this->redirectToRoute('app_site_devis_envoyee', [], Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, string> $erreurs */
    private static function aErreurFichier(array $erreurs): bool
    {
        foreach (array_keys($erreurs) as $cle) {
            if (str_ends_with($cle, '.fichier')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Noms des finitions choisies sur une ligne contrôlée.
     *
     * @param array{produit: ?ProduitConfiguration, finitions: list<int>} $ligne
     *
     * @return list<string>
     */
    private static function nomsFinitions(array $ligne): array
    {
        $noms = [];
        foreach ($ligne['produit']?->getConfigurationFinitions() ?? [] as $cf) {
            if (\in_array($cf->getId(), $ligne['finitions'], true)) {
                $noms[] = self::sansDemo((string) $cf->getFinition()?->getNom());
            }
        }

        return array_values(array_filter($noms));
    }

    /**
     * Ce que le visiteur a tapé dans le panier, pour le lui remontrer.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private static function saisiePanier(array $donnees): array
    {
        $saisie = self::saisie(DemandeDevis::NATURE_DEVIS, $donnees);
        $saisie = array_intersect_key($saisie, array_flip(['nom', 'telephone', 'message', 'email']));
        $saisie['lignes'] = [];
        foreach ($donnees['lignes'] ?? [] as $cle => $l) {
            $ligne = self::saisie(DemandeDevis::NATURE_DEVIS, \is_array($l) ? $l : []);
            $saisie['lignes'][$cle] = array_intersect_key($ligne, self::ligneVide())
                + ['description' => mb_substr(\is_scalar($l['description'] ?? null) ? (string) $l['description'] : '', 0, DemandeDevisLigne::DESCRIPTION_MAX)];
        }
        if ([] === $saisie['lignes']) {
            $saisie['lignes'][0] = self::ligneVide();
        }

        return $saisie;
    }

    /** Confirmation (une fois) : numéro de la demande, jamais de prix. */
    #[Route('/demander-un-devis/envoyee', name: 'app_site_devis_envoyee', methods: ['GET'])]
    #[Route('/contact/envoye', name: 'app_site_contact_envoye', methods: ['GET'])]
    public function envoyee(Request $request): Response
    {
        $envoi = $request->hasSession() ? $request->getSession()->remove(self::SESSION) : null;
        $devis = 'app_site_devis_envoyee' === $request->attributes->get('_route');
        $natures = $devis ? [DemandeDevis::NATURE_DEVIS] : [DemandeDevis::NATURE_CONTACT, DemandeDevis::NATURE_RAPPEL];
        if (!\is_array($envoi) || !\in_array($envoi['nature'] ?? null, $natures, true)) {
            return $this->redirectToRoute($devis ? 'app_site_devis' : 'app_site_contact');
        }
        $maintenant = new \DateTimeImmutable();

        if ($devis) {
            return $this->page('site/demande_envoyee.html.twig', [
                'envoi' => $envoi,
                'devis' => $devis,
                'horaires' => $this->contact->horaires($maintenant),
            ]);
        }

        // Commande citée et reconnue (numéro + téléphone du visiteur) : son état tout de suite (EC05), sans montant.
        $carte = null;
        if (null !== ($envoi['commande'] ?? null)) {
            $commande = $this->em->find(Commandes::class, (int) $envoi['commande']);
            $carte = $commande instanceof Commandes ? $this->espace->carte($commande) : null;
        }
        $recuLe = isset($envoi['le']) ? new \DateTimeImmutable('@'.$envoi['le']) : $maintenant;
        $recuLe = $recuLe->setTimezone($maintenant->getTimezone());
        $heures = $this->regles->heures();

        return $this->page('site/contact_envoye.html.twig', [
            'envoi' => $envoi,
            'horaires' => $this->contact->horaires($maintenant),
            'recuLe' => $recuLe,
            'reponseAvant' => $heures->ajouter($recuLe, $this->regles->premiere()),
            'delaiReponse' => ReglesReponse::duree($this->regles->premiere()),
            'carte' => $carte,
            'pastille' => null !== $carte ? SuiviSite::pastille($carte, $maintenant) : null,
            'etapes' => null !== $carte ? SuiviSite::etapes($carte['etapes'], $maintenant) : [],
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

        // Produit et finitions arrivent en jetons (les identifiants restent lus : un envoi n'est pas une adresse).
        $donnees = $this->jetons->decoderParametres($request->request->all(), JetonsSite::PARAMETRES_PRODUIT, true);
        if ('' !== trim((string) (\is_scalar($request->request->all()['produit'] ?? null) ? $request->request->all()['produit'] : '')) && !isset($donnees['produit'])) {
            // Jeton refusé : « Choisissez un produit de la liste », pas « Autre projet ».
            $donnees['produit'] = '0';
        }
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

        $creneau = fn (string $v): ?\DateTimeImmutable => $this->rappels->creneauValide($v, new \DateTimeImmutable());
        ['valeurs' => $valeurs, 'erreurs' => $erreurs] = FormulaireDemande::valider($nature, $donnees, $produits, $this->piecesM2->espacement(), $creneau);
        // Fichier joint : devis et message (logo, photo, PDF), jamais pour un rappel.
        $fichier = DemandeDevis::NATURE_RAPPEL !== $nature ? $request->files->get('fichier') : null;
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

        // Commande citée : cherchée comme le suivi sans compte (numéro + téléphone, même limite) ; seul son identifiant est gardé.
        $commande = null;
        if (null !== ($valeurs['numeroCommande'] ?? null)) {
            $commande = $this->suivi->chercher((string) $valeurs['numeroCommande'], (string) $valeurs['telephone'], (string) $request->getClientIp())['commande'];
        }

        $request->getSession()->set(self::SESSION, [
            'nature' => $nature,
            'numero' => $demande->getNumero(),
            'accuse' => null !== $demande->getAccuseEnvoyeLe(),
            'resume' => $demande->estDevis() ? self::sansDemo($demande->getResume()) : $demande->getLibelleObjet(),
            'fichier' => null !== $demande->getFichier(),
            'prenom' => self::prenom($demande->getNom()),
            'message' => null !== $demande->getMessage() ? mb_strimwidth($demande->getMessage(), 0, 240, '…') : null,
            'numeroCommande' => $demande->getNumeroCommande(),
            'rappelLe' => $demande->getRappelLe()?->format('Y-m-d H:i'),
            'commande' => $commande?->getId(),
            'le' => $demande->getCreeLe()->getTimestamp(),
        ]);

        return $this->redirectToRoute(DemandeDevis::NATURE_DEVIS === $nature ? 'app_site_devis_envoyee' : 'app_site_contact_envoye', [], Response::HTTP_SEE_OTHER);
    }

    /** « Awa Diarra » → « Awa » (titre de la confirmation). */
    public static function prenom(string $nom): string
    {
        $mots = preg_split('/\s+/u', trim($nom), -1, \PREG_SPLIT_NO_EMPTY) ?: [''];

        return mb_substr($mots[0], 0, 40);
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
            return $saisie + ['objet' => $texte('objet', 20), 'numero_commande' => $texte('numero_commande', 40)];
        }
        if (DemandeDevis::NATURE_RAPPEL === $nature) {
            return $saisie + ['creneau' => $texte('creneau', 16)];
        }
        $finitions = \is_array($donnees['finitions'] ?? null) ? $donnees['finitions'] : [];

        return $saisie + [
            'email' => $texte('email', 180),
            'produit' => $texte('produit', 12),
            'largeur' => $texte('largeur', 12),
            'hauteur' => $texte('hauteur', 12),
            'quantite' => $texte('quantite', 12),
            // Quantité en pièces ou en m² (pièces au m²).
            'unite_quantite' => FormulaireDemande::UNITE_M2 === ($donnees['unite_quantite'] ?? null) ? FormulaireDemande::UNITE_M2 : 'piece',
            'quantite_m2' => $texte('quantite_m2', 12),
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
            // Nom commercial d'abord, désignation entre parenthèses (docs/noms-commerciaux.md).
            $groupes[$groupe][] = ['id' => (int) $p->getId(), 'nom' => self::sansDemo(NomCommercial::texteConfiguration($p))];
        }

        return array_map(static fn (string $nom, array $liste): array => ['nom' => $nom, 'produits' => $liste], array_keys($groupes), array_values($groupes));
    }

    /**
     * Ce que le script de la page doit savoir de chaque produit : taille
     * demandée, quantité minimum, quantité possible en m² (pièces au m²),
     * finitions. Aucun prix.
     *
     * @param list<ProduitConfiguration> $produits
     *
     * @return array<int, array{taille: bool, largeur: ?float, hauteur: ?float, min: int, m2: bool, finitions: list<array{id: int, nom: string, obligatoire: bool, parDefaut: bool}>}>
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
                'm2' => FormulaireDemande::piecesAuM2Possible($p),
                'finitions' => array_map(static fn (array $fi): array => ['id' => $fi['id'], 'nom' => self::sansDemo($fi['nom']), 'obligatoire' => $fi['obligatoire'], 'parDefaut' => $fi['parDefaut']], $f['finitions']),
            ];
        }

        return $fiches;
    }

    /**
     * Fiches pour la page : rangées par jeton du produit, finitions par leur jeton.
     *
     * @param array<int, array<string, mixed>> $fiches self::fiches()
     *
     * @return array<string, array<string, mixed>>
     */
    private function fichesPubliques(array $fiches): array
    {
        $publiques = [];
        foreach ($fiches as $id => $f) {
            $f['finitions'] = array_map(fn (array $fi): array => ['id' => $this->jetons->encoder(JetonsSite::FINITION, $fi['id'])] + $fi, $f['finitions']);
            $publiques[$this->jetons->encoder(JetonsSite::CONFIGURATION, $id)] = $f;
        }

        return $publiques;
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
