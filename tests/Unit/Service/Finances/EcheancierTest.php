<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\Echeancier;
use App\Service\Finances\Periode;
use PHPUnit\Framework\TestCase;

final class EcheancierTest extends TestCase
{
    public function testTacheQuiRepeteUneChargeRecurrente(): void
    {
        $charges = [['date' => new \DateTimeImmutable('2026-10-30'), 'montant' => 450000]];

        // « Paiement du loyer » au planning le 05/11 = le loyer récurrent du 30/10.
        self::assertTrue(Echeancier::doublon(450000, new \DateTimeImmutable('2026-11-05'), $charges));
        self::assertTrue(Echeancier::doublon(450000, new \DateTimeImmutable('2026-10-23'), $charges));
        self::assertFalse(Echeancier::doublon(450000, new \DateTimeImmutable('2026-11-08'), $charges));
        self::assertFalse(Echeancier::doublon(350000, new \DateTimeImmutable('2026-10-30'), $charges));
    }

    public function testRegroupementParJourEtJourLePlusLourd(): void
    {
        $sorties = [
            self::sortie('2026-10-20', 'Textile Express', 220000),
            self::sortie('2026-10-23', 'Impôts', null),
            self::sortie('2026-11-01', 'Sahel Plast', 485000),
            self::sortie('2026-11-01', 'Encres Pro', 168000),
            self::sortie('2026-11-01', 'Bailleur', 350000, true),
        ];

        $jours = Echeancier::parJour($sorties);

        self::assertCount(3, $jours);
        self::assertTrue($jours[1]['selon_declaration']);
        self::assertSame(0, $jours[1]['total']);
        self::assertSame(1003000, $jours[2]['total']);
        self::assertTrue($jours[2]['en_retard']);
        self::assertCount(3, $jours[2]['lignes']);

        self::assertSame('2026-11-01', Echeancier::grosJour($jours)['date']->format('Y-m-d'));
        // Sans paiement groupé : la prochaine échéance.
        self::assertSame('2026-10-20', Echeancier::grosJour(Echeancier::parJour(\array_slice($sorties, 0, 2)))['date']->format('Y-m-d'));
        self::assertNull(Echeancier::grosJour([]));
    }

    public function testMoisAvecElision(): void
    {
        self::assertSame('d\'octobre', Periode::deMois(new \DateTimeImmutable('2026-10-01')));
        self::assertSame('d\'août', Periode::deMois(new \DateTimeImmutable('2026-08-15')));
        self::assertSame('de novembre', Periode::deMois(new \DateTimeImmutable('2026-11-01')));
    }

    /**
     * @return array{date: \DateTimeImmutable, titre: string, montant: ?int, en_retard: bool}
     */
    private static function sortie(string $date, string $titre, ?int $montant, bool $enRetard = false): array
    {
        return ['date' => new \DateTimeImmutable($date), 'titre' => $titre, 'montant' => $montant, 'en_retard' => $enRetard];
    }
}
