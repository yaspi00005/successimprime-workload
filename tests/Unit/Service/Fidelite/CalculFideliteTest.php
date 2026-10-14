<?php

namespace App\Tests\Unit\Service\Fidelite;

use App\Entity\CommandesDetails;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\RegleFidelite;
use App\Service\Fidelite\CalculFidelite;
use App\Service\Fidelite\ReglagesFidelite;
use PHPUnit\Framework\TestCase;

final class CalculFideliteTest extends TestCase
{
    private const AUJOURDHUI = '2026-10-13 10:00';

    /* ---------- Familles et lignes ---------- */

    public function testFamilleAuM2OuAutre(): void
    {
        self::assertSame('m2', CalculFidelite::famille('metre_carre'));
        // Vinyle au mètre linéaire : au m² quand la laize est connue.
        self::assertSame('m2', CalculFidelite::famille('metre', 1.37));
        self::assertSame('autre', CalculFidelite::famille('metre'));
        self::assertSame('autre', CalculFidelite::famille('unite'));
        self::assertSame('autre', CalculFidelite::famille('forfait'));
    }

    public function testLigneDeCommande(): void
    {
        $bache = (new CommandesDetails())->setModeCalcul('metre_carre')->setLargeur(4)->setLongueur(1.5)->calculerSurface()->setQuantite(2);
        $l = CalculFidelite::ligne($bache);
        self::assertSame(['m2', 12.0, 2.0], [$l['famille'], $l['m2'], $l['pieces']]);

        $tshirts = (new CommandesDetails())->setModeCalcul('unite')->setQuantite(10);
        self::assertSame(['autre', 0.0], [CalculFidelite::ligne($tshirts)['famille'], CalculFidelite::ligne($tshirts)['m2']]);
    }

    /* ---------- Seuils ---------- */

    public function testSeuilDeCinqM2FranchiSurLaLigne(): void
    {
        $compteurs = [CalculFidelite::CLE_M2 => 3.0];
        // 4 m² payés d'un coup : 2 m² au-dessus des 5 m², 100 points chacun.
        $g = $this->gains([$this->m2(4)], 1.0, 1, [], [], $compteurs);
        self::assertSame(200, $g[0]['points']);
        self::assertEqualsWithDelta(7.0, $compteurs[CalculFidelite::CLE_M2], 1e-9);

        // Éligible : chaque m² suivant rapporte 100 points.
        $g = $this->gains([$this->m2(2.5)], 1.0, 1, [], [], $compteurs);
        self::assertSame(250, $g[0]['points']);
    }

    public function testSousLeSeuilRienMaisLaProgressionAvance(): void
    {
        $compteurs = [];
        $g = $this->gains([$this->m2(3.2)], 1.0, 2, [], [], $compteurs);
        self::assertSame(0, $g[0]['points']);
        self::assertEqualsWithDelta(3.2, $compteurs[CalculFidelite::CLE_M2], 1e-9);
    }

    public function testSeuilDeMontantPourLesAutresArticles(): void
    {
        $compteurs = [CalculFidelite::CLE_AUTRE => 20000.0];
        // 10 000 F de t-shirts : 5 000 F au-dessus des 25 000 F, à 2 %.
        $g = $this->gains([$this->autre(10000)], 1.0, 1, [], [], $compteurs);
        self::assertSame(100, $g[0]['points']);
        self::assertSame(30000.0, $compteurs[CalculFidelite::CLE_AUTRE]);

        // Les deux familles ont chacune leur seuil.
        $g = $this->gains([$this->m2(6), $this->autre(50000)], 1.0, 1, [], [], $compteurs);
        self::assertSame([100, 1000], array_column($g, 'points'));
    }

    public function testChaquePaiementComptePourSaPart(): void
    {
        $compteurs = [];
        $bache = $this->m2(12);
        // Acompte de la moitié, payé le jour même : 6 m² comptés, 1 m² au-dessus du seuil, ×2.
        $g = $this->gains([$bache], CalculFidelite::part(50000, 100000), 2, [], [], $compteurs);
        self::assertSame([6.0, 200], [$g[0]['m2'], $g[0]['points']]);
        // Le solde payé le lendemain : 6 m² à 100 points, ×1.
        $g = $this->gains([$bache], CalculFidelite::part(50000, 100000, 50000), 1, [], [], $compteurs);
        self::assertSame(600, $g[0]['points']);
        // Un trop-perçu ne compte plus rien.
        self::assertSame(0.0, CalculFidelite::part(10000, 100000, 100000));
    }

    /* ---------- Délai de paiement ---------- */

