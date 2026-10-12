<?php

namespace App\Controller;

use App\Entity\AbonnementPush;
use App\Entity\User;
use App\Service\Push\WebPush;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Le téléphone de la personne connectée accepte (ou refuse) les
 * notifications : on garde l'adresse de son service de notification et
 * ses clés de chiffrement.
 */
#[Route('/push', name: 'app_push_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class PushController extends AbstractController
{
    public const CSRF = 'push';

    public function __construct(private readonly EntityManagerInterface $em, private readonly WebPush $push)
    {
    }

    #[Route('/abonner', name: 'abonner', methods: ['POST'])]
    public function abonner(Request $request): JsonResponse
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || !$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['ok' => false], 403);
        }
        $donnees = json_decode($request->getContent(), true);
        $adresse = (string) ($donnees['endpoint'] ?? '');
        $cle = (string) ($donnees['keys']['p256dh'] ?? '');
        $secret = (string) ($donnees['keys']['auth'] ?? '');
        if (!$this->push->estConfigure() || !self::adresseValable($adresse) || \strlen(WebPush::b64d($cle)) !== 65 || \strlen(WebPush::b64d($secret)) !== 16) {
            return new JsonResponse(['ok' => false], 400);
        }
        $appareil = $request->headers->get('User-Agent');
        $a = $this->em->getRepository(AbonnementPush::class)->findOneBy(['empreinte' => AbonnementPush::empreinte($adresse)]);
        if ($a === null) {
            $this->em->persist(new AbonnementPush($moi, $adresse, $cle, $secret, $appareil, new \DateTimeImmutable()));
        } else {
            $a->renouveler($moi, $cle, $secret, $appareil);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/desabonner', name: 'desabonner', methods: ['POST'])]
    public function desabonner(Request $request): JsonResponse
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || !$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['ok' => false], 403);
        }
        $adresse = (string) (json_decode($request->getContent(), true)['endpoint'] ?? '');
        $a = $this->em->getRepository(AbonnementPush::class)->findOneBy(['empreinte' => AbonnementPush::empreinte($adresse), 'utilisateur' => $moi]);
        if ($a !== null) {
            $this->em->remove($a);
            $this->em->flush();
        }

        return new JsonResponse(['ok' => true]);
    }

    /** Seule une adresse https d'un service de notification est acceptée (pas d'adresse interne). */
    public static function adresseValable(string $adresse): bool
    {
        $hote = (string) parse_url($adresse, \PHP_URL_HOST);

        return str_starts_with($adresse, 'https://') && \strlen($adresse) <= 1000 && $hote !== ''
            && (bool) preg_match('/(^|\.)(googleapis\.com|mozilla\.com|mozaws\.net|push\.apple\.com|notify\.windows\.com)$/', $hote);
    }
}
