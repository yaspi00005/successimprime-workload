<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Patrimoine, amortissements, relevés de compteurs, scores et primes :
 * ERP local seulement (docs/immobilisations-primes.md). Ces tables ne
 * partent pas vers le site, et ces écrans n'existent pas sur le serveur
 * en ligne.
 */
final class PatrimoinePrimesHorsLigneSubscriber implements EventSubscriberInterface
{
    /** Routes (ou débuts de nom de route) fermées en ligne. */
    public const ROUTES = ['app_immobilisations_', 'app_primes_', 'app_bareme_primes', 'app_mon_espace_performance', 'app_mon_espace_prime'];

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

    public static function estLocale(string $route): bool
    {
        foreach (self::ROUTES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                return true;
            }
        }

        return false;
    }
}
