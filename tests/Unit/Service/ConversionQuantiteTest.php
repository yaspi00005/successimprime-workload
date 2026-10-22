<?php

namespace App\Tests\Unit\Service;

use App\Service\ConversionQuantite;
use PHPUnit\Framework\TestCase;

final class ConversionQuantiteTest extends TestCase
{
    public function testPiecesVersSurface(): void
    {
        self::assertSame(18.0, ConversionQuantite::surfacePieces(3, 4.0, 1.5));
    }

    public function testVingtMetresCarresDeBache(): void
    {
        // Maquette : 20 m2 de bache 400 x 150 -> 4 baches (24 m2) ou 3 (18 m2).
        $choix = ConversionQuantite::piecesDepuisSurface(20, 4.0, 1.5);

        self::assertSame(['pieces' => 4, 'surface' => 24.0], $choix['superieur']);
        self::assertSame(['pieces' => 3, 'surface' => 18.0], $choix['inferieur']);
    }

    public function testSurfaceExacteSansAlternative(): void
    {
        $choix = ConversionQuantite::piecesDepuisSurface(12, 4.0, 1.5);

        self::assertSame(2, $choix['superieur']['pieces']);
        self::assertNull($choix['inferieur']);
    }

    public function testMoinsDUnePieceDonneUnePiece(): void
    {
        $choix = ConversionQuantite::piecesDepuisSurface(2, 4.0, 1.5);

        self::assertSame(1, $choix['superieur']['pieces']);
        self::assertNull($choix['inferieur']);
    }

    public function testVinyleSurRouleau137(): void
    {
        // Maquette : 6 m2 de vinyle sur 1,37 m -> 4,4 m de long.
        self::assertSame(['longueur' => 4.4, 'surface' => 6.03], ConversionQuantite::longueurDepuisSurface(6, 1.37));
        self::assertSame(2.0, ConversionQuantite::longueurDepuisSurface(2.74, 1.37)['longueur']);
    }

    public function testEtiquettesParLots(): void
    {
        self::assertSame(['quantite' => 300, 'lots' => 3, 'feuilles' => 15], ConversionQuantite::parLots(250, 100, 20));
    }

    public function testRepartitionDesTailles(): void
    {
        self::assertSame(['total' => 25, 'tailles' => ['M' => 10, 'L' => 10, 'XL' => 5]], ConversionQuantite::repartitionTailles(['S' => 0, 'M' => 10, 'L' => 10, 'XL' => 5]));
    }

    public function testFormatInvalide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConversionQuantite::piecesDepuisSurface(20, 0, 1.5);
    }
}
