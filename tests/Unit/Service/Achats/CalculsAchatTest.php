<?php

namespace App\Tests\Unit\Service\Achats;

use App\Entity\Achats;
use App\Service\Achats\CalculsAchat;
use PHPUnit\Framework\TestCase;

/**
 * Calculs des écrans Achats (docs/achats.md, maquettes SU01 à SU03),
 * avec les chiffres de la maquette SU03 (Dakar Signs).
 */
final class CalculsAchatTest extends TestCase
{
    private static function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    public function testLesFraisSontRepartisSurChaqueMetreCarre(): void
    {
        // 4 rouleaux de vinyle (304 m²) et 2 de micro-perforé (137 m²) : 441 m².
        $lignes = [
            ['quantite' => 304.0, 'unite' => 'm²', 'montant' => 638400],
            ['quantite' => 137.0, 'unite' => 'm²', 'montant' => 452100],
        ];

        $transport = CalculsAchat::repartirFrais($lignes, 114700);
        $transit = CalculsAchat::repartirFrais($lignes, 39700);

        self::assertSame(114700, array_sum($transport), 'la somme des parts fait les frais, au franc près');
        self::assertSame(79068, $transport[0]);
        self::assertSame(39700, array_sum($transit));
        self::assertSame(260, CalculsAchat::fraisParUnite(114700, 441.0));
        self::assertSame(90, CalculsAchat::fraisParUnite(39700, 441.0));
        self::assertNull(CalculsAchat::fraisParUnite(1000, 0.0));
    }

    public function testUnitesDifferentesAuProrataDuMontant(): void
    {
        $lignes = [
            ['quantite' => 300.0, 'unite' => 'm', 'montant' => 300000],
            ['quantite' => 10.0, 'unite' => 'kg', 'montant' => 100000],
        ];

        self::assertFalse(CalculsAchat::memeUnite($lignes));
        self::assertSame([30000, 10000], CalculsAchat::repartirFrais($lignes, 40000));
        self::assertSame([0, 0], CalculsAchat::repartirFrais($lignes, 0));
        self::assertSame([], CalculsAchat::repartirFrais([], 5000));
    }

    public function testCoutDeRevientAuMetreCarre(): void
    {
        // Vinyle : 159 600 F le rouleau de 76 m² = 2 100 F/m², + 260 + 90 = 2 450 F/m².
        $lignes = [
            ['quantite' => 304.0, 'unite' => 'm²', 'montant' => 638400],
            ['quantite' => 137.0, 'unite' => 'm²', 'montant' => 452100],
        ];
        $transport = CalculsAchat::repartirFrais($lignes, 114700);
        $transit = CalculsAchat::repartirFrais($lignes, 39700);

        self::assertSame(2450, CalculsAchat::coutRevientUnitaire(638400, $transport[0] + $transit[0], 304.0));
        // Micro-perforé : 226 050 F le rouleau de 68,5 m² = 3 300 F/m², + 350 = 3 650 F/m².
        self::assertSame(3650, CalculsAchat::coutRevientUnitaire(452100, $transport[1] + $transit[1], 137.0));
        self::assertNull(CalculsAchat::coutRevientUnitaire(1000, 0, 0.0));
    }

    public function testPourcentageALHeureEtRetardMoyen(): void
    {
        $livraisons = [
            ['promise' => self::date('2026-09-30'), 'recue' => self::date('2026-10-02 15:00'), 'commande' => self::date('2026-09-23')],
            ['promise' => self::date('2026-09-04'), 'recue' => self::date('2026-09-04 09:00'), 'commande' => self::date('2026-08-28')],
            ['promise' => self::date('2026-07-31'), 'recue' => self::date('2026-08-05'), 'commande' => self::date('2026-07-24')],
            ['promise' => self::date('2026-07-10'), 'recue' => self::date('2026-07-08'), 'commande' => self::date('2026-07-03')],
            ['promise' => null, 'recue' => null, 'commande' => self::date('2026-10-19')],
        ];

        // Deux sur quatre arrivées au plus tard le jour promis (une en avance).
        self::assertSame(50, CalculsAchat::pourcentageALHeure($livraisons));
        // Retards 2, 0, 5 et 0 (l'avance ne compte pas en négatif).
        self::assertSame(1.8, CalculsAchat::retardMoyen($livraisons));
        // Délais réels 9, 7, 12 et 5 jours.
        self::assertSame(8.3, CalculsAchat::delaiReelMoyen($livraisons));
        self::assertNull(CalculsAchat::pourcentageALHeure([]));
        self::assertNull(CalculsAchat::retardMoyen([]));
        self::assertSame(-2, CalculsAchat::retardJours(self::date('2026-07-10'), self::date('2026-07-08 18:00')));
        self::assertSame(1.8, CalculsAchat::pourcentageAbime(55.0, 1.0));
        self::assertNull(CalculsAchat::pourcentageAbime(0.0, 0.0));
    }

