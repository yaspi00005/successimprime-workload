<?php

namespace App\Tests\Unit\Service\Recette;

use App\Service\Recette\ContenuSensible;
use PHPUnit\Framework\TestCase;

/** Repérage des montants et des photos de pointage dans une page (recette des accès). */
final class ContenuSensibleTest extends TestCase
{
    public function testTexteVisibleSansScriptNiBalise(): void
    {
        $html = '<html><head><style>.x{}</style><script>var total = "5 000 F";</script></head><body><td>Total</td><td>12&nbsp;500&nbsp;F</td><!-- 9 000 F --></body></html>';

        self::assertSame('Total 12 500 F', ContenuSensible::texteVisible($html));
    }

    public function testMontantsEnFrancs(): void
    {
        $montants = array_column(ContenuSensible::montants('Total 12 500 F, acompte 3000 FCFA, 1.250 F CFA ; 15 Fiches, A4 80 g, F-D00498, 2 000 Fr'), 'montant');

        self::assertSame(['12 500 F', '3000 FCFA', '1.250 F CFA'], $montants);
        // Une date juste avant ne fait pas partie du montant.
        self::assertSame(['126 000 F'], array_column(ContenuSensible::montants('dernière fois 11/10 126 000 F'), 'montant'));
        self::assertSame(['227 500 FCFA'], array_column(ContenuSensible::montants('09/10/2026 09:27 227 500 FCFA'), 'montant'));
    }

    public function testSorteDuMontantDApresCeQuiPrecede(): void
    {
        self::assertSame('marge', ContenuSensible::categorie('Marge brute'));
        self::assertSame('salaire', ContenuSensible::categorie('Net à payer'));
        self::assertSame('salaire', ContenuSensible::categorie('Préparation de la paie'));
        // « Paiement » n'est pas la paie, « hors salaires » n'est pas un salaire.
        self::assertSame('paiement', ContenuSensible::categorie('Paiement reçu Wave · commande CMD-D00654 ·'));
        self::assertSame('tresorerie', ContenuSensible::categorie('Dépenses (hors salaires)'));
        self::assertSame('chiffre', ContenuSensible::categorie('Chiffre d\'affaires du mois'));
        self::assertSame('vente', ContenuSensible::categorie('Kakémono 80 × 180 cm'));
        // Le mot le plus proche du montant l'emporte.
        self::assertSame('tresorerie', ContenuSensible::categorie('suivis à part dans la Paie. Une dépense au-delà de'));
        self::assertSame('salaire', ContenuSensible::categorie('Dépenses du mois · Salaire net'));
    }

    public function testLeContexteNeRemontePasAuMontantPrecedent(): void
    {
        $montants = ContenuSensible::montants('Salaire 90 000 F Kakémono 31 000 F');

        self::assertSame('salaire', $montants[0]['categorie']);
        self::assertSame('vente', $montants[1]['categorie']);
        self::assertSame('Kakémono 31 000 F', $montants[1]['contexte']);
    }

    public function testMontantsInterditsSelonLesDroits(): void
    {
        $texte = 'Commande CMD-1 total 25 000 F. Marge 8 000 F. Paiement reçu 10 000 F.';

        self::assertCount(3, ContenuSensible::montantsInterdits($texte, ['ROLE_USER', 'ROLE_PRODUCTION_VOIR']), 'aucun droit sur les montants : tous');
        self::assertSame(['8 000 F'], array_column(ContenuSensible::montantsInterdits($texte, ['ROLE_USER', 'ROLE_COMMANDE', 'ROLE_PAIEMENT_VOIR']), 'montant'), 'la marge sans ROLE_COUT_MARGE');
        self::assertSame([], ContenuSensible::montantsInterdits($texte, ['ROLE_ADMIN']));
    }

    public function testPhotosDePointage(): void
    {
        $html = '<img src="/pointer/photo/12/arrivee"><img src="/pointer/photo/12/depart"><a href="/pointer/photo/40/arrivee">';

        self::assertSame([12, 40], ContenuSensible::photosPointage($html));
    }

    public function testJourneesDePointage(): void
    {
        $html = '<tr data-si-pointage="7"><td>Lun. 5</td></tr><tr class="si-pg-raison"><td>…</td></tr><tr data-si-pointage="9"></tr><tr data-si-pointage="7"></tr><p>data-si-pointage="x"</p>';

        self::assertSame([7, 9], ContenuSensible::journeesPointage($html));
        self::assertSame([], ContenuSensible::journeesPointage('<tr data-pointage="3">'));
    }
}
