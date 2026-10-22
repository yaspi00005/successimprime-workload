<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ProgressionRouleau;
use PHPUnit\Framework\TestCase;

/**
 * Impression en cours (PD04) : avancement estimé entre deux « Pièce
 * imprimée », pauses retirées, vitesse réelle, encre, heure de fin.
 */
final class ProgressionRouleauTest extends TestCase
{
    private function t(string $heure): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-21 '.$heure);
    }

    /** La maquette : 5 pièces, 11,8 m², la 1re (3 m²) imprimée à 10:31, la 2e en cours. */
    private function pieces(): array
    {
        return [
            ['m2' => 3.0, 'statut' => 'imprimee', 'debut' => $this->t('10:24'), 'fin' => $this->t('10:31:19')],
            ['m2' => 1.7, 'statut' => 'en_cours', 'debut' => $this->t('10:31:19'), 'fin' => null],
            ['m2' => 1.7, 'statut' => 'a_imprimer', 'debut' => null, 'fin' => null],
            ['m2' => 2.7, 'statut' => 'a_imprimer', 'debut' => null, 'fin' => null],
            ['m2' => 2.7, 'statut' => 'a_imprimer', 'debut' => null, 'fin' => null],
        ];
    }

    public function testPieceEnCoursEstimeeEtFin(): void
    {
        $p = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), [], $this->t('10:35'), 25.0, 12.0);

        self::assertSame(5, $p['total']);
        self::assertSame(1, $p['faites']);
        self::assertSame(2, $p['courante']);
        self::assertSame(11.8, $p['m2Total']);
        self::assertSame(3.0, $p['m2Confirme']);
        // 3 m² confirmés + 3 min 41 s à 25 m²/h ≈ 1,53 m².
        self::assertEqualsWithDelta(4.53, $p['m2Fait'], 0.01);
        self::assertTrue($p['estime']);
        // 3 m² en 7 min 19 s : 24,6 m²/h, comme la maquette.
        self::assertSame(24.6, $p['vitesseReelle']);
        self::assertSame(24.6, $p['vitesse']);
        self::assertSame(54, $p['encreMl']);
        // 7,27 m² restants à 24,6 m²/h : 17 min 44 s.
        self::assertSame('10:52', $p['fin']->format('H:i'));
    }

    public function testLaPieceEnCoursNeDepassePasSaSurface(): void
    {
        $p = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), [], $this->t('11:30'), 25.0, null);

        self::assertSame(4.7, $p['m2Fait']);
        self::assertNull($p['encreMl']);
    }

    public function testLesPausesNeComptentPas(): void
    {
        $pauses = [['debut' => $this->t('10:32'), 'fin' => $this->t('10:42')]];
        $avec = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), $pauses, $this->t('10:45'), 25.0, 12.0);
        $sans = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), [], $this->t('10:35'), 25.0, 12.0);

        self::assertEqualsWithDelta($sans['m2Fait'], $avec['m2Fait'], 0.01);
        self::assertSame(11 * 60, $avec['secondes']);
    }

    public function testUnePauseEnCoursArreteLaPiece(): void
    {
        $pauses = [['debut' => $this->t('10:33'), 'fin' => null]];
        $a = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), $pauses, $this->t('10:40'), 25.0, 12.0);
        $b = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), $pauses, $this->t('11:40'), 25.0, 12.0);

        self::assertSame($a['m2Fait'], $b['m2Fait']);
        // La fin recule avec la pause.
        self::assertSame(60, (int) (($b['fin']->getTimestamp() - $a['fin']->getTimestamp()) / 60));
    }

    public function testVitesseDuProfilTantQueRienNEstConfirme(): void
    {
        $pieces = $this->pieces();
        $pieces[0] = ['m2' => 3.0, 'statut' => 'en_cours', 'debut' => $this->t('10:24'), 'fin' => null];
        $pieces[1]['statut'] = 'a_imprimer';
        $p = ProgressionRouleau::calculer($pieces, $this->t('10:24'), [], $this->t('10:30'), 25.0, 12.0);

        self::assertNull($p['vitesseReelle']);
        self::assertSame(25.0, $p['vitesse']);
        self::assertSame(1, $p['courante']);
        self::assertSame(2.5, $p['m2Fait']);
    }

    public function testSansVitesseNiFin(): void
    {
        $p = ProgressionRouleau::calculer($this->pieces(), $this->t('10:24'), [], $this->t('10:35'), null, null);

        self::assertSame(3.0, $p['m2Fait']);
        self::assertFalse($p['estime']);
        // La vitesse réelle suffit pour l'heure de fin.
        self::assertNotNull($p['fin']);
    }

    public function testTempsDUnOrdrePausesRetirees(): void
    {
        $pieces = [
            ['debut' => $this->t('10:24'), 'fin' => $this->t('10:31')],
            ['debut' => $this->t('10:31'), 'fin' => $this->t('10:50')],
        ];
        $t = ProgressionRouleau::tempsOrdre($pieces, [['debut' => $this->t('10:40'), 'fin' => $this->t('10:45')]], $this->t('11:00'));

        self::assertSame(21, $t['minutes']);
        self::assertSame('10:24', $t['debut']->format('H:i'));
        self::assertSame('10:50', $t['fin']->format('H:i'));
        self::assertNull(ProgressionRouleau::tempsOrdre([['debut' => null, 'fin' => null]], [], $this->t('11:00')));
    }
}
