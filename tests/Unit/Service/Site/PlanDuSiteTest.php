<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\PlanDuSite;
use App\Service\Site\SourcePlanSite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class PlanDuSiteTest extends TestCase
{
    public function testPagesExistantesEtPagesDesSources(): void
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/'));
        $routes->add('app_site_accueil', new Route('/site'));
        $routes->add('app_site_faq', new Route('/faq'));
        $routes->add('app_site_contact', new Route('/contact'));
        $routes->add('app_site_conseil', new Route('/conseils/{slug}'));

        $source = new class implements SourcePlanSite {
            public function pagesDuPlan(): iterable
            {
                yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => 'bien-choisir-sa-bache'], 'modifieLe' => new \DateTimeImmutable('2026-10-01')];
                yield ['route' => 'app_site_inconnue'];
            }
        };
        $plan = new PlanDuSite(new UrlGenerator($routes, new RequestContext('', 'GET', 'succesimprim.com', 'https')), [$source]);

        $urls = array_column($plan->pages(), 'url');
        self::assertSame([
            'https://succesimprim.com/',
            'https://succesimprim.com/contact',
            'https://succesimprim.com/faq',
            'https://succesimprim.com/conseils/bien-choisir-sa-bache',
        ], $urls);
        // L'aperçu /site de l'ERP local n'est jamais dans le plan.
        self::assertNotContains('https://succesimprim.com/site', $urls);
    }
}
