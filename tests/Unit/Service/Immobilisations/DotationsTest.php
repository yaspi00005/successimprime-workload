<?php

namespace App\Tests\Unit\Service\Immobilisations;

use App\Entity\DotationAmortissement;
use App\Entity\Format;
use App\Entity\Immobilisation;
use App\Entity\Machines;
use App\Entity\ReleveCompteur;
use App\Service\Immobilisations\CalculAmortissement;
use App\Service\Immobilisations\Patrimoine;
use App\Service\Immobilisations\UsageMachines;
use App\Service\JournalComptable;
use PHPUnit\Framework\TestCase;

/**
 * Dotations mensuelles (M01, M04) : calcul du mois, arrêt à la valeur
 * résiduelle, sortie du bien, écriture SYSCOHADA 681 / 28x.
 */
final class DotationsTest extends TestCase
{
    /** Machine à l'usage : la dotation suit l'usage du mois puis s'arrête à la base (prix − valeur résiduelle). */
    public function testDotationALUsageSArreteALaValeurResiduelle(): void
    {
        // Compteur actuel 100 000 m² ; production de juillet, août et septembre.
        $suivi = ['releves' => [], 'production' => ['2026-07' => 1_000.0, '2026-08' => 2_000.0, '2026-09' => 3_000.0], 'compteur' => 100_000.0];

        // 20 F par m² : 97 000 m² fin août valent 1 940 000 F, au-delà de la base.
        $aout = Patrimoine::calculer(1_200_000, 1_000_000, CalculAmortissement::USAGE, 20.0, 60, new \DateTimeImmutable('2020-01-01'), null, $suivi, new \DateTimeImmutable('2026-08-01'));
        self::assertSame(2_000.0, $aout['usageMois']);
        self::assertSame(0, $aout['dotation'], 'base déjà amortie : plus de dotation');
        self::assertSame(1_000_000, $aout['cumul']);
        self::assertSame(200_000, $aout['valeurNette'], 'la valeur nette ne descend pas sous la valeur résiduelle');

        // 10 F par m² : 99 000 m² fin septembre, la base est atteinte à 100 000 m².
        $jeune = ['releves' => [], 'production' => ['2026-08' => 2_000.0, '2026-09' => 3_000.0], 'compteur' => 99_000.0];
        $septembre = Patrimoine::calculer(1_200_000, 1_000_000, CalculAmortissement::USAGE, 10.0, 60, null, null, $jeune, new \DateTimeImmutable('2026-09-01'));
        self::assertSame(3_000.0, $septembre['usageMois']);
        self::assertSame(30_000, $septembre['dotation']);
        self::assertSame(990_000, $septembre['cumul']);
        $octobre = Patrimoine::calculer(1_200_000, 1_000_000, CalculAmortissement::USAGE, 10.0, 60, null, null, ['production' => ['2026-10' => 5_000.0], 'compteur' => 104_000.0, 'releves' => []], new \DateTimeImmutable('2026-10-01'));
        self::assertSame(10_000, $octobre['dotation'], 'seuls les 10 000 F restants avant la valeur résiduelle');
    }

    /** Linéaire : la même dotation chaque mois, puis 0 au bout de la durée. */
    public function testDotationLineaireMensuelle(): void
    {
        $mise = new \DateTimeImmutable('2024-10-15');
        $mois = Patrimoine::calculer(1_450_000, 1_440_000, CalculAmortissement::LINEAIRE, null, 36, $mise, null, null, new \DateTimeImmutable('2026-09-01'));
        self::assertSame(40_000, $mois['dotation']);
        self::assertSame('lineaire', $mois['methode']);

        $fini = Patrimoine::calculer(1_450_000, 1_440_000, CalculAmortissement::LINEAIRE, null, 36, $mise, null, null, new \DateTimeImmutable('2027-10-01'));
        self::assertSame(0, $fini['dotation']);
        self::assertSame(1_440_000, $fini['cumul']);
        self::assertSame(10_000, $fini['valeurNette']);
    }

    /** Un bien vendu garde le cumul de son mois de sortie. */
    public function testUnBienSortiNeSeDotePlus(): void
    {
        $mise = new \DateTimeImmutable('2025-01-01');
        $apres = Patrimoine::calculer(600_000, 600_000, CalculAmortissement::LINEAIRE, null, 60, $mise, new \DateTimeImmutable('2026-03-20'), null, new \DateTimeImmutable('2026-09-01'));

        self::assertSame(150_000, $apres['cumul'], '15 mois de janvier 2025 à mars 2026');
        self::assertSame(0, $apres['dotation']);
    }

