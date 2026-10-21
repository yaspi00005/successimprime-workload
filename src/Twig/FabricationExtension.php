<?php

namespace App\Twig;

use App\EventSubscriber\AtelierHorsLigneSubscriber;
use App\Security\AccesEnLigne;
use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Sous-navigation des écrans de production PD01 à PD07 (maquettes du
 * 20/10/2026, docs/production-rouleau.md et docs/production-colis.md).
 *
 * Chaque écran ajoute sa ligne dans ENTREES ; une entrée dont la route
 * n'existe pas encore, ou qui est fermée sur le site en ligne
 * (AtelierHorsLigneSubscriber), est simplement cachée.
 */
final class FabricationExtension extends AbstractExtension
{
    /** @var list<array{cle: string, libelle: string, route: string}> */
    public const ENTREES = [
        ['cle' => 'tableau', 'libelle' => 'Tableau', 'route' => 'app_atelier_production'],
        ['cle' => 'parcours', 'libelle' => 'Parcours d\'un fichier', 'route' => 'app_atelier_production_parcours'],
        ['cle' => 'rouleaux', 'libelle' => 'Rouleaux', 'route' => 'app_atelier_production_rouleaux'],
        ['cle' => 'machines', 'libelle' => 'Machines et dossiers', 'route' => 'app_atelier_production_machines'],
    ];

    public function __construct(private readonly RouterInterface $router, private readonly ?AccesEnLigne $acces = null)
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
        $enLigne = $this->acces?->estEnLigne() ?? false;
        $fermee = static function (string $route) use ($enLigne): bool {
            foreach ($enLigne ? AtelierHorsLigneSubscriber::ROUTES : [] as $prefixe) {
                if (str_starts_with($route, $prefixe)) {
                    return true;
                }
            }

            return false;
        };

        return array_values(array_filter(self::ENTREES, static fn (array $e): bool => null !== $routes->get($e['route']) && !$fermee($e['route'])));
    }
}
