<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le guide utilisateur (docs/guide-utilisateur.md) est celui de l'ERP
 * local : ses fiches et ses captures ne sont pas servies par le serveur
 * en ligne. Là-bas, ces adresses n'existent pas.
 */
final class AideHorsLigneSubscriber implements EventSubscriberInterface
{
    public const PREFIXE = 'app_aide_';

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
        if (str_starts_with((string) $event->getRequest()->attributes->get('_route', ''), self::PREFIXE)) {
            throw new NotFoundHttpException();
        }
    }
}
