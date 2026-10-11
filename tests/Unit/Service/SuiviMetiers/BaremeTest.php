<?php

namespace App\Tests\Unit\Service\SuiviMetiers;

use App\Service\Parametres\CatalogueParametres;
use App\Service\SuiviMetiers\Bareme;
use PHPUnit\Framework\TestCase;

final class BaremeTest extends TestCase
{
    public function testExempleW02BatATempsSansErreurMoyen(): void
    {
        self::assertSame(22, (new Bareme())->points(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1], 'moyen'));
    }

    public function testRetardParTrancheEtCoefficientComplexe(): void
    {
        // (10 − 2 × 2) × 2 = 12
        self::assertSame(12, (new Bareme())->points(['g_bat_a_temps' => 1, 'g_retard_30' => 2], 'complexe'));
    }

    public function testArrondiInferieurMemeNegatif(): void
    {
        // −10 × 1,5 = −15 ; −2 × 1,5 = −3 ; (−2 + 5) × 1,5 = 4,5 → 4
        self::assertSame(4, (new Bareme())->points(['g_retard_30' => 1, 'g_sans_erreur' => 1], 'moyen'));
        self::assertSame(-15, (new Bareme())->points(['g_erreur' => 1], 'moyen'));
    }

    public function testLeCoefficientNeConcernePasLesAutresMetiers(): void
    {
        self::assertSame(7, (new Bareme())->points(['l_creneau' => 1, 'l_preuve' => 1], 'complexe'));
        self::assertSame(3, (new Bareme())->points(['p_10m2' => 3.4]));
    }

    public function testValeursDeLaTuile(): void
    {
        $bareme = new Bareme(['g_bat_a_temps' => 12, 'coef_moyen' => 1.2]);
        self::assertSame(20, $bareme->points(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1], 'moyen'));
        self::assertSame(-10, $bareme->valeur('g_erreur'));
    }

    public function testChangementAvisClientNeCouteRien(): void
    {
        self::assertSame(0, (new Bareme())->points(['g_avis_client' => 3]));
    }

    public function testPointsPossiblesNeGardentQueLePositif(): void
    {
        self::assertSame(22, (new Bareme())->possibles(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1, 'g_erreur' => 1], 'moyen'));
    }

    public function testNoteBorneeEntre0Et100(): void
    {
        self::assertSame(87, Bareme::note(612, 703));
        self::assertSame(100, Bareme::note(800, 703));
        self::assertSame(0, Bareme::note(-20, 703));
        self::assertSame(0, Bareme::note(10, 0));
    }

    public function testScorePondereEtPalier(): void
    {
        $bareme = new Bareme();
        self::assertSame(84.3, $bareme->score(87, [85, 80]));
        self::assertSame(75, Bareme::palier(84.3));
        self::assertSame(100, Bareme::palier(90));
        self::assertSame(25, Bareme::palier(60));
        self::assertSame(0, Bareme::palier(59.9));
    }

    public function testRegleInconnueRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Bareme())->points(['inconnue' => 1]);
    }

    public function testChampsDeLEcranHorsDuPanneau(): void
    {
        $panneau = array_column(CatalogueParametres::champsPanneau('bareme'), 'cle');
        self::assertSame(['valeur_point', 'seuil_prime', 'ponctualite'], $panneau);
        self::assertNotNull(CatalogueParametres::champ('bareme', 'l_creneau'));
        self::assertCount(25, Bareme::champs());
    }
}
