<?php

namespace App\Tests\Unit\Service;

use App\Service\PiecesParMetreCarre;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pièces au m² (demande de Yaya du 16/10) : pièces de L × H mm sur 1 m × 1 m,
 * espacées de 1 mm en X et en Y ; n = plancher((1 000 + e) / (L + e)).
 */
final class PiecesParMetreCarreTest extends TestCase
{
    public function testCinqCentimetresDeCote(): void
    {
        $calcul = PiecesParMetreCarre::calculer(50, 50);

        // 19 × 50 + 18 × 1 = 968 mm ; 20 pièces demanderaient 1 019 mm.
        self::assertSame(['parM2' => 361, 'colonnes' => 19, 'rangees' => 19, 'tournee' => false, 'tropGrande' => false, 'espacement' => 1.0], $calcul);
    }

    public function testCarteDeVisiteDansLesDeuxSens(): void
    {
        $droite = PiecesParMetreCarre::calculer(85, 55);
        $tournee = PiecesParMetreCarre::calculer(55, 85);

        // 85 × 55 : 11 colonnes × 17 rangées = 187 ; tournée : 17 × 11, même nombre.
        self::assertSame(187, $droite['parM2']);
        self::assertSame([11, 17], [$droite['colonnes'], $droite['rangees']]);
        self::assertSame(187, $tournee['parM2']);
        self::assertSame([17, 11], [$tournee['colonnes'], $tournee['rangees']]);
        self::assertFalse($droite['tournee'], 'à égalité, la pièce reste dans le sens saisi');
    }

    public function testLaPieceTourneeEstGardeeQuandElleDonnePlus(): void
    {
        // Sur une plaque de 1 000 × 600 mm, 300 × 500 donne 3 × 1 = 3 ; tournée, 500 × 300 donne 2 × 2 = 4.
        $calcul = PiecesParMetreCarre::calculer(300, 500, 0, 1000, 600);

        self::assertTrue($calcul['tournee']);
        self::assertSame(4, $calcul['parM2']);
        self::assertSame([2, 2], [$calcul['colonnes'], $calcul['rangees']]);
    }

    public function testUnMetreTientJusteEtUnMillimetreDePlusNon(): void
    {
        self::assertSame(1, PiecesParMetreCarre::calculer(1000, 1000)['parM2']);

        $trop = PiecesParMetreCarre::calculer(1001, 10);
        self::assertSame(0, $trop['parM2']);
        self::assertTrue($trop['tropGrande']);
        self::assertSame('pièce plus grande que 1 m', PiecesParMetreCarre::libelle($trop));
    }

    public function testSansEspacementEtAvecUnEspacementPlusLarge(): void
    {
        self::assertSame(400, PiecesParMetreCarre::calculer(50, 50, 0)['parM2'], '20 × 20 sans espacement');
        // 3 mm : plancher(1 003 / 53) = 18.
        self::assertSame(324, PiecesParMetreCarre::calculer(50, 50, 3)['parM2']);
        // Décimales : 1,5 mm, plancher(1 001,5 / 51,5) = 19.
        self::assertSame(361, PiecesParMetreCarre::calculer(50, 50, 1.5)['parM2']);
    }

    public function testCoteJusteAuMillimetrePres(): void
    {
        // 10 pièces de 99 mm + 9 espaces = 999 mm : elles tiennent, sans arrondi qui en perde une.
        self::assertSame(10, PiecesParMetreCarre::calculer(99, 1000)['colonnes']);
        self::assertSame(10, PiecesParMetreCarre::calculer(99.1, 1000)['colonnes']);
        self::assertSame(9, PiecesParMetreCarre::calculer(99.2, 1000)['colonnes']);
    }

    /** @return iterable<string, array{float, float, float}> */
    public static function saisiesFausses(): iterable
    {
        yield 'largeur nulle' => [0, 50, 1];
        yield 'hauteur négative' => [50, -5, 1];
        yield 'espacement négatif' => [50, 50, -1];
    }