    public function testMultiplicateurSelonLeJourDuPaiement(): void
    {
        $r = new ReglagesFidelite();
        $ref = new \DateTimeImmutable('2026-10-13 16:00');
        self::assertSame(2, CalculFidelite::multiplicateur(new \DateTimeImmutable('2026-10-13 18:30'), $ref, $r));
        // Payé avant la facture : comme le jour même.
        self::assertSame(2, CalculFidelite::multiplicateur(new \DateTimeImmutable('2026-10-10 09:00'), $ref, $r));
        self::assertSame(1, CalculFidelite::multiplicateur(new \DateTimeImmutable('2026-10-14 08:00'), $ref, $r));
        self::assertSame(1, CalculFidelite::multiplicateur(new \DateTimeImmutable('2026-10-16 15:59'), $ref, $r));
        self::assertSame(0, CalculFidelite::multiplicateur(new \DateTimeImmutable('2026-10-16 16:01'), $ref, $r));
    }

    public function testPaiementEnRetardCompteSansRapporter(): void
    {
        $compteurs = [CalculFidelite::CLE_M2 => 10.0];
        $g = $this->gains([$this->m2(3)], 1.0, 0, [], [], $compteurs);
        self::assertSame(0, $g[0]['points']);
        self::assertEqualsWithDelta(13.0, $compteurs[CalculFidelite::CLE_M2], 1e-9);
    }

    /* ---------- Qui gagne ---------- */

    public function testClientB2BEtPaiementsQuiNeRapportentPas(): void
    {
        self::assertTrue(CalculFidelite::paiementRapporte(true, false, Paiements::MODE_ESPECES, true, false));
        self::assertFalse(CalculFidelite::paiementRapporte(true, true, Paiements::MODE_ESPECES, true, false), 'client au prix B2B');
        self::assertFalse(CalculFidelite::paiementRapporte(true, false, Paiements::MODE_ORANGE_MONEY, false, false), 'paiement en attente');
        self::assertFalse(CalculFidelite::paiementRapporte(true, false, Paiements::MODE_ESPECES, true, true), 'commande annulée');
        self::assertFalse(CalculFidelite::paiementRapporte(true, false, Paiements::MODE_POINTS_FIDELITE, true, false), 'payé en points');
        self::assertFalse(CalculFidelite::paiementRapporte(false, false, Paiements::MODE_ESPECES, true, false), 'programme arrêté');
    }

    /* ---------- Valeurs du client et règles par article ---------- */

    public function testValeursPropresDuGrosClient(): void
    {
        $compteurs = [];
        $client = ['pointsParM2' => 150.0, 'seuilM2' => 0.0, 'tauxAutre' => 3.0, 'seuilMontant' => 10000];
        $g = $this->gains([$this->m2(2), $this->autre(20000)], 1.0, 1, $client, [], $compteurs);
        self::assertSame([300, 300], array_column($g, 'points'));
        self::assertSame(['client', 'client'], array_column($g, 'regle'));
    }

    public function testPrioriteClientPuisArticlePuisGeneral(): void
    {
        $r = new ReglagesFidelite();
        $jour = new \DateTimeImmutable(self::AUJOURDHUI);
        $regle = $this->regle(RegleFidelite::MODE_UNITE, 150, '2026-10-01');

        $general = CalculFidelite::regle('m2', 7, [], [], $r, $jour);
        self::assertSame(['defaut', 100.0, 5.0], [$general['source'], $general['valeur'], $general['seuil']]);

        $article = CalculFidelite::regle('m2', 7, [], [$regle], $r, $jour);
        self::assertSame(['article', 150.0, 0.0, 'produit:7:m2'], [$article['source'], $article['valeur'], $article['seuil'], $article['compteur']]);

        $client = CalculFidelite::regle('m2', 7, ['pointsParM2' => 200.0], [$regle], $r, $jour);
        self::assertSame(['client', 200.0], [$client['source'], $client['valeur']]);
        // Seuil non personnalisé : celui de l'article reste.
        self::assertSame(0.0, $client['seuil']);
    }

    public function testArticleDesactiveNeRapporteRien(): void
    {
        $compteurs = [CalculFidelite::CLE_M2 => 20.0];
        $off = $this->regle(RegleFidelite::MODE_UNITE, 0, '2026-01-01')->setActif(false);
        // Même avec les valeurs d'un gros client.
        $g = $this->gains([$this->m2(4, 7)], 1.0, 2, ['pointsParM2' => 150.0], [7 => [$off]], $compteurs);
        self::assertSame([0, 'desactive'], [$g[0]['points'], $g[0]['regle']]);
        // La ligne compte quand même pour la progression.
        self::assertEqualsWithDelta(24.0, $compteurs[CalculFidelite::CLE_M2], 1e-9);
    }

