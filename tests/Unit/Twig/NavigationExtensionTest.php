<?php

namespace App\Tests\Unit\Twig;

use App\Twig\NavigationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NavigationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function routesProvider(): iterable
    {
        yield 'tableau de bord' => ['app_home', 'accueil'];
        yield 'statistiques' => ['app_statistiques_globales_index', 'accueil'];
        yield 'devis' => ['app_devis_new', 'ventes'];
        yield 'commande' => ['app_commandes_show', 'ventes'];
        yield 'production' => ['app_production_index', 'atelier'];
        yield 'stock' => ['app_stock_etat_index', 'atelier'];
        yield 'facture' => ['app_factures_show', 'finances'];
        yield 'soldes clients avant clients' => ['app_clients_soldes', 'finances'];
        yield 'fiche client' => ['app_clients_show', 'clients'];
        yield 'campagne' => ['app_campagne_index', 'clients'];
        yield 'produits' => ['app_produits_index', 'reglages'];
        yield 'utilisateurs' => ['app_user_index', 'reglages'];
        yield 'messagerie hors menu' => ['app_chat_index', null];
        yield 'route vide' => ['', null];
        yield 'sans route' => [null, null];
    }

    #[DataProvider('routesProvider')]
    public function testSectionPourRoute(?string $route, ?string $section): void
    {
        self::assertSame($section, (new NavigationExtension())->sectionPourRoute($route));
    }

    public function testLibelleSection(): void
    {
        $extension = new NavigationExtension();

        self::assertSame('Réglages', $extension->libelleSection('reglages'));
        self::assertNull($extension->libelleSection(null));
    }

    public function testChaqueSectionAUnEcranDEntree(): void
    {
        $extension = new NavigationExtension();

        foreach (array_keys(NavigationExtension::SECTIONS) as $section) {
            self::assertNotEmpty($extension->ongletsSection($section), $section);
        }

        self::assertSame([], $extension->ongletsSection(null));
    }

    public function testOngletActif(): void
    {
        $extension = new NavigationExtension();

        self::assertSame(0, $extension->ongletActif('app_home'));
        self::assertSame(0, $extension->ongletActif('app_devis_show'));
        self::assertSame(1, $extension->ongletActif('app_livraisons_index'));
        self::assertSame(0, $extension->ongletActif('app_clients_soldes'));
        self::assertSame(1, $extension->ongletActif('app_employes_index'));
        self::assertNull($extension->ongletActif('app_chat_index'));
    }
}
