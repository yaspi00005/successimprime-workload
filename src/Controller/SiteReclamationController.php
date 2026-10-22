<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\DemandeDevis;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\ReclamationsClients;
use App\Service\EspaceClient\SuiviSansCompte;
use App\Service\Site\DemandesSite;
use App\Service\Site\EngagementsSite;
use App\Service\Site\FormulaireDemande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réclamations depuis le site, sans compte :
 *  - « Un engagement non tenu ? » (page Nos engagements, maquette EC02) :
 *    commande retrouvée par son numéro et le téléphone → réclamation au
 *    nom de son client (elle monte au local comme celles de l'espace
 *    client) ; sinon le message part comme une demande de contact
 *    « Réclamation », qui ouvre une conversation dans la messagerie ;
 *  - « Suivre une réclamation » (EC03) : numéro + téléphone, même limite
 *    et même réponse que le suivi de commande sans compte, sans montant.
 */
final class SiteReclamationController extends AbstractController
{
    /** Montants masqués dans la réponse montrée sans compte (« 21 000 F », « 5 000 FCFA »). */
    private const MONTANT = '/\d(?:[\d \x{00A0}\x{202F}.,]*\d)?[ \x{00A0}\x{202F}]?(?:f\s?cfa|xof|francs?|frs?|f)(?![\p{L}\p{N}])/iu';

