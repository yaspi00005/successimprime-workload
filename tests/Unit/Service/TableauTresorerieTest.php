<?php

namespace App\Tests\Unit\Service;

use App\Entity\DecaissementRecurrent;
use App\Service\TableauTresorerie;
use PHPUnit\Framework\TestCase;

final class TableauTresorerieTest extends TestCase
{
    public function testCourbeReconstitueLePasseEtProjetteLAvenir(): void
    {
        $aujourdhui = new \DateTimeImmutable('2026-10-11');
        $points = TableauTresorerie::courbe(
            100000,
            ['2026-10-11' => 30000, '2026-10-10' => -10000],
            ['2026-10-13' => 50000],
            [['date' => new \DateTimeImmutable('2026-10-14'), 'montant' => 20000, 'libelle' => 'Loyer']],
            $aujourdhui,
        );

        $parJour = [];
        foreach ($points as $p) {
            $parJour[$p['date']->format('Y-m-d')] = $p['solde'];
        }
        self::assertCount(TableauTresorerie::JOURS_PASSES + 1 + TableauTresorerie::JOURS_PREVUS, $points);
        self::assertSame(100000, $parJour['2026-10-11']);
        self::assertSame(70000, $parJour['2026-10-10']);
        self::assertSame(80000, $parJour['2026-10-09']);
        self::assertSame(150000, $parJour['2026-10-13']);
        self::assertSame(130000, $parJour['2026-10-14']);
    }

    public function testTraceCommenceAGaucheEtFinitADroite(): void
    {
        $points = [
            ['date' => new \DateTimeImmutable('2026-10-10'), 'solde' => 0, 'prevu' => false],
            ['date' => new \DateTimeImmutable('2026-10-11'), 'solde' => 100, 'prevu' => false],
            ['date' => new \DateTimeImmutable('2026-10-12'), 'solde' => 50, 'prevu' => true],
        ];
        $trace = TableauTresorerie::trace($points, 200, 100);
        self::assertSame('M0.0,100.0 L100.0,0.0', $trace['reel']);
        self::assertSame('M100.0,0.0 L200.0,50.0', $trace['prevu']);
        self::assertSame(100.0, $trace['x_aujourdhui']);
    }

    public function testEcheancesRecurrentes(): void
    {
        $dates = TableauTresorerie::echeancesRecurrentes(
            new \DateTimeImmutable('2026-09-05'),
            DecaissementRecurrent::FREQUENCE_MENSUELLE,
            new \DateTimeImmutable('2026-10-12'),
            new \DateTimeImmutable('2026-11-10'),
        );
        self::assertSame(['2026-11-05'], array_map(static fn ($d) => $d->format('Y-m-d'), $dates));
        self::assertSame([], TableauTresorerie::echeancesRecurrentes(null, DecaissementRecurrent::FREQUENCE_MENSUELLE, new \DateTimeImmutable(), new \DateTimeImmutable()));
    }
}
