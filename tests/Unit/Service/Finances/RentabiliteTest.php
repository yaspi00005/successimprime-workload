<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\Rentabilite;
use PHPUnit\Framework\TestCase;

final class RentabiliteTest extends TestCase
{
    public function testVentilationRameneeAuCoutEnregistre(): void
    {
        $calcul = ['matieres' => 6000, 'encre' => 2000, 'machine' => 1000, 'finitions' => 1000, 'total' => 10000];

        // Coût enregistré identique ou absent : le calcul du jour est gardé.
        self::assertSame(['matieres' => 6000, 'encre' => 2000, 'machine' => 1000, 'finitions' => 1000], Rentabilite::ventiler($calcul, 10000));
        self::assertSame(6000, Rentabilite::ventiler($calcul, 0)['matieres']);

        // Coût enregistré différent : il fait foi, au franc près.
        $ventile = Rentabilite::ventiler($calcul, 12001);
        self::assertSame(12001, array_sum($ventile));
        self::assertSame(2400, $ventile['encre']);
        self::assertSame(7201, $ventile['matieres']);

        // Rien de calculable : tout le coût va aux matières.
        self::assertSame(['matieres' => 5000, 'encre' => 0, 'machine' => 0, 'finitions' => 0], Rentabilite::ventiler(['matieres' => 0, 'encre' => 0, 'machine' => 0, 'finitions' => 0, 'total' => 0], 5000));
    }

    public function testMinutesDOrdrePausesDeduites(): void
    {
        $debut = new \DateTimeImmutable('2026-10-12 08:00');

        self::assertSame(75, Rentabilite::minutesOrdre($debut, new \DateTimeImmutable('2026-10-12 09:45'), 30));
        self::assertSame(0, Rentabilite::minutesOrdre($debut, null, 0));
        self::assertSame(0, Rentabilite::minutesOrdre($debut, $debut->modify('-1 hour'), 0));
        self::assertSame(0, Rentabilite::minutesOrdre($debut, $debut->modify('+10 minutes'), 30));
    }

    public function testTauxEtCouleur(): void
    {
        self::assertSame(58, Rentabilite::taux(100000, 42000));
        self::assertNull(Rentabilite::taux(0, 5000));
        self::assertSame(-20, Rentabilite::taux(10000, 12000));

        self::assertSame('vert', Rentabilite::couleur(55));
        self::assertSame('bleu', Rentabilite::couleur(45));
        self::assertSame('orange', Rentabilite::couleur(35));
        self::assertSame('rouge', Rentabilite::couleur(34));
        self::assertSame('gris', Rentabilite::couleur(null));
    }

    public function testRegroupementParProduit(): void
    {
        $lignes = [
            ['produit' => 'Cartes de visite', 'ht' => 50000, 'total' => 20000],
            ['produit' => 'Bâches', 'ht' => 200000, 'total' => 120000],
            ['produit' => 'Cartes de visite', 'ht' => 50000, 'total' => 20000],
            ['produit' => '', 'ht' => 99999, 'total' => 0],
            ['produit' => 'Flyers', 'ht' => 10000, 'total' => 5000],
        ];

        $groupes = Rentabilite::regrouper($lignes, 'produit', 2);

        self::assertSame([
            ['nom' => 'Bâches', 'ht' => 200000, 'cout' => 120000, 'taux' => 40, 'couleur' => 'orange'],
            ['nom' => 'Cartes de visite', 'ht' => 100000, 'cout' => 40000, 'taux' => 60, 'couleur' => 'vert'],
        ], $groupes);
    }

    public function testMachineQuiMargeLeMoins(): void
    {
        self::assertSame(['nom' => 'Mimaki', 'taux' => 41], Rentabilite::plusFaible([
            ['nom' => 'Konica', 'taux' => 62],
            ['nom' => 'Mimaki', 'taux' => 41],
            ['nom' => 'Massicot', 'taux' => null],
        ]));
        // Écart de moins de 10 points : rien à signaler.
        self::assertNull(Rentabilite::plusFaible([['nom' => 'A', 'taux' => 50], ['nom' => 'B', 'taux' => 45]]));
        self::assertNull(Rentabilite::plusFaible([['nom' => 'A', 'taux' => 50]]));
    }

    public function testCommandesAMargeFaible(): void
    {
        $lignes = [
            ['commande_id' => 7, 'commande_numero' => 'CMD-7', 'client' => 'Hôtel Niger', 'ht' => 100000, 'total' => 50000],
            ['commande_id' => 8, 'commande_numero' => 'CMD-8', 'client' => 'Salon Espoir', 'ht' => 40000, 'total' => 30000],
            ['commande_id' => 8, 'commande_numero' => 'CMD-8', 'client' => 'Salon Espoir', 'ht' => 10000, 'total' => 9000],
            ['commande_id' => 9, 'commande_numero' => 'CMD-9', 'client' => 'Pharmacie', 'ht' => 20000, 'total' => 13000],
        ];

        $faibles = Rentabilite::commandesFaibles($lignes, 5);

        // CMD-7 marge 50 % : au-dessus du seuil, absente. La pire d'abord.
        self::assertSame(['CMD-8', 'CMD-9'], array_column($faibles, 'numero'));
        self::assertSame(22, $faibles[0]['taux']);
        self::assertSame('rouge', $faibles[0]['couleur']);
        self::assertSame(50000, $faibles[0]['ht']);
        self::assertCount(1, Rentabilite::commandesFaibles($lignes, 1));
    }

    public function testNomsDeProduitsPourLesMatieres(): void
    {
        self::assertSame('roll-up 85 × 200 cm', Rentabilite::nomCourt('Roll-up 85 × 200 cm (démo)'));
        self::assertSame('flyers A5', Rentabilite::nomCourt('Flyers A5'));
        self::assertSame('bâche, roll-up…', Rentabilite::liste(['bâche', 'roll-up', 't-shirts'], 2));
        self::assertSame('bâche, roll-up', Rentabilite::liste(['bâche', 'roll-up'], 3));
    }

    public function testResumeDeLigne(): void
    {
        self::assertSame('2 bâche 3x2', Rentabilite::resumeLigne(2, 'Bâche 3x2'));
        self::assertSame('1 flyers', Rentabilite::resumeLigne(0, ' Flyers '));
        self::assertSame('', Rentabilite::resumeLigne(5, ''));
    }
}
