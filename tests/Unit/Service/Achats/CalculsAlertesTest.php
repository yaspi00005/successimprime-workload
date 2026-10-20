<?php

namespace App\Tests\Unit\Service\Achats;

use App\Service\Achats\CalculsAchat;
use App\Service\Achats\CalculsAlertes;
use PHPUnit\Framework\TestCase;

/**
 * Règles d'alerte et calculs du réapprovisionnement (docs/achats.md,
 * maquettes SU06 et SU07) : hausse de prix de 5 %, retard dès le
 * lendemain de la date promise, intervalle habituel dépassé de 20 %,
 * quantité suggérée, tendance, textes et courbe du stock.
 */
final class CalculsAlertesTest extends TestCase
{
    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testHausseDePrixAPartirDuSeuil(): void
    {
        // Vinyle transparent : 170 400 → 182 400 F le rouleau, +7 %.
        self::assertSame(7.0, CalculsAlertes::hausse(170400, 182400));
        self::assertTrue(CalculsAlertes::estHausse(170400, 182400, 5));
        // Exactement 5 % : signalé ; 4,9 % : non.
        self::assertTrue(CalculsAlertes::estHausse(100000, 105000, 5));
        self::assertFalse(CalculsAlertes::estHausse(100000, 104900, 5));
        // Une baisse ou un premier achat ne sont jamais une hausse.
        self::assertFalse(CalculsAlertes::estHausse(182400, 170400, 5));
        self::assertFalse(CalculsAlertes::estHausse(null, 182400, 5));
        self::assertNull(CalculsAlertes::hausse(0, 1000));
    }

    public function testRetardDesLeLendemainDeLaDatePromise(): void
    {
        $promise = self::date('2026-10-18');

        self::assertSame(0, CalculsAlertes::joursRetard($promise, self::date('2026-10-17')));
        self::assertSame(0, CalculsAlertes::joursRetard($promise, self::date('2026-10-18')), 'le jour promis, pas encore en retard');
        self::assertSame(1, CalculsAlertes::joursRetard($promise, self::date('2026-10-19')));
        self::assertSame(2, CalculsAlertes::joursRetard($promise, self::date('2026-10-20')));
        self::assertSame(0, CalculsAlertes::joursRetard(null, self::date('2026-10-20')));
        self::assertSame(CalculsAlertes::ORANGE, CalculsAlertes::graviteRetard(2));
        self::assertSame(CalculsAlertes::ROUGE, CalculsAlertes::graviteRetard(8));
    }

    public function testIntervalleHabituelEntreLesCommandes(): void
    {
        // Vinyle blanc : 28/08, 23/09, 19/10 → 26 jours en moyenne.
        $dates = [self::date('2026-09-23 10:00'), self::date('2026-08-28 09:30'), self::date('2026-10-19 15:00')];
        self::assertSame(26.0, CalculsAlertes::intervalleHabituel($dates));

        // Deux commandes le même jour comptent pour une.
        self::assertSame(26.0, CalculsAlertes::intervalleHabituel([...$dates, self::date('2026-10-19 08:00')]));

        // Moins de trois commandes : pas encore d'habitude.
        self::assertNull(CalculsAlertes::intervalleHabituel([self::date('2026-08-28'), self::date('2026-09-23')]));
        self::assertSame(26.0, CalculsAlertes::intervalleHabituel([self::date('2026-08-28'), self::date('2026-09-23')], 2));
        self::assertNull(CalculsAlertes::intervalleHabituel([]));
    }

    public function testCommandeOublieeQuandLIntervalleEstDepasseDe20Pourcent(): void
    {
        // Tous les 61 jours : oubliée au-delà de 73,2 jours.
        self::assertFalse(CalculsAlertes::estOubliee(61.0, 70, 20));
        self::assertFalse(CalculsAlertes::estOubliee(61.0, 73, 20));
        self::assertTrue(CalculsAlertes::estOubliee(61.0, 74, 20));
        self::assertTrue(CalculsAlertes::estOubliee(61.0, 75, 20));
        // Exactement 20 % de plus : pas encore.
        self::assertFalse(CalculsAlertes::estOubliee(30.0, 36, 20));
        self::assertTrue(CalculsAlertes::estOubliee(30.0, 37, 20));
        self::assertFalse(CalculsAlertes::estOubliee(null, 400, 20));
    }

