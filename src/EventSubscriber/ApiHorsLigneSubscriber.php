<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * L'API ne répond que sur l'ERP local (docs/api.md) : en ligne, l'accès
 * passe par un appareil autorisé et un code SMS, qu'un jeton
 * contournerait. Sur le serveur en ligne, /api/v1 n'existe pas.
 */
final class ApiHorsLigneSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant le routeur (32) et le pare-feu (8).
        return [KernelEvents::REQUEST => ['fermer', 300]];
    }

    public function fermer(RequestEvent $event): void
    {
        if (!$this->enLigne || !$event->isMainRequest()) {
            return;
        }
        $chemin = $event->getRequest()->getPathInfo();
        if ('/api/v1' === $chemin || str_starts_with($chemin, '/api/v1/')) {
            $event->setResponse(new JsonResponse(['title' => 'Introuvable', 'status' => 404], 404, ['Content-Type' => 'application/problem+json']));
        }
    }
}
