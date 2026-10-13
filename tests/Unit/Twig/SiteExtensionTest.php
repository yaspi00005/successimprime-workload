<?php

namespace App\Tests\Unit\Twig;

use App\Controller\SiteController;
use App\Entity\Entreprise;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Security\CodeParSms;
use App\Service\NotificationService;
use App\Service\OrangeSmsService;
use App\Twig\SiteExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class SiteExtensionTest extends TestCase
{
    private function extension(bool $enLigne, ?Entreprise $entreprise = null): SiteExtension
    {
        $routes = new RouteCollection();
        $routes->add('app_home', new Route('/'));
        $routes->add('app_site_accueil', new Route('/site'));
        $routes->add('app_site_contact', new Route('/contact'));
        $routes->add('app_site_faq', new Route('/faq'));

        $sms = new OrangeSmsService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '', '', '', '');
        $acces = new AccesEnLigne($enLigne, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), new CodeParSms('secret-de-test', 'dev', $sms), $this->createStub(NotificationService::class));

        $entreprises = $this->createStub(EntrepriseRepository::class);
        $entreprises->method('parCode')->willReturn($entreprise);
        $entreprises->method('dansLOrdre')->willReturn([]);

        return new SiteExtension(new UrlGenerator($routes, new RequestContext()), $entreprises, $acces);
    }

    public function testUnePagePasEncoreConstruiteNAPasDeLien(): void
    {
        $site = $this->extension(false);

        self::assertNull($site->lien('app_site_calculateur'));
        self::assertSame('/contact', $site->lien('app_site_contact'));
    }

    public function testLeMenuNeGardeQueLesPagesExistantes(): void
    {
        $menu = $this->extension(false)->getFunctions()[1]->getCallable()();

        self::assertSame(['app_site_accueil', 'app_site_contact'], array_column($menu, 'route'));
        self::assertSame('/site', $menu[0]['url']);
    }

    public function testEnLigneLAccueilEstLaRacine(): void
    {
        self::assertSame('/', $this->extension(true)->lien('app_site_accueil'));
        self::assertSame('/site', $this->extension(false)->lien('app_site_accueil'));
    }

    public function testWhatsappVersLeNumeroDeLEntreprise(): void
    {
        $entreprise = (new Entreprise())->setCode('mdg_success')->setTelephone('+223 70 38 69 12');

        self::assertSame('https://wa.me/22370386912?text=Bonjour', $this->extension(false, $entreprise)->whatsapp('Bonjour'));
        self::assertNull($this->extension(false)->whatsapp());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function famillesProvider(): iterable
    {
        yield 'grand format' => ['Grand format (démo)', 3, 'grand'];
        yield 'textile' => ['Textile DTF', 0, 'textile'];
        yield 'impression numérique' => ['Impression numérique', 0, 'petit'];
        yield 'signalétique' => ['Signalétique', 0, 'signaletique'];
        yield 'inconnu, au rang' => ['Objets publicitaires', 1, 'textile'];
    }

    #[DataProvider('famillesProvider')]
    public function testFamilleDUnUnivers(string $nom, int $rang, string $famille): void
    {
        self::assertSame($famille, SiteController::famille($nom, $rang));
    }

    public function testLaRacineEstRevalideeEtLesAutresPagesGardees(): void
    {
        $controleur = new SiteController($this->createStub(EntityManagerInterface::class));

        $racine = $controleur->cache(new Response('accueil'), Request::create('/'));
        self::assertTrue($racine->headers->hasCacheControlDirective('no-cache'));
        self::assertNotNull($racine->getEtag());

        $page = $controleur->cache(new Response('faq'), Request::create('/faq'));
        self::assertSame(300, $page->getMaxAge());
        self::assertTrue($page->headers->hasCacheControlDirective('public'));
    }
}
