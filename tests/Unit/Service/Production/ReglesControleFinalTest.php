<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ReglesControleFinal;
use PHPUnit\Framework\TestCase;

/**
 * Règles du contrôle final (PD06, docs/production-colis.md) : œillets,
 * taille mesurée, passage à « Prête ».
 */
final class ReglesControleFinalTest extends TestCase
{
    public function testEspacementLuDansLaFinition(): void
    {
        self::assertSame(50, ReglesControleFinal::espacementOeillets('Œillets tous les 50 cm'));
        self::assertSame(30, ReglesControleFinal::espacementOeillets('Oeillets 30cm'));
        self::assertSame(50, ReglesControleFinal::espacementOeillets('Œillets'));
        self::assertNull(ReglesControleFinal::espacementOeillets('Ourlet'));
        self::assertNull(ReglesControleFinal::espacementOeillets(null));
    }

    public function testSeizeOeilletsSurUneBacheDeTroisMetresParUn(): void
    {
        self::assertSame(16, ReglesControleFinal::oeilletsParPiece(300, 100, 50));
        self::assertSame(12, ReglesControleFinal::oeilletsParPiece(85, 200, 50));
        self::assertSame(8, ReglesControleFinal::oeilletsParPiece(100, 100, 50));
        self::assertSame(0, ReglesControleFinal::oeilletsParPiece(0, 100, 50));
    }

    public function testTailleMesuree(): void
    {
        // 300 cm : 1 % = 3 cm de tolérance.
        self::assertFalse(ReglesControleFinal::tailleHorsTolerance(300, 301.5));
        self::assertFalse(ReglesControleFinal::tailleHorsTolerance(300, 297));
        self::assertTrue(ReglesControleFinal::tailleHorsTolerance(300, 304));
        // 85 cm : 1 cm au moins.
        self::assertFalse(ReglesControleFinal::tailleHorsTolerance(85, 85.9));
        self::assertTrue(ReglesControleFinal::tailleHorsTolerance(85, 86.5));
    }

    public function testCouleurASurveillerLueDansLesNotes(): void
    {
        self::assertSame('rouge', ReglesControleFinal::couleurSensible('Le rouge Awa Mode doit être identique au BAT.'));
        self::assertSame('Pantone 186 C', ReglesControleFinal::couleurSensible(null, 'Logo en pantone 186 c, fond bleu'));
        self::assertSame('vert', ReglesControleFinal::couleurSensible('Croix verte ? non : le vert de la croix', 'bleu roi'), 'la première couleur citée');
        self::assertNull(ReglesControleFinal::couleurSensible('Rougeâtre interdit, livrer à Bleuet'), 'pas de couleur dans un autre mot');
        self::assertNull(ReglesControleFinal::couleurSensible(null, '', '  '));
    }

    public function testLaCommandePassePreteAuDernierGeste(): void
    {
        self::assertTrue(ReglesControleFinal::commandePrete(true, 2, 2));
        self::assertFalse(ReglesControleFinal::commandePrete(true, 2, 1), 'un colis pas encore scanné');
        self::assertFalse(ReglesControleFinal::commandePrete(false, 2, 2), 'contrôle pas validé');
        self::assertFalse(ReglesControleFinal::commandePrete(true, 0, 0), 'aucune étiquette imprimée');
    }
}
