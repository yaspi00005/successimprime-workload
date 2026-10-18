<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Entreprise;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Service\Site\AdresseSite;
use App\Service\Site\PhotosSite;
use App\Service\Site\PlanDuSite;
use App\Service\Site\PlanPhotosLocal;
use App\Service\Site\SourcePlanSite;
use App\Tests\Unit\Security\FabriqueCodes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class PlanDuSiteTest extends TestCase
{
    /**
     * @param iterable<SourcePlanSite> $sources
     */
    private function plan(iterable $sources, ?string $siteWeb = 'succesimprim.com', bool $enLigne = true): PlanDuSite
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/'));
        $routes->add('app_site_accueil', new Route('/site'));
        $routes->add('app_site_produits', new Route('/produits'));
        $routes->add('app_site_faq', new Route('/faq'));
        $routes->add('app_site_contact', new Route('/contact'));
        $routes->add('app_site_suivi', new Route('/suivi'));
        $routes->add('app_site_devis', new Route('/devis'));
        $routes->add('app_site_conseils', new Route('/conseils'));
        $routes->add('app_site_conseil', new Route('/conseils/{slug}'));

        $entreprises = $this->createStub(EntrepriseRepository::class);
        $entreprises->method('parCode')->willReturn((new Entreprise())->setCode('mdg_success')->setSiteWeb($siteWeb));
        $acces = new AccesEnLigne($enLigne, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        // La requête vient de l'ERP local : son adresse ne doit jamais sortir.
        $requetes = new RequestStack();
        $requetes->push(Request::create('http://192.168.1.10:8000/site'));

        return new PlanDuSite(
            new UrlGenerator($routes, new RequestContext('', 'GET', '192.168.1.10', 'http', 8000)),
            new AdresseSite($entreprises, $acces, $requetes, 'https://succesimprim.com', 'http://127.0.0.1:8195'),
            $sources,
        );
    }

    private static function source(callable $pages): SourcePlanSite
    {
        return new class($pages) implements SourcePlanSite {
            /** @var callable */
            private $pages;

            public function __construct(callable $pages)
            {
                $this->pages = $pages;
            }

            public function pagesDuPlan(): iterable
            {
                return ($this->pages)();
            }
        };
    }

    public function testPagesExistantesEtPagesDesSourcesSurLAdresseDuSite(): void
    {
        $source = self::source(static function (): iterable {
            yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => 'bien-choisir-sa-bache'], 'modifieLe' => new \DateTimeImmutable('2026-10-01'), 'images' => ['/uploads/site/bache.webp']];
            yield ['route' => 'app_site_inconnue'];
            yield ['route' => 'app_site_suivi'];
        });

        $pages = $this->plan([$source])->pages();
        $urls = array_column($pages, 'url');
        self::assertSame([
            'https://succesimprim.com/',
            'https://succesimprim.com/produits',
            'https://succesimprim.com/contact',
            'https://succesimprim.com/conseils',
            'https://succesimprim.com/devis',
            'https://succesimprim.com/conseils/bien-choisir-sa-bache',
        ], $urls);
        // Ni l'aperçu /site de l'ERP local, ni le suivi de commande, ni une page de contenu non publiée.
        self::assertNotContains('https://succesimprim.com/site', $urls);
        self::assertNotContains('https://succesimprim.com/suivi', $urls);
        self::assertNotContains('https://succesimprim.com/faq', $urls);

        $conseil = $pages[5];
        self::assertSame(['https://succesimprim.com/uploads/site/bache.webp'], $conseil['images']);
        self::assertSame('0.5', $conseil['priorite']);
        self::assertSame('1.0', $pages[0]['priorite']);
        self::assertSame('0.8', $pages[1]['priorite']);
    }

    public function testVraiesPhotosDuLocalSurLAccueilEtLeContact(): void
    {
        $pages = $this->plan([new PlanPhotosLocal(new PhotosSite(__DIR__.'/../../../../public'))])->pages();
        $images = array_column($pages, 'images', 'url');
        self::assertSame(['https://succesimprim.com/images/site/photos/facade-local-succes-imprim-bamako-1200.webp'], $images['https://succesimprim.com/']);
        self::assertSame([
            'https://succesimprim.com/images/site/photos/facade-local-succes-imprim-bamako-1200.webp',
            'https://succesimprim.com/images/site/photos/allee-entree-succes-imprim-1200.webp',
            'https://succesimprim.com/images/site/photos/jardin-entree-succes-imprim-1200.webp',
        ], $images['https://succesimprim.com/contact']);
        // Les photos s'ajoutent aux pages déjà au plan, sans en créer.
        self::assertCount(\count($this->plan([])->pages()), $pages);
    }

    public function testDateConnueSeulementEtAccueilALaDateLaPlusRecente(): void
    {
        $source = self::source(static function (): iterable {
            yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => 'a'], 'modifieLe' => new \DateTimeImmutable('2026-09-01')];
            yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => 'b'], 'modifieLe' => new \DateTimeImmutable('2026-10-12')];
            // Une source précise la page de liste déjà au menu : même adresse, date et images ajoutées.
            yield ['route' => 'app_site_conseils', 'modifieLe' => new \DateTimeImmutable('2026-10-12'), 'images' => ['images/a.webp']];
            yield ['route' => 'app_site_conseils', 'modifieLe' => new \DateTimeImmutable('2026-08-01'), 'images' => ['images/a.webp', 'images/b.webp']];
        });

        $pages = array_column($this->plan([$source])->pages(), null, 'url');

        self::assertSame('2026-10-12', $pages['https://succesimprim.com/']['modifieLe']?->format('Y-m-d'));
        self::assertNull($pages['https://succesimprim.com/contact']['modifieLe']);
        $liste = $pages['https://succesimprim.com/conseils'];
        self::assertSame('2026-10-12', $liste['modifieLe']?->format('Y-m-d'));
        self::assertSame('0.6', $liste['priorite']);
        self::assertSame(['https://succesimprim.com/images/a.webp', 'https://succesimprim.com/images/b.webp'], $liste['images']);
        self::assertCount(1, array_filter(array_keys($pages), static fn (string $u): bool => str_ends_with($u, '/conseils')));
    }

    public function testSansSiteRegleLAdresseDuServeurEnLigne(): void
    {
        $urls = array_column($this->plan([], null, true)->pages(), 'url');

        self::assertSame('https://succesimprim.com/', $urls[0]);
        foreach ($urls as $url) {
            self::assertStringStartsWith('https://succesimprim.com/', $url);
        }
    }
}
