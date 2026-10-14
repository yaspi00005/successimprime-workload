<?php

namespace App\Tests\Unit\Service\Immobilisations;

use App\Service\Immobilisations\CalculAmortissement;
use PHPUnit\Framework\TestCase;

final class CalculAmortissementTest extends TestCase
{
    public function testCoutParUniteEtCumulALUsage(): void
    {
        // Spécification : 18 000 000 F, 0 F résiduel, 60 000 m² → 300 F / m².
        self::assertSame(300.0, CalculAmortissement::coutUnitaire(18_000_000, 60_000));
        self::assertNull(CalculAmortissement::coutUnitaire(18_000_000, null));
        self::assertNull(CalculAmortissement::coutUnitaire(18_000_000, 0.0));

        // Canon : (4 200 000 − 200 000) ÷ 600 000 pages = 6,67 F la page A4.
        $cu = CalculAmortissement::coutUnitaire(4_000_000, 600_000);
        self::assertSame(1_226_667, CalculAmortissement::cumulUsage(184_000, $cu, 4_000_000));
        self::assertSame(78_333, CalculAmortissement::cumulUsage(11_750, $cu, 4_000_000));
        self::assertSame(0, CalculAmortissement::cumulUsage(-5, $cu, 4_000_000));
    }

    public function testLeCumulALUsageSArreteALaValeurResiduelle(): void
    {
        // Base 4 000 000 : au-delà de la capacité, le cumul reste à la base.
        $cu = CalculAmortissement::coutUnitaire(4_000_000, 600_000);
        $avant = CalculAmortissement::cumulUsage(599_000, $cu, 4_000_000);
        $apres = CalculAmortissement::cumulUsage(650_000, $cu, 4_000_000);
        self::assertSame(4_000_000, $apres);
        self::assertSame(4_000_000 - $avant, CalculAmortissement::dotation($apres, $avant));
        self::assertSame(0, CalculAmortissement::dotation(CalculAmortissement::cumulUsage(700_000, $cu, 4_000_000), $apres));
        // Valeur nette = achat − cumul : 4 200 000 − 4 000 000 = la valeur résiduelle.
        self::assertSame(200_000, CalculAmortissement::valeurNette(4_200_000, $apres));
    }

    public function testLineaireLeMoisDeMiseEnServiceCompte(): void
    {
        $mise = new \DateTimeImmutable('2026-10-03');
        self::assertSame(0, CalculAmortissement::moisAmortis($mise, new \DateTimeImmutable('2026-09-01')));
        self::assertSame(1, CalculAmortissement::moisAmortis($mise, new \DateTimeImmutable('2026-10-01')));
        self::assertSame(15, CalculAmortissement::moisAmortis($mise, new \DateTimeImmutable('2027-12-01')));
        self::assertSame(0, CalculAmortissement::moisAmortis(null, new \DateTimeImmutable('2027-12-01')));

        // Serveur local : 1 200 000 F sur 4 ans, mis en service en octobre → 25 000 F en octobre.
        self::assertSame(25_000, CalculAmortissement::cumulLineaire(1_200_000, 48, $mise, new \DateTimeImmutable('2026-10-01')));
    }

    public function testLaDotationLineaireMensuelleSArreteAuBoutDeLaDuree(): void
    {
        $mise = new \DateTimeImmutable('2024-03-15');
        $base = 880_000 - 0;
        $total = 0;
        $mois = new \DateTimeImmutable('2024-03-01');
        $precedent = 0;
        for ($i = 0; $i < 70; ++$i) {
            $cumul = CalculAmortissement::cumulLineaire($base, 60, $mise, $mois);
            $total += CalculAmortissement::dotation($cumul, $precedent);
            $precedent = $cumul;
            $mois = $mois->modify('+1 month');
        }
        // Somme des dotations = montant à amortir, au franc près, puis plus rien.
        self::assertSame($base, $total);
        self::assertSame($base, CalculAmortissement::cumulLineaire($base, 60, $mise, new \DateTimeImmutable('2031-01-01')));
        self::assertSame(14_667, CalculAmortissement::cumulLineaire($base, 60, $mise, new \DateTimeImmutable('2024-03-01')));
    }

    public function testPlusOuMoinsValueDeCession(): void
    {
        self::assertSame(150_000, CalculAmortissement::resultatCession(450_000, 300_000));
        self::assertSame(-300_000, CalculAmortissement::resultatCession(0, 300_000));
    }

    public function testLibelleDeLaMethode(): void
    {
        self::assertSame('Unités (m²)', CalculAmortissement::libelleMethode('usage', 'm²', 60));
        self::assertSame('Linéaire 4 ans', CalculAmortissement::libelleMethode('lineaire', null, 48));
        self::assertSame('Linéaire 1 an', CalculAmortissement::libelleMethode('lineaire', null, 12));
        self::assertSame('Linéaire 18 mois', CalculAmortissement::libelleMethode('lineaire', null, 18));
    }
}
