<?php

namespace App\Tests\Unit\Service\Achats;

use App\Service\Achats\CalculsMarges;
use App\Service\Achats\CoutsMarges;
use PHPUnit\Framework\TestCase;

/**
 * Coûts, marges et bénéfice brut par produit (maquette SU04, docs/achats.md).
 */
final class CalculsMargesTest extends TestCase
{
    /** Une ligne reçue : 4 rouleaux de vinyle de 76 m² à 159 600 F, transport 260 F/m² et transit 90 F/m² répartis. */
    private static function vinyle(array $change = []): array
    {
        return $change + [
            'quantiteAchat' => 4, 'prixUnitaire' => 159600, 'quantiteStock' => 304.0,
            'coutRevient' => 2450, 'bonStock' => 304.0, 'fraisTransport' => 79000, 'fraisTransit' => 27400,
        ];
    }

    public function testLeCoutRenduSeDecomposeEnAchatTransportEtTransit(): void
    {
        $u = CalculsMarges::unitairesReceptions([self::vinyle()]);

        self::assertNotNull($u);
        self::assertEqualsWithDelta(2100.0, $u['achat'], 0.01, '159 600 F ÷ 76 m²');
        // 350 F/m² de frais, partagés comme sur la commande (79 000 / 27 400).
        self::assertEqualsWithDelta(350.0, $u['transport'] + $u['transit'], 0.01);
        self::assertEqualsWithDelta(350 * 79000 / 106400, $u['transport'], 0.01);
        self::assertSame(304.0, $u['quantite']);
    }

    public function testLesReceptionsSontPondereesParLesUnitesEnBonEtat(): void
    {
        // Un rouleau écrasé : 228 m² en bon état portent les frais (coût 2 586 F/m²) ; une autre réception sans frais.
        $u = CalculsMarges::unitairesReceptions([
            self::vinyle(['coutRevient' => 2586, 'bonStock' => 228.0]),
            self::vinyle(['quantiteAchat' => 1, 'quantiteStock' => 76.0, 'prixUnitaire' => 152000, 'coutRevient' => 2000, 'bonStock' => 76.0, 'fraisTransport' => 0, 'fraisTransit' => 0]),
        ]);

        self::assertNotNull($u);
        self::assertEqualsWithDelta((2100 * 228 + 2000 * 76) / 304, $u['achat'], 0.01);
        self::assertEqualsWithDelta(486 * 228 / 304, $u['transport'] + $u['transit'], 0.01, 'la réception sans frais ne porte pas de transport');
        self::assertSame(304.0, $u['quantite']);
    }

    public function testSansFraisNiQuantiteRienNeCompte(): void
    {
        self::assertNull(CalculsMarges::unitairesReceptions([]));
        self::assertNull(CalculsMarges::unitairesReceptions([self::vinyle(['quantiteStock' => null])]));
        self::assertNull(CalculsMarges::unitairesReceptions([self::vinyle(['bonStock' => 0.0])]));

        // Coût de revient absent (ancienne commande) : le prix seul, sans frais.
        $u = CalculsMarges::unitairesReceptions([self::vinyle(['coutRevient' => null, 'fraisTransport' => 0, 'fraisTransit' => 0])]);
        self::assertEqualsWithDelta(2100.0, $u['achat'], 0.01);
        self::assertSame(0.0, $u['transport'] + $u['transit']);
    }

    public function testReceptionsRetenuesDesTroisDerniersMoisSinonLaDerniere(): void
    {
        $fin = new \DateTimeImmutable('2026-11-01');
        $l = static fn (string $date, int $prix): array => ['recueLe' => new \DateTimeImmutable($date), 'prix' => $prix];

        $retenues = CalculsMarges::receptionsRetenues([$l('2026-11-03', 1), $l('2026-10-10', 2), $l('2026-08-20', 3), $l('2026-05-01', 4)], $fin);
        self::assertSame([2, 3], array_column($retenues, 'prix'), 'pas la réception d\'après le mois, pas celle de plus de 90 jours');

        $retenues = CalculsMarges::receptionsRetenues([$l('2026-05-01 10:00', 4), $l('2026-05-01 09:00', 5), $l('2026-03-01', 6)], $fin);
        self::assertSame([4, 5], array_column($retenues, 'prix'), 'la dernière réception, toutes ses lignes');

        self::assertSame([], CalculsMarges::receptionsRetenues([$l('2026-11-02', 1)], $fin));
    }

    public function testCoutMachineAuM2SaisiSinonDeduit(): void
    {
        self::assertSame(380, CalculsMarges::machineM2(380, 4500, 18.0));
        self::assertSame(0, CalculsMarges::machineM2(0, 4500, 18.0), 'zéro saisi reste zéro');
        self::assertSame(250, CalculsMarges::machineM2(null, 4500, 18.0), '4 500 F de l\'heure à 18 m² de l\'heure');
        self::assertNull(CalculsMarges::machineM2(null, 4500, null));
        self::assertNull(CalculsMarges::machineM2(null, null, 18.0));
    }

