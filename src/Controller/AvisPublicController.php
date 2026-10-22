<?php

namespace App\Controller;

use App\Entity\InvitationSondage;
use App\Entity\ReponseSondage;
use App\Entity\Sondage;
use App\Service\Sondages\Sondages;
use App\Service\Sondages\SondageCommande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Réponse sans compte (docs/sondages.md) : le sondage d'une commande par
 * le lien reçu à la livraison (SMS ou WhatsApp), et un sondage libre par
 * son lien ou son QR code. Rien d'autre du client n'est montré : ni
 * montant, ni nom. Envois limités par adresse IP, jeton CSRF.
 */
#[Route('/avis', name: 'app_avis_')]
final class AvisPublicController extends AbstractController
{
    private const CSRF = 'avis-public';

    public function __construct(
        private readonly Sondages $sondages,
        #[Autowire(service: 'limiter.sondage_public_ip')] private readonly RateLimiterFactoryInterface $limite,
    ) {
    }

    #[Route('/c/{jeton}', name: 'commande', requirements: ['jeton' => '[0-9a-f]{24}'], methods: ['GET', 'POST'])]
    public function commande(string $jeton, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $invitation = $this->sondages->invitation($jeton);
        $commande = $invitation?->getCommande();
        if (!$invitation instanceof InvitationSondage || null === $commande || !$invitation->estValable($maintenant) || !Sondages::estLivree($commande)) {
            return $this->page('avis/indisponible.html.twig', ['raison' => 'Ce lien n\'est plus valable. Merci de votre intérêt !'], 404);
        }
        if (null !== $this->sondages->reponseCommande($commande)) {
            return $this->page('avis/merci.html.twig', ['deja' => true]);
        }
        $saisie = [];
        $erreur = null;
        if ($request->isMethod('POST')) {
            $saisie = $request->request->all();
            $erreur = $this->controler($request);
            if (null === $erreur) {
                try {
                    $this->sondages->repondreCommande($commande, null, $saisie, ReponseSondage::CANAL_LIEN, $maintenant);

                    return $this->page('avis/merci.html.twig', ['deja' => false]);
                } catch (\DomainException $e) {
                    $erreur = $e->getMessage();
                }
            }
        }

        return $this->page('avis/commande.html.twig', [
            'commande' => $commande,
            'articles' => Sondages::articles($commande),
            'questions' => SondageCommande::questions(),
            'saisie' => $saisie,
            'erreur' => $erreur,
            'jeton' => self::CSRF,
        ], null !== $erreur ? 422 : 200);
    }

    #[Route('/s/{jeton}', name: 'sondage', requirements: ['jeton' => '[0-9a-f]{24}'], methods: ['GET', 'POST'])]
    public function sondage(string $jeton, Request $request): Response
    {
        $sondage = $this->sondages->sondageLibre($jeton);
        if (!$sondage instanceof Sondage) {
            return $this->page('avis/indisponible.html.twig', ['raison' => 'Ce sondage n\'existe pas ou n\'est pas encore ouvert.'], 404);
        }
        if (!$sondage->estOuvert()) {
            return $this->page('avis/indisponible.html.twig', ['raison' => 'Ce sondage est terminé. Merci de votre intérêt !']);
        }
        $saisie = [];
        $erreur = null;
        if ($request->isMethod('POST')) {
            $saisie = $request->request->all();
            $erreur = $this->controler($request);
            if (null === $erreur) {
                try {
                    $canal = 'qr' === $request->query->get('via') ? ReponseSondage::CANAL_QR : ReponseSondage::CANAL_LIEN;
                    $this->sondages->repondreSondage($sondage, null, null, $saisie, $canal, $request->request->getString('nom'), $request->request->getString('telephone'), new \DateTimeImmutable());

                    return $this->page('avis/merci.html.twig', ['deja' => false]);
                } catch (\DomainException $e) {
                    $erreur = $e->getMessage();
                }
            }
        }

        return $this->page('avis/sondage.html.twig', [
            'sondage' => $sondage,
            'saisie' => $saisie,
            'erreur' => $erreur,
            'jeton' => self::CSRF,
            'via' => 'qr' === $request->query->get('via') ? 'qr' : null,
        ], null !== $erreur ? 422 : 200);
    }

    /** Jeton et limite d'envois : le message d'erreur, ou null. */
    private function controler(Request $request): ?string
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            return 'La page a expiré. Réessayez.';
        }
        // Rempli seulement par les robots (champ caché aux personnes).
        if ('' !== $request->request->getString('site')) {
            return 'Envoi refusé.';
        }
        if (!$this->limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return 'Trop d\'envois depuis cette connexion. Réessayez plus tard.';
        }

        return null;
    }

    /** @param array<string, mixed> $parametres */
    private function page(string $gabarit, array $parametres, int $statut = 200): Response
    {
        $reponse = $this->render($gabarit, $parametres, new Response(null, $statut));
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $reponse;
    }
}
