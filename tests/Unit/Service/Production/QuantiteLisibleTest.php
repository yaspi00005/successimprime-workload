<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\QuantiteLisible;
use PHPUnit\Framework\TestCase;

/**
 * Quantités du bloc « Consommé » du contrôle final (PD06,
 * docs/production-colis.md), dans l'unité de stock de l'article.
 */
final class QuantiteLisibleTest extends TestCase
{
    public function testSurfacesLongueursEtPoids(): void
    {
        self::assertSame('6,6 m²', QuantiteLisible::texte(6.6, 'm2'));
        self::assertSame('12 m²', QuantiteLisible::texte(12.0, 'm2'));
        self::assertSame('3,2 m', QuantiteLisible::texte(3.2, 'metre'));
        self::assertSame('1,5 kg', QuantiteLisible::texte(1.5, 'kg'));
    }

    public function testEncreEnMillilitresSousUnLitre(): void
    {
        self::assertSame('78 ml', QuantiteLisible::texte(0.078, 'litre'));
        self::assertSame('1,2 L', QuantiteLisible::texte(1.2, 'litre'));
    }

    public function testPiecesEtConditionnements(): void
    {
        self::assertSame('32', QuantiteLisible::texte(32.0, 'unite'));
        self::assertSame('1 boîte', QuantiteLisible::texte(1.0, 'boite'));
        self::assertSame('2 boîtes', QuantiteLisible::texte(2.0, 'boite'));
        self::assertSame('120 feuilles', QuantiteLisible::texte(120.0, 'feuille'));
        self::assertSame('1 000', QuantiteLisible::texte(1000.0, 'unite'));
    }

    public function testNombreAvecVirguleSansZerosInutiles(): void
    {
        self::assertSame('6,6', QuantiteLisible::nombre(6.60, 2));
        self::assertSame('2', QuantiteLisible::nombre(2.0, 1));
        self::assertSame('0,5', QuantiteLisible::nombre(0.5, 1));
    }
}
