<?php

namespace App\Twig;

use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Sous-navigation des écrans de production PD01 à PD07 (maquettes du
 * 20/10/2026, docs/production-rouleau.md et docs/production-colis.md).
 *
 * Chaque écran ajoute sa ligne dans ENTREES ; une entrée dont la route
 * n'existe pas encore est simplement cachée.
 */
final class FabricationExtension extends AbstractExtension
{
    /** @var list<array{cle: string, libelle: string, route: string}> */
    public const ENTREES = [
        ['cle' => 'tableau', 'libelle' => 'Tableau', 'route' => 'app_atelier_production'],
        ['cle' => 'etiquettes', 'libelle' => 'Étiquettes', 'route' => 'app_atelier_production_etiquettes'],
        ['cle' => 'controle', 'libelle' => 'Contrôle final', 'route' => 'app_atelier_production_controle'],
    ];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('fabrication_onglets', $this->onglets(...))];
    }

    /** @return list<array{cle: string, libelle: string, route: string}> */
    public function onglets(): array
    {
        $routes = $this->router->getRouteCollection();

        return array_values(array_filter(self::ENTREES, static fn (array $e): bool => null !== $routes->get($e['route'])));
    }
}