    public function testPeriodeDeLaRegleParArticle(): void
    {
        $r = new ReglagesFidelite();
        $noel = $this->regle(RegleFidelite::MODE_POURCENTAGE, 5, '2026-12-01', '2026-12-31');
        $avant = new \DateTimeImmutable('2026-11-30');
        $dedans = new \DateTimeImmutable('2026-12-31 23:00');
        $apres = new \DateTimeImmutable('2027-01-01');

        self::assertNull(CalculFidelite::regleDuJour([$noel], $avant), 'programmée, pas encore commencée');
        self::assertSame($noel, CalculFidelite::regleDuJour([$noel], $dedans), 'dernier jour compris');
        self::assertNull(CalculFidelite::regleDuJour([$noel], $apres));
        self::assertSame('defaut', CalculFidelite::regle('autre', 3, [], [$noel], $r, $apres)['source']);

        // Deux règles qui se chevauchent : la plus récemment commencée gagne.
        $base = $this->regle(RegleFidelite::MODE_POURCENTAGE, 3, '2026-01-01');
        self::assertSame($noel, CalculFidelite::regleDuJour([$base, $noel], $dedans));
        self::assertSame($base, CalculFidelite::regleDuJour([$base, $noel], $apres));
    }

    public function testRegleParArticleAvecSeuilEtValeurDuPoint(): void
    {
        $compteurs = [];
        // T-shirts : 10 points par pièce dès la 6e pièce de ce produit, point à 5 F.
        $regle = $this->regle(RegleFidelite::MODE_UNITE, 10, '2026-10-01')->setSeuil(5)->setValeurPoint(5);
        $g = $this->gains([$this->autre(40000, 9, 8)], 1.0, 1, [], [9 => [$regle]], $compteurs);
        self::assertSame([30, 5, 'article'], [$g[0]['points'], $g[0]['valeurPoint'], $g[0]['regle']]);
        self::assertSame(8.0, $compteurs['produit:9:pieces']);

        // Portée famille : le seuil se compte sur tous les articles « autres ».
        $famille = $this->regle(RegleFidelite::MODE_POURCENTAGE, 4, '2026-10-01')->setSeuil(45000)->setPortee(RegleFidelite::PORTEE_FAMILLE);
        $g = $this->gains([$this->autre(10000, 9, 1)], 1.0, 1, [], [9 => [$famille]], $compteurs);
        self::assertSame(200, $g[0]['points']); // 40 000 + 10 000 → 5 000 F au-dessus de 45 000, à 4 %
    }

    /* ---------- Solde, expiration, utilisation ---------- */

    public function testLesPlusAnciensPointsServentEtExpirentDAbord(): void
    {
        $m = [
            $this->mvt(1, 'gain', 100, '2025-09-01'),
            $this->mvt(2, 'gain', 50, '2026-06-01'),
            $this->mvt(3, 'utilisation', -120, '2026-07-01'),
        ];
        $e = CalculFidelite::etat($m, new \DateTimeImmutable('2026-07-02'));
        self::assertSame([30, 30, 0], [$e['solde'], $e['disponible'], $e['dette']]);
        self::assertSame([['lot' => 1, 'points' => 100], ['lot' => 2, 'points' => 20]], array_map(static fn (array $c): array => ['lot' => $c['lot'], 'points' => $c['points']], $e['consommes'][3]));

        // Un an après le premier gain : le lot de septembre est vide, rien n'expire.
        $e = CalculFidelite::etat($m, new \DateTimeImmutable('2026-09-02'));
        self::assertSame([], $e['aExpirer']);

        // Un an après le second : ses 30 points restants sont à expirer.
        $e = CalculFidelite::etat($m, new \DateTimeImmutable('2027-06-02'));
        self::assertSame([['id' => 2, 'restant' => 30]], array_map(static fn (array $l): array => ['id' => $l['id'], 'restant' => $l['restant']], $e['aExpirer']));
        self::assertSame(0, $e['disponible']);
    }

    public function testPointsQuiExpirentBientot(): void
    {
        $m = [$this->mvt(1, 'gain', 80, '2025-10-20'), $this->mvt(2, 'gain', 40, '2026-05-01')];
        $e = CalculFidelite::etat($m, new \DateTimeImmutable(self::AUJOURDHUI), 30);
        self::assertSame([120, 80, '2026-10-20'], [$e['disponible'], $e['expirant'], $e['prochaineExpiration']->format('Y-m-d')]);

        // L'expiration écrite au relevé vise son lot.
        $m[] = $this->mvt(3, 'expiration', -80, '2026-10-20', 1);
        $e = CalculFidelite::etat($m, new \DateTimeImmutable('2026-10-21'));
        self::assertSame([40, 0, []], [$e['disponible'], $e['expirant'], $e['aExpirer']]);
    }

