<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Service\Atelier\FacteursEstimation;
use PHPUnit\Framework\TestCase;

/**
 * « Ces temps améliorent les prochaines estimations de délai » (PD06) :
 * médiane de réel ÷ proposé, 5 à 20 travaux, bornée entre 0,5 et 2.
 */
final class FacteursEstimationTest extends TestCase
{
    public function testMedianeDesRapports(): void
    {
        $f = FacteursEstimation::facteur([
            ['reel' => 33, 'prevu' => 30], ['reel' => 30, 'prevu' => 30], ['reel' => 66, 'prevu' => 60],
            ['reel' => 12, 'prevu' => 10], ['reel' => 40, 'prevu' => 40], ['reel' => 22, 'prevu' => 20],
        ]);

        self::assertSame(['facteur' => 1.1, 'nombre' => 6], $f);
    }

    public function testIlFautCinqTravaux(): void
    {
        self::assertNull(FacteursEstimation::facteur(array_fill(0, 4, ['reel' => 60, 'prevu' => 30])));
        // Un travail sans temps proposé ou sans temps réel ne compte pas.
        self::assertNull(FacteursEstimation::facteur([...array_fill(0, 4, ['reel' => 60, 'prevu' => 30]), ['reel' => null, 'prevu' => 30], ['reel' => 20, 'prevu' => 0]]));
    }

    public function testBorneEtVingtDerniers(): void
    {
        self::assertSame(2.0, FacteursEstimation::facteur(array_fill(0, 6, ['reel' => 90, 'prevu' => 30]))['facteur']);
        self::assertSame(0.5, FacteursEstimation::facteur(array_fill(0, 6, ['reel' => 5, 'prevu' => 30]))['facteur']);
        // Les 20 plus récents seulement : les anciens (très lents) ne pèsent plus.
        $f = FacteursEstimation::facteur([...array_fill(0, 20, ['reel' => 30, 'prevu' => 30]), ...array_fill(0, 30, ['reel' => 60, 'prevu' => 30])]);
        self::assertSame(['facteur' => 1.0, 'nombre' => 20], $f);
    }

    public function testAppliquerGardeLeBrutEtDitPourquoi(): void
    {
        $etapes = [
            ['cle' => 'impression', 'libelle' => 'Impression', 'propose' => 30],
            ['cle' => 'controle', 'libelle' => 'Contrôle et emballage', 'propose' => 10],
        ];
        $r = FacteursEstimation::appliquer($etapes, ['impression' => ['facteur' => 1.1, 'nombre' => 12], 'controle' => ['facteur' => 1.0, 'nombre' => 8]]);

        self::assertSame(35, $r[0]['propose']);
        self::assertSame(30, $r[0]['brut']);
        self::assertSame('corrigé d\'après les 12 derniers travaux (× 1,1)', $r[0]['correction']);
        self::assertArrayNotHasKey('brut', $r[1]);
        self::assertSame(10, $r[1]['propose']);
        self::assertSame(45, FacteursEstimation::corriger(40, ['facteur' => 1.1, 'nombre' => 5]));
        self::assertSame(40, FacteursEstimation::corriger(40, null));
    }
}
