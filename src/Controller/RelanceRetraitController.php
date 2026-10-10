<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\RelanceRetrait;
use App\Entity\User;
use App\Service\RelanceRetraitService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « À retirer » : commandes prêtes que le client n'est pas encore venu
 * chercher, avec la relance par WhatsApp, SMS ou appel.
 */
#[Route('/livraisons/a-retirer', name: 'app_livraisons_a_retirer')]
#[IsGranted('ROLE_LIVRAISON')]
final class RelanceRetraitController extends AbstractController
{
    public function __construct(private readonly RelanceRetraitService $relances)
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $pretes = $this->relances->commandesPretes(new \DateTimeImmutable());

        return $this->render('livraisons/a_retirer.html.twig', [
            'pretes' => $pretes,
            'aRelancer' => \count(array_filter($pretes, static fn (array $p) => $p['jours'] >= 2 && ($p['derniereRelance'] === null || $p['derniereRelance']->getEnvoyeeLe() < new \DateTimeImmutable('-2 days')))),
            'voirMontants' => $this->isGranted('ROLE_PAIEMENT_VOIR'),
        ]);
    }

    /**
     * Note une relance faite à la main (WhatsApp ouvert, appel passé),
     * ou envoie le SMS.
     */
    #[Route('/{id}/relancer', name: '_relancer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function relancer(Commandes $commande, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('relance-retrait', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $canal = (string) $request->request->get('canal');
        $par = $this->getUser() instanceof User ? $this->getUser() : null;

        if ($canal === RelanceRetrait::CANAL_SMS) {
            $message = trim((string) $request->request->get('message')) ?: RelanceRetraitService::message($commande, new \DateTimeImmutable());
            $resultat = $this->relances->envoyerSms($commande, mb_substr($message, 0, 480), $par);
        } else {
            $this->relances->noter($commande, \array_key_exists($canal, RelanceRetrait::CANAUX_LABELS) ? $canal : RelanceRetrait::CANAL_WHATSAPP, $par);
            $resultat = ['succes' => true, 'erreur' => null];
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($resultat, $resultat['succes'] ? 200 : 422);
        }

        $this->addFlash($resultat['succes'] ? 'success' : 'error', $resultat['succes']
            ? sprintf('Relance notée pour la commande %s.', $commande->getNumero())
            : 'SMS non envoyé : '.$resultat['erreur']);

        return $this->redirectToRoute('app_livraisons_a_retirer');
    }
}
