<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ConsommationProduction;
use PHPUnit\Framework\TestCase;

/**
 * Sorties de stock de la production (PD04, PD06) : chute du rouleau par
 * ligne, encre par couleur, œillets dans l'unité de l'article.
 */
final class ConsommationProductionTest extends TestCase
{
    public function testRepartirTombeJuste(): void
    {
        $parts = ConsommationProduction::repartir(10.0, [1 => 1.0, 2 => 1.0, 3 => 1.0]);

        self::assertSame([1 => 3.333, 2 => 3.333, 3 => 3.334], $parts);
        self::assertEqualsWithDelta(10.0, array_sum($parts), 0.0000001);
    }

    public function testChuteParLigneMoinsLaRecette(): void
    {
        // Rouleau : 15,2 m² consommés pour 11,8 m² imprimés (6 m² et 5,8 m²) ; la recette a déjà sorti la surface des pièces.
        $c = ConsommationProduction::complements(15.2, [10 => 6.0, 11 => 5.8], [10 => 6.0, 11 => 5.8]);

        self::assertSame([10 => 1.729, 11 => 1.671], $c);
        self::assertEqualsWithDelta(15.2, 6.0 + 5.8 + array_sum($c), 0.0000001);
        // Rien de déjà sorti (produit sans recette) : toute la part.
        self::assertSame([10 => 7.729, 11 => 7.471], ConsommationProduction::complements(15.2, [10 => 6.0, 11 => 5.8], []));
        // La recette a sorti plus que la part : rien de plus, jamais négatif.
        self::assertSame([10 => 0.0], ConsommationProduction::complements(5.0, [10 => 6.0], [10 => 6.0]));
    }

    public function testEncreSurLesCouleursCMJN(): void
    {
        $encres = [1 => 'Encre solvant Cyan 1 L', 2 => 'Encre solvant Magenta 1 L', 3 => 'Encre solvant Jaune 1 L', 4 => 'Encre solvant Noir 1 L', 5 => 'Encre Blanc 1 L', 6 => 'Encre Light Cyan'];
        $l = ConsommationProduction::encre(78.0, $encres);

        self::assertSame([1, 2, 3, 4], array_keys($l));
        self::assertEqualsWithDelta(0.078, array_sum($l), 0.0000001);
        self::assertSame(0.02, $l[1]);
        self::assertSame(0.018, $l[4]);
        // Aucune couleur reconnue : toutes les encres de la machine.
        self::assertCount(2, ConsommationProduction::encre(40.0, [7 => 'Encre A', 8 => 'Encre B']));
        self::assertSame([], ConsommationProduction::encre(0.0, $encres));
        self::assertSame([], ConsommationProduction::encre(40.0, []));
    }

    public function testUniteDuRouleau(): void
    {
        self::assertSame(6.6, ConsommationProduction::versUniteStock('m2', 6.6, 3.2, 50.0));
        self::assertSame(2.062, ConsommationProduction::versUniteStock('metre', 6.6, 3.2, 50.0));
        self::assertSame(0.041, ConsommationProduction::versUniteStock('rouleau', 6.6, 3.2, 50.0));
        self::assertNull(ConsommationProduction::versUniteStock('rouleau', 6.6, 3.2, null));
        self::assertNull(ConsommationProduction::versUniteStock('litre', 6.6, 3.2, 50.0));
    }

    public function testOeilletsJamaisEnBoites(): void
    {
        self::assertSame(32.0, ConsommationProduction::piecesEnStock('unite', 32, 500));
        self::assertSame(0.064, ConsommationProduction::piecesEnStock('boite', 32, 500));
        self::assertNull(ConsommationProduction::piecesEnStock('paquet', 32, 1));
        self::assertSame(0.0, ConsommationProduction::piecesEnStock('boite', 0, 500));
    }
}
