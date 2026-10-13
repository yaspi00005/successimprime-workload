<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\Periode;
use PHPUnit\Framework\TestCase;

final class PeriodeTest extends TestCase
{
    public function testMoisDemandeJamaisDansLeFutur(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-13 15:20');

        self::assertSame('2026-09-01 00:00', Periode::moisDemande('2026-09', $maintenant)->format('Y-m-d H:i'));
        self::assertSame('2026-10-01', Periode::moisDemande('2027-01', $maintenant)->format('Y-m-d'));
        self::assertSame('2026-10-01', Periode::moisDemande('n\'importe quoi', $maintenant)->format('Y-m-d'));
        self::assertSame('2026-10-01', Periode::moisDemande('2026-13', $maintenant)->format('Y-m-d'));
    }

    public function testChoixDesDouzeDerniersMois(): void
    {
        $choix = Periode::choix(new \DateTimeImmutable('2026-01-31'));

        self::assertCount(12, $choix);
        self::assertSame('ce mois', $choix['2026-01']);
        self::assertSame('mois dernier', $choix['2025-12']);
        self::assertSame('novembre 2025', $choix['2025-11']);
        self::assertSame('2025-02', array_key_last($choix));
    }

    public function testBornesEtLibelles(): void
    {
        [$debut, $fin] = Periode::bornes(new \DateTimeImmutable('2026-02-17 10:00'));

        self::assertSame('2026-02-01 00:00', $debut->format('Y-m-d H:i'));
        self::assertSame('2026-03-01 00:00', $fin->format('Y-m-d H:i'));
        self::assertSame('février 2026', Periode::libelle($debut));
        self::assertSame('Mar. 20/10', Periode::jourCourt(new \DateTimeImmutable('2026-10-20')));
    }
}
