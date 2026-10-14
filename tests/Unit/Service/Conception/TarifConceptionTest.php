<?php

namespace App\Tests\Unit\Service\Conception;

use App\Service\Conception\TarifConception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TarifConceptionTest extends TestCase
{
    /** @return iterable<string, array{int, int, int, int}> forfait, pourcentage, acompte, solde */
    public static function acomptes(): iterable
    {
        yield 'kakémono 50 %' => [10000, 50, 5000, 5000];
        yield 'carte 50 %' => [5000, 50, 2500, 2500];
        yield 'arrondi à la dizaine supérieure' => [7500, 33, 2480, 5020];
        yield 'sans acompte' => [15000, 0, 0, 15000];
        yield 'tout d\'avance' => [50000, 100, 50000, 0];
        yield 'pourcentage hors bornes' => [10000, 150, 10000, 0];
        yield 'forfait nul' => [0, 50, 0, 0];
    }

    #[DataProvider('acomptes')]
    public function testAcompteEtSolde(int $forfait, int $pourcent, int $acompte, int $solde): void
    {
        self::assertSame($acompte, TarifConception::acompte($forfait, $pourcent));
        self::assertSame($solde, TarifConception::solde($forfait, $pourcent));
        self::assertSame($forfait, TarifConception::acompte($forfait, $pourcent) + TarifConception::solde($forfait, $pourcent));
    }

    public function testPacksLusDansLeReglage(): void
    {
        self::assertSame([1, 3, 5, 10], TarifConception::packs('1, 3, 5, 10'));
        self::assertSame([2, 5, 20], TarifConception::packs('20 ; 5, 5, x, 0, 2, 500'));
        self::assertSame([1, 3, 5, 10], TarifConception::packs(''));
        self::assertSame([1, 3, 5, 10], TarifConception::packs(null));
        self::assertSame([1, 3, 5, 10], TarifConception::packs('aucun'));
    }

    public function testPrixDesPacksEtImagesPossibles(): void
    {
        self::assertSame(3000, TarifConception::prixPack(3, 1000));
        self::assertSame(0, TarifConception::prixPack(-2, 1000));
        self::assertSame(3, TarifConception::imagesPossibles(3000, 1000));
        self::assertSame(2, TarifConception::imagesPossibles(2999, 1000));
        self::assertSame(0, TarifConception::imagesPossibles(-1000, 1000));
    }

    public function testMontantEcritALaMaliene(): void
    {
        self::assertSame("5\u{202f}000 F", TarifConception::francs(5000));
        self::assertSame('0 F', TarifConception::francs(0));
    }
}