    public function testQuantiteSuggereeDApresLaConsommationMoyenne(): void
    {
        // Bâche 510 g : 38,4 m² disponibles, 22 m² par semaine, 3 j de délai + 5 de marge,
        // commandée toutes les 3 semaines ; un rouleau fait 160 m².
        // Besoin : 22/7 × (3 + 5 + 21) − 38,4 ≈ 52,7 m² → 1 rouleau.
        self::assertSame(1, CalculsAlertes::quantiteSuggeree(22.0, 38.4, 60.0, 480.0, 3, 5, 21.0, 160.0));

        // Vinyle blanc : 57,8 m², 14,5 m²/semaine, 9 + 5 j, tous les 26 j, rouleaux de 76 m².
        // 14,5/7 × 40 − 57,8 ≈ 25 m² → 1 rouleau ; sans historique (30 j de couverture) : 14,5/7 × 44 − 57,8 ≈ 33,3 → 1.
        self::assertSame(1, CalculsAlertes::quantiteSuggeree(14.5, 57.8, 30.0, 330.0, 9, 5, 26.0, 76.0));

        // Rien en stock : de quoi tenir délai + marge + couverture.
        // 50 m/semaine × (20 + 5 + 30)/7 ≈ 392,9 m → 4 rouleaux de 100 m.
        self::assertSame(4, CalculsAlertes::quantiteSuggeree(50.0, 0.0, 50.0, null, 20, 5, null, 100.0));

        // Le stock maximum plafonne la commande : 4 rouleaux voulus, mais 300 m au plus à l'arrivée.
        // À l'arrivée il resterait 0 ; plafond 300 m → 3 rouleaux.
        self::assertSame(3, CalculsAlertes::quantiteSuggeree(50.0, 0.0, 50.0, 300.0, 20, 5, null, 100.0));

        // Sans consommation, le minimum compte encore ; toujours au moins une unité.
        self::assertSame(2, CalculsAlertes::quantiteSuggeree(0.0, 0.5, 4.0, null, 1, 5, null, 2.0));
        self::assertSame(1, CalculsAlertes::quantiteSuggeree(0.0, 100.0, 4.0, null, 1, 5, null, 1.0));
        self::assertSame(1, CalculsAlertes::quantiteSuggeree(10.0, 0.0, 0.0, null, 1, 5, null, 0.0));
    }

    public function testTendanceDeLaConsommation(): void
    {
        // 12 semaines à 9 m² = 108 m² ; les 4 dernières : 40,3 m² au lieu de 36 → +12 %.
        self::assertSame(12, CalculsAlertes::tendance(40.3, 108.0));
        self::assertSame(0, CalculsAlertes::tendance(36.0, 108.0));
        self::assertNull(CalculsAlertes::tendance(0.0, 0.0));
        self::assertSame('consommation en hausse de 12 % sur 4 semaines', CalculsAlertes::texteTendance(12));
        self::assertSame('consommation en baisse de 20 % sur 4 semaines', CalculsAlertes::texteTendance(-20));
        self::assertSame('consommation stable', CalculsAlertes::texteTendance(3));
        self::assertNull(CalculsAlertes::texteTendance(null));
    }