    public function testGacheMesureeSinonParDefaut(): void
    {
        self::assertSame(['pourcent' => 5.5, 'mesuree' => true], CalculsMarges::gache(5.5));
        self::assertSame(['pourcent' => 8.0, 'mesuree' => false], CalculsMarges::gache(null));
        self::assertSame(['pourcent' => 10.0, 'mesuree' => false], CalculsMarges::gache(null, 10.0));
        self::assertSame(['pourcent' => 8.0, 'mesuree' => false], CalculsMarges::gache(120.0), 'une gâche impossible est ignorée');
    }

    public function testQuantiteVendueDansLUniteDuProduit(): void
    {
        self::assertSame(12.5, CalculsMarges::quantiteVendue('m²', 12.5, 3.0, 2));
        self::assertSame(6.0, CalculsMarges::quantiteVendue('m', 12.5, 3.0, 2));
        self::assertSame(2.0, CalculsMarges::quantiteVendue('pièce', 12.5, 3.0, 2));
    }

    public function testCoutDUneLignePosteParPoste(): void
    {
        // 10 m² de vinyle : achat 2 100, transport 260, transit 90 ; gâche 5 % ; encre 800 et machine 250 au m².
        $c = CalculsMarges::coutsLigne(10.0, ['achat' => 2100.0, 'transport' => 260.0, 'transit' => 90.0], 5.0, 10.0, 800, 250, 1500.0);

        self::assertSame(21000.0, $c['achat']);
        self::assertSame(2600.0, $c['transport']);
        self::assertSame(900.0, $c['transit']);
        self::assertSame(1225.0, $c['gache'], '5 % de la matière rendue Bamako');
        self::assertSame(8000.0, $c['encre']);
        self::assertSame(2500.0, $c['machine']);
        self::assertSame(1500.0, $c['autres']);

        // Recto verso : la surface imprimée double l'encre et la machine, pas la matière.
        $rv = CalculsMarges::coutsLigne(10.0, ['achat' => 2100.0, 'transport' => 0.0, 'transit' => 0.0], 0.0, 20.0, 800, 250);
        self::assertSame(21000.0, $rv['achat']);
        self::assertSame(16000.0, $rv['encre']);

        // Sans matière connue ni machine : rien d'inventé.
        self::assertSame(0.0, array_sum(CalculsMarges::coutsLigne(10.0, null, 8.0, 10.0, null, null)));
    }

    public function testBilanDuProduit(): void
    {
        $couts = CalculsMarges::additionner(
            CalculsMarges::coutsLigne(10.0, ['achat' => 2100.0, 'transport' => 260.0, 'transit' => 90.0], 5.0, 10.0, 800, 250),
            CalculsMarges::coutsLigne(5.0, ['achat' => 2100.0, 'transport' => 260.0, 'transit' => 90.0], 5.0, 5.0, 800, 250),
        );
        $b = CalculsMarges::bilan(135000, 15.0, $couts);

        self::assertSame(9000, $b['prixMoyen']);
        self::assertSame(['achat' => 2100, 'transport' => 260, 'transit' => 90, 'encre' => 800, 'gache' => 123, 'machine' => 250, 'autres' => 0], $b['unitaires']);
        self::assertSame(3623, $b['coutUnitaire']);
        self::assertSame(5377, $b['margeUnitaire']);
        self::assertSame(54338, $b['cout'], 'arrondi au franc sur le total, pas sur l\'unité');
        self::assertSame(80662, $b['benefice']);
        self::assertSame(59.7, $b['taux']);
    }

    public function testBilanSansVente(): void
    {
        $b = CalculsMarges::bilan(0, 0.0, []);

        self::assertNull($b['prixMoyen']);
        self::assertNull($b['margeUnitaire']);
        self::assertNull($b['taux']);
        self::assertSame(0, $b['benefice']);
    }

    public function testCascadeDuPrixALaMarge(): void
    {
        $bilan = ['prixMoyen' => 6000, 'margeUnitaire' => 2620, 'unitaires' => ['achat' => 2100, 'transport' => 260, 'transit' => 90, 'encre' => 420, 'gache' => 130, 'machine' => 380, 'autres' => 0]];
        $barres = CalculsMarges::cascade($bilan);

        self::assertSame(['prix', 'achat', 'transport', 'transit', 'encre', 'gache', 'machine', 'marge'], array_column($barres, 'cle'), 'pas de barre pour un poste nul');
        self::assertSame([6000, -2100, -260, -90, -420, -130, -380, 2620], array_column($barres, 'valeur'));
        self::assertSame(1.0, $barres[0]['haut']);
        self::assertSame(1.0, $barres[1]['haut'], 'l\'achat part du haut du prix');
        self::assertEqualsWithDelta(3900 / 6000, $barres[1]['bas'], 1e-9);
        self::assertEqualsWithDelta($barres[1]['bas'], $barres[2]['haut'], 1e-9, 'chaque poste repart du bas du précédent');
        self::assertEqualsWithDelta(2620 / 6000, $barres[6]['bas'], 1e-9);
        self::assertEqualsWithDelta(2620 / 6000, $barres[7]['haut'], 1e-9, 'la marge est ce qui reste');

        // Vendu à perte : la marge est posée à zéro, son montant dit la perte.
        $perte = CalculsMarges::cascade(['prixMoyen' => 1000, 'margeUnitaire' => -200, 'unitaires' => ['achat' => 1200, 'transport' => 0, 'transit' => 0, 'encre' => 0, 'gache' => 0, 'machine' => 0, 'autres' => 0]]);
        self::assertSame(-200, $perte[2]['valeur']);
        self::assertSame(0.0, $perte[2]['haut']);
        self::assertSame(1.0, $perte[1]['haut']);
        self::assertSame(0.0, $perte[1]['bas'], 'un coût plus grand que le prix s\'arrête à zéro');
    }

