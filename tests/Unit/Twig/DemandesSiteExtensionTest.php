<?php

namespace App\Tests\Unit\Twig;

use App\Twig\NavigationExtension;
use PHPUnit\Framework\TestCase;

/** Ventes › Demandes du site : rangé dans Ventes, pas dans les demandes du personnel. */
final class DemandesSiteExtensionTest extends TestCase
{
    public function testOngletDansLesVentes(): void
    {
        $navigation = new NavigationExtension();

        self::assertSame('ventes', $navigation->sectionPourRoute('app_demandes_site_voir'));
        self::assertSame('equipe', $navigation->sectionPourRoute('app_demandes_index'), 'demandes du personnel inchangées');

        $onglets = $navigation->ongletsSection('ventes');
        $rang = $navigation->ongletActif('app_demandes_site_index');
        self::assertNotNull($rang);
        self::assertSame('Demandes du site', $onglets[$rang]['libelle']);
        self::assertSame('ROLE_DEVIS', $onglets[$rang]['role']);
    }

    public function testCreationsAtelierDansLesVentes(): void
    {
        $navigation = new NavigationExtension();

        self::assertSame('ventes', $navigation->sectionPourRoute('app_creations_atelier_voir'));
        $onglets = $navigation->ongletsSection('ventes');
        $rang = $navigation->ongletActif('app_creations_atelier_index');
        self::assertNotNull($rang);
        self::assertSame('Créations Atelier 3D', $onglets[$rang]['libelle']);
        // Le graphiste (Devis ou Commandes) les voit ; pas le livreur ni la caisse.
        self::assertSame(['ROLE_DEVIS', 'ROLE_COMMANDE'], $onglets[$rang]['role']);
    }
}
