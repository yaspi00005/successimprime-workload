<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Atelier de production (docs/atelier-production.md) : ERP local
 * seulement. Les tables de l'atelier (machines affectées, encre,
 * incidents, entretien) ne partent pas vers le site ; ces écrans
 * n'existent pas sur le serveur en ligne.
 */
final class AtelierHorsLigneSubscriber implements EventSubscriberInterface
{
    public const ROUTES = [
        'app_atelier_direct', 'app_atelier_tv', 'app_atelier_files', 'app_atelier_operateurs', 'app_atelier_encre',
        'app_atelier_planning', 'app_atelier_maintenance', 'app_atelier_incident_', 'app_atelier_entretien_',
        'app_atelier_tetes', 'app_atelier_operateur', 'app_tv_atelier',
        // Production au rouleau, machines et dossiers (docs/production-rouleau.md), colis et
        // contrôle final (docs/production-colis.md) : tables locales, photos des pièces.
        'app_atelier_production_', 'app_colis_qr',
    ];

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