    public function testConsommationEtJoursRestants(): void
    {
        // 174 m² sortis en 12 semaines : 14,5 m² par semaine ; 57,8 m² tiennent 27 jours.
        $conso = CalculsAchat::consommationHebdo(174.0);
        self::assertSame(14.5, $conso);
        self::assertSame(27, CalculsAchat::joursRestants(57.8, $conso));
        self::assertNull(CalculsAchat::joursRestants(57.8, 0.0));
        self::assertSame(0, CalculsAchat::joursRestants(-3.0, 2.0));
    }

    public function testDateDeLaProchaineCommande(): void
    {
        $aujourdhui = self::date('2026-10-19 16:30');

        // Micro-perforé : 19 jours de stock, délai 9 j + 5 j de marge : commander avant le 24/10.
        self::assertEquals(self::date('2026-10-24'), CalculsAchat::dateProchaineCommande($aujourdhui, 19, 9, 5));
        // Bâche : 12 jours de stock, délai 2 + 5 : le 24/10 aussi ; au-delà du délai, c'est aujourd'hui.
        self::assertEquals(self::date('2026-10-19'), CalculsAchat::dateProchaineCommande($aujourdhui, 10, 9, 5));
        self::assertNull(CalculsAchat::dateProchaineCommande($aujourdhui, null, 9, 5));

        // Le délai à compter : le réel mesuré (arrondi au-dessus), sinon l'article, sinon le promis.
        self::assertSame(9, CalculsAchat::delaiReappro(8.1, 7, 7));
        self::assertSame(3, CalculsAchat::delaiReappro(null, 3, 7));
        self::assertSame(7, CalculsAchat::delaiReappro(null, null, 7));
        self::assertSame(CalculsAchat::DELAI_INCONNU, CalculsAchat::delaiReappro(null, null, null));
    }

    public function testAlerteEtUrgence(): void
    {
        $aujourdhui = self::date('2026-10-19');

        self::assertTrue(CalculsAchat::aCommanderBientot(0.8, 4.0, 16, 1, 5), 'sous le minimum');
        self::assertTrue(CalculsAchat::aCommanderBientot(20.0, 15.0, 14, 9, 5), 'jours restants ≤ délai + marge');
        self::assertFalse(CalculsAchat::aCommanderBientot(24.7, 15.0, 19, 9, 5), 'encore 5 jours avant d\'alerter');
        self::assertFalse(CalculsAchat::aCommanderBientot(57.8, 30.0, 27, 9, 5));
        self::assertFalse(CalculsAchat::aCommanderBientot(57.8, 30.0, null, 9, 5), 'sans consommation, pas d\'alerte au-dessus du minimum');

        self::assertSame(CalculsAchat::URGENCE_SOUS_MINIMUM, CalculsAchat::urgence(0.8, 4.0, self::date('2026-10-30'), $aujourdhui));
        self::assertSame(CalculsAchat::URGENCE_MAINTENANT, CalculsAchat::urgence(38.4, 30.0, $aujourdhui, $aujourdhui));
        self::assertSame(CalculsAchat::URGENCE_BIENTOT, CalculsAchat::urgence(24.7, 15.0, self::date('2026-10-24'), $aujourdhui));
        self::assertSame(CalculsAchat::URGENCE_PLUS_TARD, CalculsAchat::urgence(300.0, 50.0, self::date('2026-11-06'), $aujourdhui));
        self::assertNull(CalculsAchat::urgence(300.0, 50.0, null, $aujourdhui));

        self::assertSame('avant le 24/10', CalculsAchat::texteProchaineCommande(CalculsAchat::URGENCE_BIENTOT, self::date('2026-10-24')));
        self::assertSame('le 06/11', CalculsAchat::texteProchaineCommande(CalculsAchat::URGENCE_PLUS_TARD, self::date('2026-11-06')));
        self::assertSame('sous le minimum', CalculsAchat::texteProchaineCommande(CalculsAchat::URGENCE_SOUS_MINIMUM, null));
    }

    public function testTexteDeLaSuggestion(): void
    {
        // 4 rouleaux de 76 m² à 14,5 m² par semaine.
        self::assertSame('4 rouleaux ≈ 21 semaines', CalculsAchat::suggestion(4, 'rouleau', 76.0, 14.5));
        self::assertSame('2 rouleaux ≈ 15 semaines', CalculsAchat::suggestion(2, 'rouleau', 68.5, 9.0));
        self::assertSame('1 litre ≈ 3 semaines', CalculsAchat::suggestion(1, 'litre', 1.0, 0.35));
        self::assertSame('1 sac ≈ 5 jours', CalculsAchat::suggestion(1, 'sac', 1.0, 1.5));
        self::assertSame('3 cartons · pas de consommation récente', CalculsAchat::suggestion(3, 'carton', 1.0, 0.0));
        self::assertSame('2 unités · pas de consommation récente', CalculsAchat::suggestion(2, null, 1.0, 0.0));
        self::assertSame(4, CalculsAchat::unitesPourSemaines(14.5, 76.0, 20));
        self::assertSame('boîtes', CalculsAchat::pluriel('boîte', 2));
        self::assertSame('colis', CalculsAchat::pluriel('colis', 3));
    }

