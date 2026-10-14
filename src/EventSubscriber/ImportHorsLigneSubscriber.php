<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Import des données de démarrage : ERP local seulement
 * (docs/import-donnees.md). Les fiches importées partent vers le site par
 * la synchronisation ordinaire ; l'écran d'import n'existe pas en ligne.
 */
final class ImportHorsLigneSubscriber implements EventSubscriberInterface
{
    public const PREFIXE = 'app_import_';

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
