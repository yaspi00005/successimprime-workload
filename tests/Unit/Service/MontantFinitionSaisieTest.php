<?php

namespace App\Tests\Unit\Service;

use App\Service\MontantFinitionSaisie;
use PHPUnit\Framework\TestCase;

final class MontantFinitionSaisieTest extends TestCase
{
    private const ORIGINE = ['finition' => 7, 'prixApplique' => 500, 'modeCalcul' => 'metre_carre', 'quantite' => 1, 'montant' => 2250];

    public function testFinitionInchangeeGardeSonMontant(): void
    {
        // Ourlet d'une bâche de 4,5 m² commandée en ligne : 500 F x 4,5 m², pas 500 x 1.
        $saisie = ['finition' => 7, 'prixApplique' => 500, 'modeCalcul' => 'metre_carre', 'quantite' => 1];

        self::assertSame(2250, MontantFinitionSaisie::calculer($saisie, self::ORIGINE));
    }

    public function testFinitionModifieeRecalculee(): void
    {
        $saisie = ['finition' => 7, 'prixApplique' => 500, 'modeCalcul' => 'metre_carre', 'quantite' => 3];
        self::assertSame(1500, MontantFinitionSaisie::calculer($saisie, self::ORIGINE));

        $autre = ['finition' => 8, 'prixApplique' => 500, 'modeCalcul' => 'metre_carre', 'quantite' => 1];
        self::assertSame(500, MontantFinitionSaisie::calculer($autre, self::ORIGINE));
    }

    public function testNouvelleFinition(): void
    {
        self::assertSame(1200, MontantFinitionSaisie::calculer(['finition' => 3, 'prixApplique' => 300, 'modeCalcul' => 'unite', 'quantite' => 4], []));
        self::assertSame(300, MontantFinitionSaisie::calculer(['finition' => 3, 'prixApplique' => 300, 'modeCalcul' => 'forfait', 'quantite' => 4], []));
    }
}