    #[DataProvider('saisiesFausses')]
    public function testSaisieFausseRefusee(float $largeur, float $hauteur, float $espacement): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PiecesParMetreCarre::calculer($largeur, $hauteur, $espacement);
    }

    public function testSurfaceFactureeAuCentiemeSuperieur(): void
    {
        self::assertSame(2.78, PiecesParMetreCarre::surface(1000, 361), '1 000 ÷ 361 = 2,770… → 2,78');
        self::assertSame(2.0, PiecesParMetreCarre::surface(722, 361), 'juste 2 m² : pas d\'arrondi en plus');
        self::assertSame(0.01, PiecesParMetreCarre::surface(1, 361));
        self::assertSame(3.0, PiecesParMetreCarre::surface(1083, 361));
        self::assertSame(0.0, PiecesParMetreCarre::surface(0, 361));
        self::assertSame(5.35, PiecesParMetreCarre::surface(1000, 187));
    }

    public function testPiecesPourUneSurface(): void
    {
        self::assertSame(1083, PiecesParMetreCarre::pieces(3, 361));
        self::assertSame(902, PiecesParMetreCarre::pieces(2.5, 361), '902,5 → 902');
        self::assertSame(561, PiecesParMetreCarre::pieces(3, 187));
        self::assertSame(0, PiecesParMetreCarre::pieces(0.5, 1), 'moins d\'une pièce');
        self::assertSame(0, PiecesParMetreCarre::pieces(0, 361));
        self::assertSame(0, PiecesParMetreCarre::pieces(-2, 361));
        // 0,29 × 100 = 28,999… en virgule flottante : 29 pièces quand même.
        self::assertSame(29, PiecesParMetreCarre::pieces(0.29, 100));
    }

    public function testAucunePieceSurUnMetreCarreRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PiecesParMetreCarre::surface(10, 0);
    }

    public function testLeCalculRefaitLaSurfaceDeLaSaisie(): void
    {
        // Les m² tapés redonnent des pièces, qui redonnent au plus ces m².
        foreach ([[3.0, 361], [2.5, 361], [10.0, 187], [0.37, 1600]] as [$m2, $parM2]) {
            self::assertLessThanOrEqual($m2, PiecesParMetreCarre::surface(PiecesParMetreCarre::pieces($m2, $parM2), $parM2));
        }
    }

    public function testDimensionsEnMetresCommeEnBase(): void
    {
        self::assertSame(361, PiecesParMetreCarre::pourDimensionsEnMetres(0.05, 0.05, 1)['parM2'] ?? null);
        self::assertSame(187, PiecesParMetreCarre::pourDimensionsEnMetres(0.085, 0.055, 1)['parM2'] ?? null);
        self::assertNull(PiecesParMetreCarre::pourDimensionsEnMetres(null, 0.05, 1));
        self::assertNull(PiecesParMetreCarre::pourDimensionsEnMetres(0.05, 0.0, 1));
        self::assertTrue(PiecesParMetreCarre::pourDimensionsEnMetres(1.2, 0.5, 1)['tropGrande'] ?? false);
    }

    public function testEspacementLuDansLesReglages(): void
    {
        self::assertSame(1.0, PiecesParMetreCarre::espacementValide(null), 'pas de réglage : 1 mm');
        self::assertSame(1.0, PiecesParMetreCarre::espacementValide('abc'));
        self::assertSame(1.0, PiecesParMetreCarre::espacementValide(-3));
        self::assertSame(0.0, PiecesParMetreCarre::espacementValide('0'));
        self::assertSame(2.5, PiecesParMetreCarre::espacementValide('2,5'));
        self::assertSame(1.3, PiecesParMetreCarre::espacementValide(1.26));
        self::assertSame(PiecesParMetreCarre::ESPACEMENT_MAX, PiecesParMetreCarre::espacementValide(500));
    }

    public function testLibelle(): void
    {
        self::assertSame('361 pièces au m² (19 × 19, espacement 1 mm)', PiecesParMetreCarre::libelle(PiecesParMetreCarre::calculer(50, 50)));
        self::assertSame("1\u{a0}600 pièces au m² (40 × 40, espacement 0,5 mm)", PiecesParMetreCarre::libelle(PiecesParMetreCarre::calculer(24.5, 24.5, 0.5)));
        self::assertSame('1 pièce au m² (1 × 1, espacement 1 mm)', PiecesParMetreCarre::libelle(PiecesParMetreCarre::calculer(600, 600)));
    }
}