    public function __construct(
        private readonly EngagementsSite $engagements,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire(service: 'limiter.site_demande_ip')] private readonly RateLimiterFactoryInterface $limite,
    ) {
    }

    #[Route('/nos-engagements/signaler', name: 'app_site_engagements_signaler', methods: ['POST'])]
    public function signaler(Request $request, SuiviSansCompte $suivi, ReclamationsClients $reclamations, DemandesSite $demandes): Response
    {
        $d = $request->request->all();
        $texte = static fn (string $cle, int $max): string => mb_substr(trim(\is_scalar($d[$cle] ?? null) ? (string) $d[$cle] : ''), 0, $max);
        $titres = array_column($this->engagements->promesses(), 'titre');
        $saisie = [
            'nom' => $texte('nom', 120), 'telephone' => $texte('telephone', 20), 'numero_commande' => $texte('numero_commande', 40),
            'engagement' => \in_array($texte('engagement', 80), $titres, true) ? $texte('engagement', 80) : '', 'message' => $texte('message', DemandeDevis::MESSAGE_MAX),
        ];
        $maintenant = time();
        $vue = fn (array $erreurs): Response => $this->page('site/engagement_signale.html.twig', [
            'saisie' => $saisie, 'erreurs' => $erreurs, 'titres' => $titres, 'resultat' => null,
            'jeton' => FormulaireDemande::jetonDelai($maintenant - FormulaireDemande::DELAI_MIN_SECONDES, $this->secret),
        ], 422);

        // Le formulaire vit sur une page gardée en cache (pas de jeton de session) : l'origine doit être ce site.
        $origine = (string) ($request->headers->get('Origin') ?? '');
        if ('' !== $origine && 'null' !== $origine && parse_url($origine, \PHP_URL_HOST) !== $request->getHost()) {
            return $vue(['_' => 'La page a expiré : vérifiez votre message puis envoyez-le à nouveau.']);
        }
        if (FormulaireDemande::estPiege($d)) {
            return $this->page('site/engagement_signale.html.twig', ['resultat' => ['type' => 'demande', 'numero' => null], 'saisie' => $saisie, 'titres' => $titres, 'erreurs' => [], 'jeton' => '']);
        }
        if (null !== $erreur = FormulaireDemande::verifierDelai((string) $request->request->get('_t'), $maintenant, $this->secret)) {
            return $vue(['_' => $erreur]);
        }
        $erreurs = [];
        if (mb_strlen($saisie['nom']) < 2) {
            $erreurs['nom'] = 'Indiquez votre nom.';
        }
        $telephone = CompteClient::normaliserTelephone($saisie['telephone']);
        if (null === $telephone) {
            $erreurs['telephone'] = 'Indiquez un numéro malien à 8 chiffres.';
        }
        if (false === FormulaireDemande::numeroCommande($saisie['numero_commande'])) {
            $erreurs['numero_commande'] = 'Numéro tel qu\'il est écrit sur votre bon.';
        }
        if (mb_strlen($saisie['message']) < 5) {
            $erreurs['message'] = 'Dites-nous ce qui s\'est passé.';
        }
        if ([] !== $erreurs) {
            return $vue($erreurs);
        }
        if (!$this->limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $vue(['_' => 'Trop d\'envois depuis cette connexion. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.']);
        }

        $le = new \DateTimeImmutable();
        $numero = FormulaireDemande::numeroCommande($saisie['numero_commande']);
        $commande = null !== $numero ? $suivi->chercher($numero, (string) $telephone, (string) $request->getClientIp())['commande'] : null;
        if (null !== $commande) {
            try {
                $r = $reclamations->creerDepuisSite($commande, $saisie['engagement'], $saisie['message'], $saisie['nom'], $le);

                return $this->page('site/engagement_signale.html.twig', ['resultat' => ['type' => 'reclamation', 'numero' => $r->getNumero(), 'commande' => $commande->getNumero(), 'delai' => $this->engagements->delaiReclamation()], 'saisie' => $saisie, 'titres' => $titres, 'erreurs' => [], 'jeton' => '']);
            } catch (\DomainException $e) {
                return $vue(['_' => $e->getMessage()]);
            }
        }

        // Commande non retrouvée (ou pas de numéro) : le message part comme une demande de contact « Réclamation ».
        $demande = $demandes->enregistrer(DemandeDevis::NATURE_CONTACT, [
            'nom' => $saisie['nom'], 'telephone' => $telephone, 'objet' => 'reclamation', 'numeroCommande' => $numero ?: null,
            'message' => ('' !== $saisie['engagement'] ? '['.$saisie['engagement'].'] ' : '').$saisie['message'],
        ], null, $le);

        return $this->page('site/engagement_signale.html.twig', ['resultat' => ['type' => 'demande', 'numero' => $demande->getNumero(), 'delai' => $this->engagements->delaiReclamation()], 'saisie' => $saisie, 'titres' => $titres, 'erreurs' => [], 'jeton' => '']);
    }

    #[Route('/suivre-ma-reclamation', name: 'app_site_reclamation_suivi', methods: ['GET', 'POST'])]
    public function suivre(Request $request, CompteClientRepository $comptes, ReclamationsClients $reclamations, #[Autowire(service: 'limiter.espace_suivi_ip')] RateLimiterFactoryInterface $limiteSuivi): Response
    {
        $numero = mb_substr(trim((string) $request->request->get('numero', $request->query->get('numero', ''))), 0, 30);
        $telephone = mb_substr(trim((string) $request->request->get('telephone', '')), 0, 20);
        $reclamation = null;
        $erreur = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('site-reclamation', (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } elseif ('' === $numero || '' === $telephone) {
                $erreur = 'Indiquez le numéro de la réclamation et votre téléphone.';
            } elseif (!$limiteSuivi->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $erreur = SuiviSansCompte::TROP_DE_RECHERCHES;
            } else {
                $normalise = CompteClient::normaliserTelephone($telephone);
                $reclamation = $reclamations->duTelephone(null !== $normalise ? $comptes->clientDuTelephone($normalise) : null, $numero);
                $erreur = null === $reclamation ? 'Aucune réclamation ne correspond à ce numéro et à ce téléphone.' : null;
            }
        }

        $delai = $this->engagements->delaiReclamation();
        $duree = null;
        if (null !== $reclamation && null !== $reclamation->getReponduLe()) {
            $duree = intdiv($reclamation->getReponduLe()->getTimestamp() - $reclamation->getCreeLe()->getTimestamp(), 60);
        }

        return $this->page('site/reclamation_suivi.html.twig', [
            'reclamation' => $reclamation,
            'reponse' => null !== $reclamation && null !== $reclamation->getReponse() ? self::sansMontant($reclamation->getReponse()) : null,
            'duree' => $duree,
            'delai' => $delai,
            'numero' => $numero,
            'telephone' => $telephone,
            'erreur' => $erreur,
        ], null !== $erreur ? 422 : 200);
    }

    /** « Avoir de 5 000 F » → « Avoir de … » : jamais de montant sans compte. */
    public static function sansMontant(string $texte): string
    {
        return (string) preg_replace(self::MONTANT, '…', $texte);
    }

    /** Jamais en cache, jamais indexée. */
    private function page(string $vue, array $parametres, int $statut = 200): Response
    {
        $reponse = $this->render($vue, $parametres, new Response(null, $statut));
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
