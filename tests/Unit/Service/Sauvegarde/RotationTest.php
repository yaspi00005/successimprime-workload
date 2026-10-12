<?php

namespace App\Tests\Unit\Service\Sauvegarde;

use App\Service\Sauvegarde\Rotation;
use PHPUnit\Framework\TestCase;

final class RotationTest extends TestCase
{
    /** Une sauvegarde toutes les 6 h pendant $jours jours avant $fin. */
    private static function serie(\DateTimeImmutable $fin, int $jours): array
    {
        $noms = [];
        for ($d = $fin->modify('-'.$jours.' days'); $d <= $fin; $d = $d->modify('+6 hours')) {
            $noms[] = $d->format('Ymd-His');
        }

        return $noms;
    }

    public function testGardeToutSur48hPuisUneParJourSemaineMois(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-12 20:00');
        $noms = self::serie($maintenant->setTime(18, 0), 500);
        $garder = Rotation::aGarder($noms, $maintenant, 14, 8, 12);

        // 48 h à raison de 4 par jour : les 9 dernières (12/10 18 h → 10/10 18 h... incluses)
        $recentes = array_filter($garder, static fn ($n) => Rotation::date($n) >= $maintenant->modify('-48 hours'));
        self::assertCount(8, $recentes);

        $jours = array_unique(array_map(static fn ($n) => substr($n, 0, 8), $garder));
        self::assertContains('20260929', $jours, 'le 14e jour est gardé');
        self::assertNotContains('20260928', array_map(static fn ($n) => substr($n, 0, 8), array_filter($garder, static fn ($n) => !str_ends_with($n, '-180000'))));

        // La plus récente de chaque jour, pas les autres.
        self::assertContains('20261001-180000', $garder);
        self::assertNotContains('20261001-120000', $garder);

        // Un mois ancien n'a plus qu'une sauvegarde, la plus récente du mois.
        $mars = array_values(array_filter($garder, static fn ($n) => str_starts_with($n, '202603')));
        self::assertSame(['20260331-180000'], $mars);

        // Rien de plus vieux que 12 mois.
        self::assertSame([], array_filter($garder, static fn ($n) => $n < '20251101'));
        self::assertLessThan(14 + 8 + 12 + 8, \count($garder));
    }

    public function testLaDerniereEstToujoursGardee(): void
    {
        $garder = Rotation::aGarder(['20200101-020000', '20190101-020000'], new \DateTimeImmutable('2026-10-12'), 14, 0, 0);
        self::assertSame(['20200101-020000'], $garder);
    }

    public function testIgnoreLesNomsInconnus(): void
    {
        self::assertSame([], Rotation::aGarder(['journal.json', '20261012-180000.en-cours'], new \DateTimeImmutable(), 14, 8, 12));
        self::assertNull(Rotation::date('2026-10-12'));
        self::assertSame('2026-10-12 18:06:17', Rotation::date('20261012-180617')?->format('Y-m-d H:i:s'));
    }
}