    public function testCeQuIlFautRetenir(): void
    {
        $produit = static fn (string $nom, string $unite, ?string $origine, int $prix, int $marge, float $taux, int $transport, int $transit): array => [
            'nom' => $nom, 'unite' => $unite, 'origine' => $origine,
            'bilan' => ['ca' => 100000, 'prixMoyen' => $prix, 'margeUnitaire' => $marge, 'taux' => $taux, 'unitaires' => ['achat' => 0, 'transport' => $transport, 'transit' => $transit, 'encre' => 0, 'gache' => 0, 'machine' => 0, 'autres' => 0]],
        ];
        $constats = CalculsMarges::aRetenir([
            $produit('Bâche 440 g', 'm²', null, 3290, 1155, 35.1, 60, 0),
            $produit('Vinyle adhésif', 'm²', 'Dakar', 6000, 2620, 43.7, 260, 90),
            $produit('Transfert DTF A4', 'pièce', 'Canton', 1500, 1000, 66.7, 30, 15),
        ]);

        self::assertSame(['transport', 'faible', 'meilleur'], array_column($constats, 'sorte'));
        self::assertSame('Le transport depuis Dakar coûte 350 F/m²', $constats[0]['titre']);
        self::assertSame('soit 13 % de la marge de vinyle adhésif', $constats[0]['detail']);
        self::assertSame('Bâche 440 g : la marge la plus faible (35,1 %)', $constats[1]['titre']);
        self::assertSame('Transfert DTF A4 : 66,7 % de marge', $constats[2]['titre']);
        self::assertStringContainsString('1 000 F gagnés par pièce', $constats[2]['detail']);

        // Sous 25 % : en alerte, même seul.
        $seul = CalculsMarges::aRetenir([$produit('Affiche A3', 'pièce', null, 750, 150, 20.0, 0, 0)]);
        self::assertSame(['alerte'], array_column($seul, 'sorte'));

        self::assertSame([], CalculsMarges::aRetenir([]));
    }

    public function testMoisDemandeJamaisDansLeFutur(): void
    {
        $aujourdhui = new \DateTimeImmutable('2026-10-20 14:00');

        self::assertSame('2026-10-01', CalculsMarges::mois(null, $aujourdhui)->format('Y-m-d'));
        self::assertSame('2026-09-01', CalculsMarges::mois('2026-09', $aujourdhui)->format('Y-m-d'));
        self::assertSame('2026-10-01', CalculsMarges::mois('2027-01', $aujourdhui)->format('Y-m-d'));
        self::assertSame('2026-10-01', CalculsMarges::mois('2026-13', $aujourdhui)->format('Y-m-d'));
        self::assertSame('2026-10-01', CalculsMarges::mois('n\'importe quoi', $aujourdhui)->format('Y-m-d'));
        self::assertSame('octobre 2026', CalculsMarges::libelleMois($aujourdhui));
        self::assertSame('août 2026', CalculsMarges::libelleMois(new \DateTimeImmutable('2026-08-31')));
    }

    public function testEvolutionSurLeMoisDAvant(): void
    {
        self::assertSame(12.5, CalculsMarges::evolution(900000, 800000));
        self::assertSame(-50.0, CalculsMarges::evolution(400000, 800000));
        self::assertNull(CalculsMarges::evolution(400000, 0));
    }

    public function testSaisieDesReglages(): void
    {
        $lu = CoutsMarges::lireSaisie(['12' => '380', '5' => '6,5', '7' => '', '99' => '10', '3' => '-4', '8' => 'abc', '4' => '1 200'], [3, 4, 5, 7, 8, 12], 10000.0, 1);

        self::assertSame([4 => 1200.0, 5 => 6.5, 12 => 380.0], $lu, 'id connus, nombres positifs sous le plafond, triés');
        self::assertSame([], CoutsMarges::lireSaisie(['5' => '120'], [5], 100.0, 1), 'au-dessus du plafond');
    }
}
