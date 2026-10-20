<?php

namespace App\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\RouteCollection;

/**
 * Charge les routes des contrôleurs (#[Route]) et laisse les paramètres
 * numériques (« {id} » en \d+) accepter aussi un code chiffré de 9
 * caractères (CodeId). Branché dans config/routes.yaml (type ids_chiffres).
 */
#[AutoconfigureTag('routing.loader')]
final class ChargeurRoutesIdsChiffres extends Loader
{
    private const NUMERIQUES = ['\d+', '[0-9]+', '\d+|nouveau'];

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        /** @var RouteCollection $routes */
        $routes = $this->import('routing.controllers');
        foreach ($routes->all() as $nom => $route) {
            if (!IdsChiffres::routeConcernee($nom)) {
                continue;
            }
            foreach ($route->compile()->getPathVariables() as $variable) {
                $exigence = $route->getRequirement($variable);
                if (isset(IdsChiffres::NOMS[$variable]) && null !== $exigence && \in_array($exigence, self::NUMERIQUES, true)) {
                    $route->setRequirement($variable, $exigence.'|'.CodeId::MOTIF);
                }
            }
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'ids_chiffres' === $type;
    }
}
