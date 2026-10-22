<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\TempsEtapes;
use PHPUnit\Framework\TestCase;

/**
 * Temps réel comparé au temps estimé par étape (PD06) : impression
 * mesurée, séchage, finition, contrôle.
 */
final class TempsEtapesTest extends TestCase
{
    private function t(string $quand): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-21 '.$quand);
    }

    public function testLaMaquette(): void
    {
        $estimation = [
            ['cle' => 'impression', 'estime' => 28, 'propose' => 30, 'brut' => 25],
            ['cle' => 'sechage', 'estime' => 45, 'propose' => 45],
            ['cle' => 'finitions', 'estime' => 40, 'propose' => 40],
        ];
        $temps = [
            'impression_debut' => $this->t('09:00')->format(\DATE_ATOM),
            'impression_fin' => $this->t('09:26')->format(\DATE_ATOM),
            'finition_debut' => $this->t('10:11')->format(\DATE_ATOM),
            'etapes' => ['impression' => ['reel' => 26, 'prevu' => 25]],
        ];
        $r = TempsEtapes::calculer($estimation, [], $temps, 40, $this->t('09:30'), $this->t('11:03'), $this->t('11:12'), 45, true);

        $parCle = array_column($r['etapes'], null, 'cle');
        self::assertSame(['impression', 'sechage', 'finitions', 'controle'], array_keys($parCle));
        self::assertSame([28, 26, 25], [$parCle['impression']['estime'], $parCle['impression']['reel'], $parCle['impression']['prevu']]);
        self::assertSame(45, $parCle['sechage']['reel']);
        self::assertSame(52, $parCle['finitions']['reel']);
        self::assertSame(9, $parCle['controle']['reel']);
        self::assertNull($parCle['controle']['estime']);
        self::assertSame(113, $r['estime']);
        self::assertSame(132, $r['reel']);
        self::assertFalse($r['partiel']);
    }

    public function testSansAppuiLaFinitionCommenceApresLeSechage(): void
    {
        $temps = ['impression_fin' => $this->t('09:26')->format(\DATE_ATOM), 'etapes' => ['impression' => ['reel' => 26, 'prevu' => 25]]];
        $r = TempsEtapes::calculer([], ['impression' => 25, 'sechage' => 45, 'finitions' => 40, 'controle' => 10], $temps, null, null, $this->t('11:03'), null, 45, true);
        $parCle = array_column($r['etapes'], null, 'cle');

        self::assertSame(45, $parCle['sechage']['reel']);
        self::assertSame(52, $parCle['finitions']['reel']);
        self::assertNull($parCle['controle']['reel']);
        self::assertTrue($r['partiel']);
        self::assertSame(40, $parCle['finitions']['estime']);
    }

    public function testUnAncienTravailGardeSonTempsTotalALImpression(): void
    {
        $r = TempsEtapes::calculer([['cle' => 'impression', 'estime' => 30], ['cle' => 'controle', 'estime' => 10]], [], [], 47, $this->t('09:30'), null, null, 45, false);

        self::assertSame(['impression', 'controle'], array_column($r['etapes'], 'cle'));
        self::assertSame(47, $r['etapes'][0]['reel']);
    }

    public function testFinitionEtControleEnHeuresDOuverture(): void
    {
        // Mardi 18:30 → mercredi 08:30 : 30 min la veille, 30 min le matin.
        self::assertSame(60, TempsEtapes::minutesOuvrees(new \DateTimeImmutable('2026-10-20 18:30'), new \DateTimeImmutable('2026-10-21 08:30')));
        // Samedi 18:00 → lundi 09:00 : le dimanche est fermé.
        self::assertSame(120, TempsEtapes::minutesOuvrees(new \DateTimeImmutable('2026-10-24 18:00'), new \DateTimeImmutable('2026-10-26 09:00')));
        self::assertSame(0, TempsEtapes::minutesOuvrees($this->t('10:00'), $this->t('09:00')));
    }
}