    public function testSeuilDeValidation(): void
    {
        self::assertSame(Achats::STATUT_A_VALIDER, CalculsAchat::statutApresEnvoi(1244900, 500000));
        self::assertSame(Achats::STATUT_COMMANDEE, CalculsAchat::statutApresEnvoi(500000, 500000), 'au seuil, pas de validation : « au-dessus de »');
        self::assertSame(Achats::STATUT_COMMANDEE, CalculsAchat::statutApresEnvoi(110000, 500000));
        self::assertTrue(CalculsAchat::doitEtreValidee(500001, 500000));
        self::assertFalse(CalculsAchat::doitEtreValidee(0, 0));
    }

    public function testLivraisonPrevueEtEcart(): void
    {
        $promise = CalculsAchat::datePromise(self::date('2026-10-19 10:00'), 7);
        self::assertEquals(self::date('2026-10-26'), $promise);
        // Promis le 26/10, +2 j habituels (1,1 arrondi au-dessus) : prévue le 28/10.
        self::assertEquals(self::date('2026-10-28'), CalculsAchat::livraisonPrevue($promise, 1.1));
        self::assertEquals(self::date('2026-10-26'), CalculsAchat::livraisonPrevue($promise, null));

        self::assertSame(['libelle' => 'en route · prévu 28/10', 'classe' => 'attente'], CalculsAchat::ecart(Achats::STATUT_EN_ROUTE, $promise, null, 0, null, self::date('2026-10-28')));
        self::assertSame(['libelle' => 'à l\'heure', 'classe' => 'ok'], CalculsAchat::ecart(Achats::STATUT_RECU, self::date('2026-09-04'), self::date('2026-09-04 16:00')));
        self::assertSame(['libelle' => '+2 j', 'classe' => 'jaune'], CalculsAchat::ecart(Achats::STATUT_RECU, self::date('2026-09-30'), self::date('2026-10-02')));
        self::assertSame(['libelle' => '+5 j · 1 rouleau abîmé', 'classe' => 'retard'], CalculsAchat::ecart(Achats::STATUT_RECU, self::date('2026-07-31'), self::date('2026-08-05'), 1, 'rouleau'));
    }

    public function testPrixSurDouzeMoisEtVariation(): void
    {
        $aujourdhui = self::date('2026-10-19');
        $serie = CalculsAchat::prixParMois([
            ['date' => self::date('2025-09-10'), 'prix' => 140000],
            ['date' => self::date('2025-11-13'), 'prix' => 150600],
            ['date' => self::date('2026-04-11'), 'prix' => 154000],
            ['date' => self::date('2026-04-25'), 'prix' => 155000],
            ['date' => self::date('2026-10-02'), 'prix' => 159600],
        ], $aujourdhui);

        self::assertCount(12, $serie);
        self::assertSame(150600, $serie[0], 'novembre 2025 ouvre la série, septembre 2025 est hors période');
        self::assertSame(154500, $serie[5], 'moyenne des achats d\'avril');
        self::assertSame(159600, $serie[11]);
        self::assertNull($serie[1]);
        self::assertSame(6, CalculsAchat::variation($serie));
        self::assertSame(0, CalculsAchat::variation([null, 226050, null]));
        self::assertNull(CalculsAchat::variation([null, null]));

        $points = explode(' ', CalculsAchat::pointsCourbe($serie));
        self::assertCount(12, $points);
        self::assertSame('0,22', $points[0], 'le prix le plus bas en bas de la courbe');
        self::assertSame('90,2', $points[11], 'le plus haut en haut');
        self::assertSame('', CalculsAchat::pointsCourbe([null, null]));
    }

    public function testEconomieDuTransportPartage(): void
    {
        self::assertSame(57000, CalculsAchat::economieRegroupement([56000, 58200, 0], 79000));
        self::assertSame(79000, CalculsAchat::economieRegroupement([], 79000));
    }

    public function testNumerotation(): void
    {
        self::assertSame('CA-2026-0001', CalculsAchat::numeroSuivant(2026, null));
        self::assertSame('CA-2026-0042', CalculsAchat::numeroSuivant(2026, 'CA-2026-0041'));
        self::assertSame('CA-2026-0001', CalculsAchat::numeroSuivant(2026, 'ACH-000012-10-2026'));
    }
}
