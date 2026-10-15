<?php

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ReferencementSubscriber;
use App\Repository\AppareilAutoriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Tests\Unit\Security\FabriqueCodes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ReferencementSubscriberTest extends TestCase
{
    public function testPagesIndexables(): void
    {
        foreach (['app_home', 'app_site_produits', 'app_site_faq', 'app_site_conseil', 'app_site_contact', 'app_site_devis', 'app_site_plan'] as $route) {
            self::assertTrue(ReferencementSubscriber::indexable(true, $route), $route);
            // L'ERP local n'est jamais indexé.
            self::assertFalse(ReferencementSubscriber::indexable(false, $route), $route);
        }
        foreach (['app_site_accueil', 'app_site_suivi', 'app_site_demande_envoyee', 'app_espace_connexion', 'app_login', 'app_recherche', ''] as $route) {
            self::assertFalse(ReferencementSubscriber::indexable(true, $route), $route);
        }
    }

    private function reponse(bool $enLigne, string $route, int $type = HttpKernelInterface::MAIN_REQUEST, ?Response $reponse = null): Response
    {
        $acces = new AccesEnLigne($enLigne, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        $requete = Request::create('/x');
        $requete->attributes->set('_route', $route);
        $reponse ??= new Response();
        (new ReferencementSubscriber($acces))->marquer(new ResponseEvent($this->createStub(HttpKernelInterface::class), $requete, $type, $reponse));

        return $reponse;
    }

    public function testEnTeteNoindex(): void
    {
        self::assertFalse($this->reponse(true, 'app_site_faq')->headers->has('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $this->reponse(true, 'app_espace_connexion')->headers->get('X-Robots-Tag'));
        self::assertSame('noindex, nofollow', $this->reponse(false, 'app_site_faq')->headers->get('X-Robots-Tag'));
        // Sous-requête : rien ; en-tête déjà posé : gardé.
        self::assertFalse($this->reponse(true, 'app_espace_connexion', HttpKernelInterface::SUB_REQUEST)->headers->has('X-Robots-Tag'));
        self::assertSame('none', $this->reponse(false, 'app_home', reponse: new Response('', 200, ['X-Robots-Tag' => 'none']))->headers->get('X-Robots-Tag'));
    }
}
