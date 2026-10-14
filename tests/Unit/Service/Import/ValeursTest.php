<?php

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\Valeurs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des cellules d'un fichier d'import (docs/import-donnees.md).
 */
final class ValeursTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function montants(): iterable
    {
        yield 'espaces des milliers' => ['1 500 000', 1500000];
        yield 'espace insécable d\'Excel' => ["1\u{00A0}500\u{00A0}000", 1500000];
        yield 'espace fine insécable' => ["1\u{202F}500\u{202F}000", 1500000];
        yield 'centimes nuls à la française' => ['1500000,00', 1500000];
        yield 'centimes nuls à l\'anglaise' => ['1500000.00', 1500000];
        yield 'points des milliers' => ['1.500.000', 1500000];
        yield 'virgules des milliers' => ['1,500,000', 1500000];
        yield 'milliers et décimales' => ['1.500.000,00', 1500000];
        yield 'avec la devise' => ['250 000 F', 250000];
        yield 'avec FCFA' => ['250000 FCFA', 250000];
        yield 'nombre brut d\'Excel' => ['185000', 185000];
        yield 'zéro' => ['0', 0];
    }

    #[DataProvider('montants')]
    public function testMontants(string $brut, int $attendu): void
    {
        self::assertSame($attendu, Valeurs::montant($brut));
    }

    public function testMontantNegatifSeulementQuandPermis(): void
    {
        self::assertSame(-25000, Valeurs::montant('-25 000', true));
        self::assertSame(-25000, Valeurs::montant('(25 000)', true));
        self::assertSame(-25000, Valeurs::montant('−25 000', true));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ne peut pas être négatif');
        Valeurs::montant('-25 000');
    }

    public function testCentimesRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sans centimes');
        Valeurs::montant('1500,50');
    }

    public function testTexteRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('n\'est pas un montant');
        Valeurs::montant('abc');
    }

    public function testNombresAmbigus(): void
    {
        self::assertSame(2.5, Valeurs::nombre('2,5'));
        self::assertSame(0.5, Valeurs::nombre('0,500'));
        self::assertSame(1500.0, Valeurs::nombre('1,500'));
        self::assertNull(Valeurs::nombre('1,5,0'));
        self::assertNull(Valeurs::nombre(''));
        self::assertNull(Valeurs::nombre('12 ans'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function telephones(): iterable
    {
        yield 'espaces' => ['76 12 34 56', '76123456'];
        yield 'indicatif +223' => ['+223 76 12 34 56', '76123456'];
        yield 'indicatif 00223' => ['00223 76123456', '76123456'];
        yield 'indicatif 223 collé' => ['22376123456', '76123456'];
        yield 'tirets et parenthèses' => ['(+223) 65-43-21-09', '65432109'];
        yield 'points' => ['20.22.33.44', '20223344'];
        yield 'nombre d\'Excel' => ['76123456.0', '76123456'];
        yield 'notation scientifique d\'Excel' => ['7.6123456E7', '76123456'];
        yield 'trop court' => ['12345', null];
        yield 'trop long' => ['761234567', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('telephones')]
    public function testTelephones(string $brut, ?string $attendu): void
    {
        self::assertSame($attendu, Valeurs::telephone($brut));
    }

    public function testDates(): void
    {
        self::assertSame('2026-11-15', Valeurs::date('15/11/2026')?->format('Y-m-d'));
        self::assertSame('2026-11-05', Valeurs::date('5/11/26')?->format('Y-m-d'));
        self::assertSame('2026-11-15', Valeurs::date('15-11-2026')?->format('Y-m-d'));
        self::assertSame('2026-11-15', Valeurs::date('2026-11-15')?->format('Y-m-d'));
        self::assertSame('2026-11-15', Valeurs::date('2026-11-15 00:00:00')?->format('Y-m-d'));
        // Numéro de série d'Excel.
        self::assertSame('2026-11-15', Valeurs::date('46341')?->format('Y-m-d'));
        self::assertNull(Valeurs::date(''));
    }

    public function testDateImpossible(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('n\'est pas une date qui existe');
        Valeurs::date('31/02/2026');
    }

    public function testCleEtBooleens(): void
    {
        self::assertSame('hotel du fleuve sarl', Valeurs::cle('  Hôtel  du Fleuve, SARL '));
        self::assertSame('m2', Valeurs::cle('m²'));
        self::assertTrue(Valeurs::booleen('Oui'));
        self::assertTrue(Valeurs::booleen('x'));
        self::assertFalse(Valeurs::booleen(''));
        self::assertFalse(Valeurs::booleen('non'));
        self::assertNull(Valeurs::booleen('peut-être'));
        self::assertSame('1 500 000 F', Valeurs::francs(1500000));
        self::assertNull(Valeurs::texte('   '));
        self::assertSame('Rue 312 porte 18', Valeurs::texte("  Rue 312\n porte   18 "));
    }
}
