<?php

namespace App\Tests\Unit\Twig;

use App\Twig\FabricationExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class FabricationExtensionTest extends TestCase
{
    public function testUneEntreeSansRouteEstCachee(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_atelier_production', new Route('/atelier/production'));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);

        $onglets = (new FabricationExtension($router))->onglets();

        self::assertSame(['tableau'], array_column($onglets, 'cle'));
    }

    public function testLesEntreesOntDesClesUniques(): void
    {
        $cles = array_column(FabricationExtension::ENTREES, 'cle');

        self::assertSame($cles, array_values(array_unique($cles)));
    }
}
