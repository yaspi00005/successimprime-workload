<?php

namespace App\Tests\Unit\Service;

use App\Entity\Machines;
use App\Entity\ProduitArticleStock;
use App\Service\CalculateurCoutRevient;
use PHPUnit\Framework\TestCase;

final class CalculateurCoutRevientTest extends TestCase
{
    public function testDeuxBachesSurLaize320(): void
    {
        // Maquette C01 : 2 baches 400 x 150 cm sur 3,20 m, chute 6 %.
        $pose = CalculateurCoutRevient::imposition(4.0, 1.5, 2, 3.2);

        self::assertEqualsWithDelta(12.0, $pose['surfaceUtile'], 0.001);
        self::assertEqualsWithDelta(12.8, $pose['surfaceConsommee'], 0.001);
        self::assertEqualsWithDelta(4.0, $pose['metresLineaires'], 0.001);
        self::assertSame(6.3, $pose['chute']);
    }

    public function testGardeLeSensQuiConsommeLeMoins(): void
    {
        // 5 roll-up 85 x 200 cm sur 1,80 m : 2 de front, 3 rangees de 2 m.
        $pose = CalculateurCoutRevient::imposition(0.85, 2.0, 5, 1.8);

        self::assertEqualsWithDelta(6.0, $pose['metresLineaires'], 0.001);
        self::assertEqualsWithDelta(10.8, $pose['surfaceConsommee'], 0.001);
    }

    public function testPiecePlusLargeQueLaLaize(): void
    {
        $pose = CalculateurCoutRevient::imposition(5.0, 4.0, 1, 3.2);

        self::assertEqualsWithDelta(20.0, $pose['surfaceConsommee'], 0.001);
        self::assertSame(0.0, $pose['chute']);
    }

    public function testSansLaizePasDeChute(): void
    {
        $pose = CalculateurCoutRevient::imposition(1.0, 1.0, 3, null);

        self::assertEqualsWithDelta(3.0, $pose['surfaceConsommee'], 0.001);
        self::assertSame(0.0, $pose['chute']);
    }

    public function testBesoinSelonLeModeDeLArticle(): void
    {
        self::assertEqualsWithDelta(12.8, CalculateurCoutRevient::besoinArticle(ProduitArticleStock::MODE_SURFACE, 1, 2, 12.8, 4), 0.001);
        self::assertEqualsWithDelta(8.0, CalculateurCoutRevient::besoinArticle(ProduitArticleStock::MODE_METRE, 2, 2, 12.8, 4), 0.001);
        self::assertEqualsWithDelta(50.0, CalculateurCoutRevient::besoinArticle(ProduitArticleStock::MODE_QUANTITE, 2, 25, 0, 0), 0.001);
        self::assertEqualsWithDelta(1.5, CalculateurCoutRevient::besoinArticle(ProduitArticleStock::MODE_FORFAIT, 1.5, 25, 0, 0), 0.001);
    }

    public function testCoutHoraireDeduitDeLAmortissement(): void
    {
        $machine = (new Machines())->setPrixAchat(24_000_000)->setDureeAmortissementMois(60);

        self::assertSame(2000, CalculateurCoutRevient::coutHoraire($machine));
        self::assertSame(3500, CalculateurCoutRevient::coutHoraire($machine->setCoutHoraire(3500)));
        self::assertNull(CalculateurCoutRevient::coutHoraire(new Machines()));
    }

    public function testLaizeLueQuelleQueSoitLaSaisie(): void
    {
        self::assertSame(3.2, (new Machines())->setLargeurImpression('3,20 m')->getLaizeMetres());
        self::assertSame(1.8, (new Machines())->setLargeurImpression('180')->getLaizeMetres());
        self::assertNull((new Machines())->setLargeurImpression('A3')->getLaizeMetres());
    }

    public function testTauxDeMarge(): void
    {
        self::assertSame(60.0, CalculateurCoutRevient::tauxMarge(58_500, 23_400));
        self::assertNull(CalculateurCoutRevient::tauxMarge(0, 100));
    }
}