    public function testAnnulationRetireLeGainVise(): void
    {
        $m = [
            $this->mvt(1, 'gain', 200, '2026-08-01'),
            $this->mvt(2, 'gain', 300, '2026-09-01'),
            $this->mvt(3, 'annulation', -300, '2026-09-05', 2),
        ];
        $e = CalculFidelite::etat($m, new \DateTimeImmutable(self::AUJOURDHUI));
        self::assertSame([200, 200], [$e['solde'], $e['disponible']]);
        self::assertSame(2, $e['consommes'][3][0]['lot']);

        // Points déjà utilisés puis paiement annulé : dette, reprise sur le gain suivant.
        $m = [
            $this->mvt(1, 'gain', 300, '2026-09-01'),
            $this->mvt(2, 'utilisation', -250, '2026-09-02'),
            $this->mvt(3, 'annulation', -300, '2026-09-03', 1),
        ];
        $e = CalculFidelite::etat($m, new \DateTimeImmutable(self::AUJOURDHUI));
        self::assertSame([-250, 250], [$e['disponible'], $e['dette']]);
        $m[] = $this->mvt(4, 'gain', 400, '2026-09-10');
        $e = CalculFidelite::etat($m, new \DateTimeImmutable(self::AUJOURDHUI));
        self::assertSame([150, 0, 150], [$e['disponible'], $e['dette'], $e['lots'][0]['restant']]);
    }

    public function testUtilisationPlafonneeAuResteAPayer(): void
    {
        $lots = [['restant' => 300, 'valeurPoint' => 1], ['restant' => 500, 'valeurPoint' => 1]];
        self::assertSame(['points' => 800, 'montant' => 800], CalculFidelite::planUtilisation($lots, 2000, 5000));
        self::assertSame(['points' => 450, 'montant' => 450], CalculFidelite::planUtilisation($lots, 2000, 450));
        self::assertSame(['points' => 100, 'montant' => 100], CalculFidelite::planUtilisation($lots, 100, 5000));

        // Points à 5 F : jamais plus que le reste à payer.
        $lots = [['restant' => 10, 'valeurPoint' => 5], ['restant' => 100, 'valeurPoint' => 1]];
        self::assertSame(['points' => 9, 'montant' => 45], CalculFidelite::planUtilisation($lots, 1000, 48));
        self::assertSame(['points' => 13, 'montant' => 53], CalculFidelite::planUtilisation($lots, 1000, 53));
    }

    /* ---------- Outils ---------- */

    /** @return array{id: ?int, produit: ?int, famille: string, m2: float, pieces: float, montant: int} */
    private function m2(float $m2, ?int $produit = null): array
    {
        return ['id' => null, 'produit' => $produit, 'famille' => 'm2', 'm2' => $m2, 'pieces' => 1.0, 'montant' => (int) round($m2 * 5000)];
    }

    /** @return array{id: ?int, produit: ?int, famille: string, m2: float, pieces: float, montant: int} */
    private function autre(int $montant, ?int $produit = null, int $pieces = 1): array
    {
        return ['id' => null, 'produit' => $produit, 'famille' => 'autre', 'm2' => 0.0, 'pieces' => (float) $pieces, 'montant' => $montant];
    }

    /**
     * @param list<array<string, mixed>>        $lignes
     * @param array<string, mixed>              $client
     * @param array<int, list<RegleFidelite>>   $regles
     * @param array<string, float>              $compteurs
     *
     * @return list<array<string, mixed>>
     */
    private function gains(array $lignes, float $part, int $multiplicateur, array $client, array $regles, array &$compteurs): array
    {
        return CalculFidelite::gains($lignes, $part, $multiplicateur, $client, $regles, new ReglagesFidelite(), new \DateTimeImmutable(self::AUJOURDHUI), $compteurs);
    }

    private function regle(string $mode, float $valeur, string $debut, ?string $fin = null): RegleFidelite
    {
        return (new RegleFidelite())->setMode($mode)->setValeur($valeur)->setDebut(new \DateTimeImmutable($debut))->setFin(null === $fin ? null : new \DateTimeImmutable($fin));
    }

    /** @return array{id: int, type: string, points: int, date: \DateTimeImmutable, expireLe: ?\DateTimeImmutable, valeurPoint: int, cible: ?int} */
    private function mvt(int $id, string $type, int $points, string $date, ?int $cible = null): array
    {
        $d = new \DateTimeImmutable($date);

        return ['id' => $id, 'type' => $type, 'points' => $points, 'date' => $d, 'expireLe' => $points > 0 ? $d->modify('+12 months') : null, 'valeurPoint' => 1, 'cible' => $cible];
    }
}