    public function testTextesDesAlertesCommeSurLaMaquette(): void
    {
        self::assertSame('Bâche 510 g sous le minimum', CalculsAlertes::titreStock('Bâche 510 g', CalculsAchat::URGENCE_SOUS_MINIMUM, null));
        self::assertSame('Micro-perforé : commander avant le 24/10', CalculsAlertes::titreStock('Micro-perforé', CalculsAchat::URGENCE_BIENTOT, self::date('2026-10-24')));
        self::assertSame('Encre : commander maintenant', CalculsAlertes::titreStock('Encre', CalculsAchat::URGENCE_MAINTENANT, self::date('2026-10-20')));
        self::assertSame('Stock 24,7 m² · 19 jours restants · Dakar Signs livre en 9 j', CalculsAlertes::detailStock(24.7, 'm²', 19, 'Dakar Signs', 9));
        self::assertSame('Stock 3 900 feuilles · 5 jours restants · Papeterie Niger livre en 5 j', CalculsAlertes::detailStock(3900, 'feuille', 5, 'Papeterie Niger', 5));
        self::assertSame('Stock 0,8 L · 15 jours restants · Encres Pro livre en 2 j', CalculsAlertes::detailStock(0.8, 'L', 15, 'Encres Pro', 2));
        self::assertSame(CalculsAlertes::ROUGE, CalculsAlertes::graviteStock(CalculsAchat::URGENCE_SOUS_MINIMUM));
        self::assertSame(CalculsAlertes::ORANGE, CalculsAlertes::graviteStock(CalculsAchat::URGENCE_BIENTOT));

        self::assertSame('Prix en hausse : vinyle transparent +7 %', CalculsAlertes::titrePrix('Vinyle transparent', 7.04));
        self::assertSame('182 400 F le rouleau au lieu de 170 400 F · Dakar Signs', CalculsAlertes::detailPrix(182400, 170400, 'rouleau', 'Dakar Signs'));
        self::assertSame('10 200 F l\'unité au lieu de 9 500 F · Yinghe', CalculsAlertes::detailPrix(10200, 9500, null, 'Yinghe'));

        self::assertSame('Dakar Signs en retard : CA-2026-0041', CalculsAlertes::titreRetard('Dakar Signs', 'CA-2026-0041'));
        self::assertSame('Promise le 18/10 · 2 jours de retard', CalculsAlertes::detailRetard(self::date('2026-10-18'), 2));
        self::assertSame('Commandé tous les 61 jours chez Kanté · rien depuis 75 jours (06/08)', CalculsAlertes::detailOubli(61.25, 75, self::date('2026-08-06'), 'Kanté'));

        $message = CalculsAlertes::messageCollegue('Kadiatou', 'Bâche 510 g sous le minimum', 'Stock 38,4 m² · Sahel Supplies livre en 3 j', 'https://erp/achats/reapprovisionnement');
        self::assertSame('Bonjour Kadiatou, alerte achats : Bâche 510 g sous le minimum. Stock 38,4 m² · Sahel Supplies livre en 3 j. À voir : https://erp/achats/reapprovisionnement', $message);
        self::assertStringContainsString('notre commande CA-2026-0041 devait arriver le 18/10', CalculsAlertes::messageRelance('M. Ndiaye', 'CA-2026-0041', self::date('2026-10-18')));
    }

    public function testHistoriqueDuStockReconstitueDepuisLesMouvements(): void
    {
        // Aujourd'hui 50 ; sortie de 10 aujourd'hui, entrée de 30 avant-hier.
        $serie = CalculsAlertes::historique(50.0, [0 => -10.0, -2 => 30.0], 4);

        self::assertSame([-4 => 30.0, -3 => 30.0, -2 => 60.0, -1 => 60.0, 0 => 50.0], $serie);

        // Une entrée plus grosse que le stock d'alors : les jours d'avant manquent.
        self::assertSame([-2 => 60.0, -1 => 60.0, 0 => 50.0], CalculsAlertes::historique(50.0, [0 => -10.0, -2 => 300.0], 4));
    }

    public function testCourbeDuStockAvecCommandeEtArrivee(): void
    {
        // 70 m² aujourd'hui, 14 m² par semaine (2 par jour), minimum 30 ; commander dans 6 jours, 9 jours de transport.
        $courbe = CalculsAlertes::courbe([-1 => 72.0, 0 => 70.0], 70.0, 14.0, 30.0, 6, 9, 30, 30, 600, 190);

        self::assertSame(300.0, $courbe['aujourdhui'], 'aujourd\'hui au milieu');
        self::assertSame(360.0, $courbe['commande']['x']);
        self::assertSame(450.0, $courbe['arrivee']['x']);
        self::assertSame(['x' => 360.0, 'largeur' => 90.0], $courbe['bande']);
        self::assertNotNull($courbe['minimum']);
        // Le point de commande (stock 58) est au-dessus de la ligne du minimum : plus haut, y plus petit.
        self::assertLessThan($courbe['minimum'], $courbe['commande']['y']);
        self::assertCount(31, explode(' ', $courbe['prevision']));
        self::assertSame([-30, -15, 0, 15, 30], array_keys($courbe['x']));

        // Sans date de commande (rien à prévoir) : ni point, ni bande.
        $sans = CalculsAlertes::courbe([0 => 10.0], 10.0, 0.0, 0.0, null, 5);
        self::assertNull($sans['commande']);
        self::assertNull($sans['bande']);
        self::assertNull($sans['minimum']);
    }
}
