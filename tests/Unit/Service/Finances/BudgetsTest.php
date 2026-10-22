<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\Budgets;
use PHPUnit\Framework\TestCase;

final class BudgetsTest extends TestCase
{
    public function testCategorieDuMouvement(): void
    {
        self::assertSame('matieres', Budgets::categoriePourMouvement('achat'));
        self::assertSame('maintenance', Budgets::categoriePourMouvement('entretien'));
        self::assertSame('carburant', Budgets::categoriePourMouvement('transport'));
        self::assertSame('divers', Budgets::categoriePourMouvement('frais_bancaire'));
        // Salaires et virements internes ne consomment pas de budget.
        self::assertNull(Budgets::categoriePourMouvement('salaire'));
        self::assertNull(Budgets::categoriePourMouvement('transfert_interne'));
    }

    public function testEtatDeLaJauge(): void
    {
        self::assertSame('ok', Budgets::etat(0));
        self::assertSame('ok', Budgets::etat(80));
        self::assertSame('attention', Budgets::etat(81));
        self::assertSame('attention', Budgets::etat(99));
        self::assertSame('plein', Budgets::etat(100));
        self::assertSame('depasse', Budgets::etat(116));
    }

    public function testLignesDansLOrdreDesCategories(): void
    {
        $lignes = Budgets::lignes(
            ['matieres' => 1200000, 'loyer' => 350000, 'electricite' => 0],
            ['matieres' => 984000, 'loyer' => 350000, 'electricite' => 12000, 'maintenance' => 0],
        );

        self::assertCount(\count(Budgets::CATEGORIES), $lignes);
        self::assertSame(['cle' => 'matieres', 'libelle' => 'Matières et consommables', 'budget' => 1200000, 'consomme' => 984000, 'part' => 82, 'etat' => 'attention'], $lignes[0]);
        self::assertSame('plein', $lignes[1]['etat']);
        // Dépense sans budget : affichée comme pleine, pas de division par zéro.
        self::assertSame(100, $lignes[2]['part']);
        self::assertSame(0, $lignes[3]['part']);
    }
}
