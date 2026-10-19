<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Messagerie de l'équipe (docs/messagerie-interne.md) : ERP local
 * seulement. Ses tables ne partent pas vers le site ; ces écrans
 * n'existent pas sur le serveur en ligne.
 */
final class MessagerieInterneHorsLigneSubscriber implements EventSubscriberInterface
{
    public const ROUTES = ['app_messagerie_interne_'];

    public function __construct(private readonly AccesEnLigne $acces)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Après le routeur (32), avant le pare-feu (8).
        return [KernelEvents::REQUEST => ['fermer', 30]];
    }

    public function fermer(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->acces->estEnLigne()) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route', '');
        foreach (self::ROUTES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                throw new NotFoundHttpException();
            }
        }
    }
}
