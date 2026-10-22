<?php

namespace App\Tests\Unit\Service;

use App\Service\Statistiques\StatistiquesRapports;
use PHPUnit\Framework\TestCase;

final class StatistiquesRapportsTest extends TestCase
{
    public function testMargeNeCompteQueLesLignesChiffrees(): void
    {
        $marge = StatistiquesRapports::marge([
            ['categorie' => 'Bâche', 'ht' => 100000, 'cout' => 60000],
            ['categorie' => 'DTF', 'ht' => 50000, 'cout' => 20000],
            ['categorie' => 'DTF', 'ht' => 50000, 'cout' => null],
        ]);

        self::assertSame(47, $marge['taux']);
        self::assertSame(70000, $marge['montant']);
        self::assertSame(75, $marge['part_chiffree']);
        self::assertNull(StatistiquesRapports::marge([['categorie' => 'X', 'ht' => 1000, 'cout' => null]])['taux']);
    }

    public function testMargeParCategorieTrieeParTaux(): void
    {
        $r = StatistiquesRapports::margeParCategorie([
            ['categorie' => 'Bâche', 'ht' => 100000, 'cout' => 60000],
            ['categorie' => 'DTF', 'ht' => 50000, 'cout' => 20000],
            ['categorie' => 'DTF', 'ht' => 50000, 'cout' => 30000],
            ['categorie' => 'Cartes', 'ht' => 0, 'cout' => 0],
        ]);

        self::assertSame([['categorie' => 'DTF', 'taux' => 50, 'ht' => 100000], ['categorie' => 'Bâche', 'taux' => 40, 'ht' => 100000]], $r);
    }

    public function testEvolution(): void
    {
        self::assertNull(StatistiquesRapports::evolution(100, 0));
        self::assertSame(31, StatistiquesRapports::evolution(131, 100));
        self::assertSame(-50, StatistiquesRapports::evolution(50, 100));
    }
}
