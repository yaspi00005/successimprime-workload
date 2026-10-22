<?php

namespace App\Tests\Unit\Service;

use App\Service\NumerotationFactures;
use PHPUnit\Framework\TestCase;

final class NumerotationFacturesTest extends TestCase
{
    public function testFormater(): void
    {
        $date = new \DateTimeImmutable('2026-10-10');

        self::assertSame('F-SI-2026-0112', NumerotationFactures::formater('F-SI-{AAAA}-{0000}', 112, $date));
        self::assertSame('MDG/26/10/007', NumerotationFactures::formater('MDG/{AA}/{MM}/{000}', 7, $date));
        // Le compteur dépasse le gabarit sans être tronqué.
        self::assertSame('D-12345', NumerotationFactures::formater('D-{000}', 12345, $date));
    }

    public function testSuivantRepartAZeroChaqueAnnee(): void
    {
        $date = new \DateTimeImmutable('2027-01-02');

        self::assertSame([1, 2027], NumerotationFactures::suivant('F-{AAAA}-{0000}', 412, 2026, $date));
        self::assertSame([413, 2027], NumerotationFactures::suivant('F-{AAAA}-{0000}', 412, 2027, $date));
        self::assertSame([1, 2027], NumerotationFactures::suivant('F-{AA}-{0000}', 0, null, $date));
    }

    public function testSuivantSansAnneeContinue(): void
    {
        self::assertSame([413, null], NumerotationFactures::suivant('F-{00000}', 412, 2026, new \DateTimeImmutable('2027-01-02')));
    }

    public function testFormatValide(): void
    {
        self::assertTrue(NumerotationFactures::formatValide('F-SI-{AAAA}-{0000}'));
        self::assertFalse(NumerotationFactures::formatValide('F-SI-{AAAA}'));
        self::assertFalse(NumerotationFactures::formatValide('F SI {0000}'));
        self::assertFalse(NumerotationFactures::formatValide('<b>{0000}</b>'));
    }
}
