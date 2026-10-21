<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\NomFichierProduction;
use PHPUnit\Framework\TestCase;

final class NomFichierProductionTest extends TestCase
{
    public function testExempleDeLaMaquettePd01(): void
    {
        // Bâches d'Awa Diarra : commande CMD-000183-10-2026, ligne 1, 300 × 100 cm × 2, œillets tous les 50 cm.
        $nom = NomFichierProduction::nom('CMD-000183-10-2026', 1, NomFichierProduction::code('Bâche 440 g frontlit'), 300.0, 100.0, 2, [NomFichierProduction::code('Œillets tous les 50 cm')]);

        self::assertSame('CMD-000183_L1_BACHE440_300x100_x2_OEIL50.pdf', $nom);
        self::assertSame('CMD-000183_L1_BACHE440_300x100_x2_OEIL50_decoupe.svg', NomFichierProduction::nomDecoupe($nom, 'svg'));
    }

    public function testCodesTiresDesNoms(): void
    {
        self::assertSame('BACHE440', NomFichierProduction::code('Bâche 440 g frontlit'));
        self::assertSame('BACHE510', NomFichierProduction::code('Bâche 510 g frontlit · laize 3,20 m'));
        self::assertSame('OEIL50', NomFichierProduction::code('Œillets tous les 50 cm'));
        self::assertSame('OURLET', NomFichierProduction::code('Ourlet'));
        self::assertSame('FOURREAU', NomFichierProduction::code('Fourreau haut et bas'));
        self::assertSame('TSHIRT', NomFichierProduction::code('T-shirt coton'));
        self::assertSame('MICROPERFORE', NomFichierProduction::code('Micro-perforé'));
        self::assertSame('', NomFichierProduction::code(''));
    }

    public function testCodeRegleALaMainPrioritaire(): void
    {
        self::assertSame('FRONT440', NomFichierProduction::codeRegle(' front 440 ', 'Bâche 440 g frontlit'));
        self::assertSame('BACHE440', NomFichierProduction::codeRegle(null, 'Bâche 440 g frontlit'));
        self::assertSame('BACHE440', NomFichierProduction::codeRegle('  ', 'Bâche 440 g frontlit'));
    }

    public function testPlusieursFinitionsSansDoublonEtExtensionDuSource(): void
    {
        $nom = NomFichierProduction::nom('CMD-D00766', 2, 'BACHE440', 85.5, 200.0, 1, ['OURLET', 'OEIL50', 'OURLET'], 'PNG');

        self::assertSame('CMD-D00766_L2_BACHE440_85,5x200_x1_OURLET-OEIL50.png', $nom);
    }

    public function testSansSupportNiTaille(): void
    {
        self::assertSame('CMD-000012_L3_x500.pdf', NomFichierProduction::nom('CMD-000012', 3, null, null, null, 500));
    }

    public function testNomSurPourWindowsEtCoupeA150Caracteres(): void
    {
        $codes = array_fill(0, 30, 'FINITIONTRESLONGUE');
        $codes = array_map(static fn (string $c, int $i): string => $c.$i, $codes, array_keys($codes));
        $nom = NomFichierProduction::nom('CMD-000183', 1, 'BACHE440', 300.0, 100.0, 2, $codes);

        self::assertLessThanOrEqual(150, \strlen($nom));
        self::assertStringEndsWith('.pdf', $nom);
        self::assertDoesNotMatchRegularExpression('/[\\\\\/:*?"<>| ]/', $nom);
        self::assertSame('CMD-000183', NomFichierProduction::numeroCourt('cmd-000183:-10-2026'));
    }

    public function testRangDeLaLigneTrieeParId(): void
    {
        self::assertSame(1, NomFichierProduction::rang([905, 902, 910], 902));
        self::assertSame(3, NomFichierProduction::rang([905, 902, 910], 910));
        self::assertSame(1, NomFichierProduction::rang([], 4));
    }

    public function testPartiesPourLesReglesDeNommage(): void
    {
        $parties = NomFichierProduction::parties('CMD-000183_L1_BACHE440_300x100_x2_OEIL50.pdf');

        self::assertSame(['commande', 'ligne', 'support', 'taille', 'quantite', 'finition'], array_column($parties, 'role'));
        self::assertSame('decoupe', NomFichierProduction::parties('CMD-1_L1_x1_decoupe.svg')[3]['role']);
    }
}
