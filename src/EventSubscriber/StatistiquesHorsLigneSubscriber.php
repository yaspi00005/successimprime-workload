<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Objectifs du mois, exports Excel / PDF des statistiques et rapport de
 * la semaine : ERP local seulement (docs/statistiques.md). Les objectifs
 * et la trace des rapports ne partent pas vers le site, et un fichier de
 * chiffres ne sort pas d'un navigateur connecté depuis l'extérieur. Sur
 * le serveur en ligne, ces adresses n'existent pas.
 */
final class StatistiquesHorsLigneSubscriber implements EventSubscriberInterface
{
    /** Routes (ou débuts de nom de route) fermées en ligne. */
    public const ROUTES = ['app_statistiques_objectifs', 'app_rapport_semaine'];

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
        if (self::estLocale((string) $event->getRequest()->attributes->get('_route', ''))) {
            throw new NotFoundHttpException();
        }
    }

    /** Une route réservée à l'ERP local : objectifs, rapport de la semaine, exports de statistiques. */
    public static function estLocale(string $route): bool
    {
        foreach (self::ROUTES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                return true;
            }
        }

        return (str_starts_with($route, 'app_statistiques_') || str_starts_with($route, 'app_mes_statistiques')) && str_ends_with($route, 'export');
    }
}
