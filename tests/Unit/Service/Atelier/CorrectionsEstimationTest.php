<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Service\Atelier\CorrectionsEstimation;
use PHPUnit\Framework\TestCase;

/**
 * Les temps réels des travaux terminés deviennent des facteurs par étape
 * (PD06, docs/production-colis.md) ; le passage d'une pièce refaite ne
 * compte pas.
 */
final class CorrectionsEstimationTest extends TestCase
{
    public function testFacteurParEtapeSansLesReprises(): void
    {
        $temps = [];
        for ($i = 0; $i < 6; ++$i) {
            $temps[] = ['etapes' => ['impression' => ['reel' => 30, 'prevu' => 20], 'sechage' => ['reel' => 45, 'prevu' => 45]]];
        }
        // Une reprise : l'impression d'une pièce refaite s'ajoute au premier passage, elle fausserait la médiane.
        $temps[] = ['etapes' => ['impression' => ['reel' => 200, 'prevu' => 20, 'reprise' => true]]];
        $temps[] = ['etapes' => ['finition' => ['reel' => 50, 'prevu' => 40]]];
        $temps[] = null;
        $temps[] = ['reprises' => ['1' => 1]];

        $facteurs = CorrectionsEstimation::depuisTemps($temps);

        self::assertSame(['facteur' => 1.5, 'nombre' => 6], $facteurs['impression']);
        self::assertSame(['facteur' => 1.0, 'nombre' => 6], $facteurs['sechage']);
        self::assertNull($facteurs['finition'], 'un seul travail : pas assez pour corriger');
    }

    public function testSansTempsRienACorriger(): void
    {
        self::assertSame([], CorrectionsEstimation::depuisTemps([]));
        self::assertSame([], CorrectionsEstimation::depuisTemps([['etapes' => 'abîmé'], ['etapes' => ['impression' => 12]]]));
    }
}
