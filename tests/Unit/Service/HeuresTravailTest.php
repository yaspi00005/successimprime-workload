<?php

namespace App\Tests\Unit\Service;

use App\Service\ActionsCaisse\HeuresTravail;
use PHPUnit\Framework\TestCase;

final class HeuresTravailTest extends TestCase
{
    private static function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testExemplesDeLaSpecification(): void
    {
        $h = new HeuresTravail();

        // Samedi 17:00 → 1 h samedi + 3 h lundi.
        self::assertSame('2026-10-12 11:00', $h->ajouter(self::t('2026-10-10 17:00'), 240)->format('Y-m-d H:i'));
        // Vendredi 16:30 → 1 h 30 vendredi + 2 h 30 samedi.
        self::assertSame('2026-10-10 10:30', $h->ajouter(self::t('2026-10-09 16:30'), 240)->format('Y-m-d H:i'));
        // Lundi 09:40 → la pause de 13 h à 14 h ne compte pas.
        self::assertSame('2026-10-12 14:40', $h->ajouter(self::t('2026-10-12 09:40'), 240)->format('Y-m-d H:i'));
    }

    public function testHorsHeuresDemarreALaProchaineOuverture(): void
    {
        $h = new HeuresTravail();

        // Dimanche soir → lundi 8 h + 4 h, pause comprise.
        self::assertSame('2026-10-12 12:00', $h->ajouter(self::t('2026-10-11 21:00'), 240)->format('Y-m-d H:i'));
        // Pile à la fermeture : l'échéance reste à 18:00.
        self::assertSame('2026-10-12 18:00', $h->ajouter(self::t('2026-10-12 14:00'), 240)->format('Y-m-d H:i'));
    }

    public function testJoursFeries(): void
    {
        // 22/09 fête nationale, 27/05/2026 date propre à l'année.
        $h = new HeuresTravail(feries: '22/09, 27/05/2026');

        self::assertFalse($h->estOuvre(self::t('2026-09-22')));
        self::assertFalse($h->estOuvre(self::t('2026-05-27')));
        self::assertTrue($h->estOuvre(self::t('2027-05-27')));
        self::assertSame('2026-09-23 10:00', $h->ajouter(self::t('2026-09-21 17:00'), 180)->format('Y-m-d H:i'));
        self::assertNull(HeuresTravail::lireDates('31/02'));
        self::assertSame(['01-01', '2026-05-27'], HeuresTravail::lireDates('1/1, 27/05/2026 01/01'));
    }

    public function testTempsEcoule(): void
    {
        $h = new HeuresTravail();

        self::assertSame(209, $h->ecoule(self::t('2026-10-10 17:00'), self::t('2026-10-12 10:29')), '1 h samedi + 2 h 29 lundi');
        self::assertSame(0, $h->ecoule(self::t('2026-10-11 09:00'), self::t('2026-10-11 17:00')), 'dimanche');
        self::assertSame(60, $h->ecoule(self::t('2026-10-12 12:30'), self::t('2026-10-12 14:30')), 'pause retirée');
    }
}
