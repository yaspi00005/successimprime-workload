<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\VueEnsemble;
use PHPUnit\Framework\TestCase;

final class VueEnsembleTest extends TestCase
{
    public function testBarresEnMillionsEtHauteurs(): void
    {
        $barres = VueEnsemble::barres([
            ['libelle' => 'Sept.', 'ca' => 7160000, 'depenses' => 4750000],
            ['libelle' => 'Oct.', 'ca' => 2720000, 'depenses' => 3150000],
            ['libelle' => 'Nov.', 'ca' => 12500000, 'depenses' => null],
        ]);

        self::assertSame('7,16', $barres[0]['ca_m']);
        self::assertSame('4,75', $barres[0]['depenses_m']);
        self::assertSame('2,72', $barres[1]['ca_m']);
        self::assertSame('12,5', $barres[2]['ca_m']);
        self::assertNull($barres[2]['depenses_m']);
        // La plus haute barre fait 100 %, les autres en proportion.
        self::assertSame(100, $barres[2]['h_ca']);
        self::assertSame(57, $barres[0]['h_ca']);
        self::assertSame(0, $barres[2]['h_depenses']);
    }

    public function testBarresSansDonnees(): void
    {
        $barres = VueEnsemble::barres([['libelle' => 'Oct.', 'ca' => 0, 'depenses' => 0]]);

        self::assertSame('0', $barres[0]['ca_m']);
        self::assertSame(0, $barres[0]['h_ca']);
    }
}
