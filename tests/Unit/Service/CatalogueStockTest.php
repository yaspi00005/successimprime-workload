<?php

namespace App\Tests\Unit\Service;

use App\Entity\Articles;
use App\Service\CatalogueStock;
use App\Service\StockService;
use PHPUnit\Framework\TestCase;

final class CatalogueStockTest extends TestCase
{
    public function testEtatSelonLeSeuil(): void
    {
        self::assertSame(CatalogueStock::ETAT_COMMANDER, CatalogueStock::etat(12.4, 64));
        self::assertSame(CatalogueStock::ETAT_BIENTOT, CatalogueStock::etat(1.4, 1));
        self::assertSame(CatalogueStock::ETAT_OK, CatalogueStock::etat(102.4, 40));
        self::assertSame(CatalogueStock::ETAT_COMMANDER, CatalogueStock::etat(0, 0));
        self::assertSame(CatalogueStock::ETAT_OK, CatalogueStock::etat(5, 0));
    }

    public function testNiveauParRapportAuMaximum(): void
    {
        self::assertSame(8, CatalogueStock::niveau(38.4, 64, 480));
        self::assertSame(50, CatalogueStock::niveau(30, 20, null));
        self::assertSame(100, CatalogueStock::niveau(900, 64, 480));
    }

    public function testCouvertureSurLesMoisTermines(): void
    {
        $serie = [
            ['quantite' => 140.0, 'enCours' => false],
            ['quantite' => 160.0, 'enCours' => false],
            ['quantite' => 500.0, 'enCours' => true],
        ];

        self::assertSame(['moyenne' => 150.0, 'couvertureJours' => 2], CatalogueStock::couverture($serie, 12.4));
        self::assertNull(CatalogueStock::couverture([], 10)['couvertureJours']);
    }

    public function testConversionRouleauEnMetresCarres(): void
    {
        $bache = (new Articles())->setUnite('m2')->setUniteAchat('rouleau')->setLongueurUniteAchat('50')->setLaize('3,20');

        self::assertEqualsWithDelta(160.0, $bache->getFacteurConversion(), 0.0001);
        self::assertEqualsWithDelta(320.0, $bache->convertirAchatEnStock(2), 0.0001);

        $bache->setPrixAchat(236000);
        self::assertSame(1475, $bache->getCoutUnitaireStock());

        $bache->setQuantiteParUniteAchat(150);
        self::assertEqualsWithDelta(150.0, $bache->getFacteurConversion(), 0.0001);
    }

    public function testCoutMoyenPondere(): void
    {
        // 38,4 m² à 1 500 F + 160 m² à 1 475 F
        self::assertSame(1480, StockService::coutMoyenPondere(38.4, 1500, 160, 1475));
        self::assertSame(1475, StockService::coutMoyenPondere(0, 1500, 160, 1475));
        self::assertSame(1475, StockService::coutMoyenPondere(10, null, 160, 1475));
    }
}