    /** Relevés A4 / A3 : une page A3 compte pour 2 pages A4, et le relevé fait foi sur la production. */
    public function testReleveDeCompteursA4A3(): void
    {
        $canon = (new Machines())->setUnite(Machines::UNITE_PAGE)->setReleveCompteurs(Machines::RELEVE_A4A3);
        $aout = (new ReleveCompteur($canon, new \DateTimeImmutable('2026-08-01')))->saisirA4A3(['a4Couleur' => 10_000, 'a4Noir' => 20_000, 'a3Couleur' => 1_000, 'a3Noir' => 500]);
        $septembre = (new ReleveCompteur($canon, new \DateTimeImmutable('2026-09-01')))->saisirA4A3(['a4Couleur' => 17_380, 'a4Noir' => 29_976, 'a3Couleur' => 1_773, 'a3Noir' => 708]);

        self::assertSame(33_000.0, $aout->equivalent());
        $suivi = ['releves' => ['2026-08' => $aout, '2026-09' => $septembre], 'production' => ['2026-09' => 1.0], 'compteur' => 52_318.0];
        self::assertSame(19_318.0, UsageMachines::duMois($suivi, new \DateTimeImmutable('2026-09-01')));
    }

    /** Coût complet d'une page (M02) : amortissement + toner + entretien, doublé pour l'A3. */
    public function testCoutCompletParPage(): void
    {
        $a3 = Patrimoine::coutComplet('A3 couleur', 2, 5.0, 38.0, 1.2);
        self::assertSame(['format' => 'A3 couleur', 'amort' => 10.0, 'toner' => 76.0, 'entretien' => 2.4, 'total' => 88.4], $a3);
        self::assertNull(Patrimoine::coutComplet('A4', 1, null, null, null)['total']);
    }

    /** Sans dimensions sur la ligne, la surface vient du format (flyer A5, carte 85 × 55). */
    public function testSurfaceDUnePiece(): void
    {
        self::assertEqualsWithDelta(0.03108, UsageMachines::surfacePiece(null, null, '14.80', '21.00', 'cm'), 0.00001);
        self::assertEqualsWithDelta(0.098, UsageMachines::surfacePiece('0.28', '0.35', '0', '0', 'cm'), 0.00001);
        self::assertSame(0.0, UsageMachines::surfacePiece(null, null, '0.00', '0.00', 'cm'), 'format « Taille unique »');
        self::assertEqualsWithDelta(0.06237, Format::surfaceDe(210, 297, 'mm'), 0.00001);
    }

    /** Écriture SYSCOHADA : 681 au débit, 28x de la catégorie au crédit, une pièce par mois. */
    public function testEcritureSyscohadaDesDotations(): void
    {
        $septembre = new \DateTimeImmutable('2026-09-01');
        $machine = new Immobilisation('IMM-0001', 'Solvant 3,20 m', 'machine');
        $pc = new Immobilisation('IMM-0009', 'iMac PAO', 'informatique');
        $chaises = new Immobilisation('IMM-0015', 'Chaises', 'chaise');
        $le = new \DateTimeImmutable('2026-10-01');
        $dotations = [
            new DotationAmortissement($machine, $septembre, 100_602, 2_000_000, CalculAmortissement::USAGE, 373.0, $le),
            new DotationAmortissement($pc, $septembre, 40_000, 960_000, CalculAmortissement::LINEAIRE, null, $le),
            new DotationAmortissement($chaises, $septembre, 6_000, 90_000, CalculAmortissement::LINEAIRE, null, $le),
            new DotationAmortissement(new Immobilisation('IMM-0020', 'Licence', 'logiciel'), $septembre, 0, 1_100_000, CalculAmortissement::LINEAIRE, null, $le),
        ];

        $lignes = JournalComptable::ecrituresDotations($dotations);

        self::assertCount(4, $lignes, 'machine (2841) et mobilier/informatique (2844) ; la dotation à 0 n\'écrit rien');
        self::assertSame(['681', '2841', '681', '2844'], array_column($lignes, 'compte'));
        self::assertSame(146_602, array_sum(array_column($lignes, 'debit')));
        self::assertSame(146_602, array_sum(array_column($lignes, 'credit')), 'écriture équilibrée');
        self::assertSame(46_000, $lignes[3]['credit'], 'iMac et chaises sur le même 2844');
        self::assertSame('DOT-2026-09', $lignes[0]['piece']);
        self::assertSame('2026-09-30', $lignes[0]['date']->format('Y-m-d'));
        self::assertSame('681', $lignes[2]['compte']);
        self::assertSame(46_000, $lignes[2]['debit']);
        self::assertSame('2841', $machine->compteAmortissement());
        self::assertSame('2411', $machine->compteImmobilisation());
    }
}
