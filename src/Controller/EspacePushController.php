<?php

namespace App\Controller;

use App\Entity\AbonnementPush;
use App\Entity\AbonnementPushClient;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\Push\WebPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client : le téléphone du client accepte (ou refuse) les
 * notifications de son espace (BAT prêt, commande prête…).
 */
#[Route('/espace/push', name: 'app_espace_push_')]
final class EspacePushController extends AbstractController
{
    public const CSRF = 'espace-push';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompteClientRepository $comptes,
        private readonly WebPush $push,
    ) {
    }

    #[Route('/abonner', name: 'abonner', methods: ['POST'])]
    public function abonner(Request $request): JsonResponse
    {
        $compte = $this->compte($request);
        if (null === $compte) {
            return new JsonResponse(['ok' => false], 403);
        }
        $donnees = json_decode($request->getContent(), true);
        $adresse = (string) ($donnees['endpoint'] ?? '');
        $cle = (string) ($donnees['keys']['p256dh'] ?? '');
        $secret = (string) ($donnees['keys']['auth'] ?? '');
        if (!$this->push->estConfigure() || !PushController::adresseValable($adresse) || 65 !== \strlen(WebPush::b64d($cle)) || 16 !== \strlen(WebPush::b64d($secret))) {
            return new JsonResponse(['ok' => false], 400);
        }
        $appareil = $request->headers->get('User-Agent');
        $a = $this->em->getRepository(AbonnementPushClient::class)->findOneBy(['empreinte' => AbonnementPush::empreinte($adresse)]);
        if (null === $a) {
            $this->em->persist(new AbonnementPushClient($compte, $adresse, $cle, $secret, $appareil, new \DateTimeImmutable()));
        } else {
            $a->renouveler($compte, $cle, $secret, $appareil);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/desabonner', name: 'desabonner', methods: ['POST'])]
    public function desabonner(Request $request): JsonResponse
    {
        $compte = $this->compte($request);
        if (null === $compte) {
            return new JsonResponse(['ok' => false], 403);
        }
        $adresse = (string) (json_decode($request->getContent(), true)['endpoint'] ?? '');
        $a = $this->em->getRepository(AbonnementPushClient::class)->findOneBy(['empreinte' => AbonnementPush::empreinte($adresse), 'compte' => $compte]);
        if (null !== $a) {
            $this->em->remove($a);
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true]);
    }

    private function compte(Request $request): ?CompteClient
    {
        $session = $this->getUser();
        if (!$session instanceof CompteClient || !$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token'))) {
            return null;
        }
        $compte = $this->comptes->find($session->getId());

        return $compte instanceof CompteClient && $compte->isActif() ? $compte : null;
    }
}
