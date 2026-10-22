<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\ContexteLigne;
use PHPUnit\Framework\TestCase;

final class CalculPromotionTest extends TestCase
{
    private \DateTimeImmutable $jour;
    private Produits $rollup;
    private Produits $bache;
    private CategorieProduit $grandFormat;

    protected function setUp(): void
    {
        $this->jour = new \DateTimeImmutable('2026-11-05');
        $this->grandFormat = self::avecId((new CategorieProduit())->setNom('Grand format'), 7);
        $this->rollup = self::avecId((new Produits())->setNom('Roll-up'), 11);
        $this->bache = self::avecId((new Produits())->setNom('Bâche'), 12);
        $this->bache->setCategorieProduit($this->grandFormat);
    }

    /* ---------- Avantage ---------- */

    public function testPourcentageMontantEtPrixFixeParUnite(): void
    {
        self::assertSame(1500, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_POURCENTAGE, 15), 10000));
        self::assertSame(1000, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_MONTANT, 1000), 10000));
        // Prix fixe : la ligne passe à 7 000 F par unité.
        self::assertSame(3000, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_PRIX_FIXE, 7000), 10000));
        // Jamais au-delà du prix, jamais négatif.
        self::assertSame(800, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_MONTANT, 1000), 800));
        self::assertSame(0, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_PRIX_FIXE, 12000), 10000));
        self::assertSame(10000, CalculPromotion::remiseSurPrix($this->promo(Promotion::AVANTAGE_POURCENTAGE, 150), 10000));
    }

    public function testPasDeCumulAvecLaRemiseDejaSurLaLigne(): void
    {
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 15);

        // Remise saisie de 500 F : la promotion n'ajoute que ce qui manque pour arriver à −15 %.
        self::assertSame(1000, CalculPromotion::remiseUnitaire($p, 10000, 500));
        // Remise déjà meilleure (prix B2B à −20 %) : rien.
        self::assertSame(0, CalculPromotion::remiseUnitaire($p, 10000, 2000));
    }

    public function testMontantDeLaLigneParUniteFacturableEtPlafonne(): void
    {
        // 3,5 m² à 1 000 F de promotion par m².
        self::assertSame(3500, CalculPromotion::montantLigne(1000, 0, 3.5, 35000));
        // Avec une remise saisie, seule la part de la promotion compte.
        self::assertSame(2000, CalculPromotion::montantLigne(1000, 500, 2, 20000));
        // Plafond au total brut de la ligne.
        self::assertSame(1000, CalculPromotion::montantLigne(5000, 4500, 2, 10000));
        self::assertSame(0, CalculPromotion::montantLigne(0, 0, 2, 10000));
    }

    /* ---------- Éligibilité ---------- */

    public function testArticleViseParProduitOuParCategorie(): void
    {
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup);
        self::assertNull(CalculPromotion::refus($p, $this->ligne(produit: $this->rollup)));
        self::assertSame('article', CalculPromotion::refus($p, $this->ligne(produit: $this->bache)));

        $p->setCategorie($this->grandFormat);
        self::assertNull(CalculPromotion::refus($p, $this->ligne(produit: $this->bache)));
        self::assertSame('article', CalculPromotion::refus($p, $this->ligne(produit: null)));
    }

    public function testPeriodeEtSuspension(): void
    {
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup);
        $p->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-05'));
        self::assertNull(CalculPromotion::refus($p, $this->ligne()));
        self::assertSame('periode', CalculPromotion::refus($p, $this->ligne(jour: new \DateTimeImmutable('2026-11-06'))));
        self::assertSame('periode', CalculPromotion::refus($p, $this->ligne(jour: new \DateTimeImmutable('2026-10-31'))));

        $p->setActive(false);
        self::assertSame('suspendue', CalculPromotion::refus($p, $this->ligne()));
        // La ligne qui la porte déjà la garde (historique).
        self::assertNull(CalculPromotion::refus($p, $this->ligne(), true));
    }

    public function testSegmentsCalculesListeEtTous(): void
    {
        $client = self::client(5);
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup)->setSegment('nouveaux');
        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $client, segments: ['tous', 'particuliers', 'nouveaux'])));
        self::assertSame('segment', CalculPromotion::refus($p, $this->ligne(client: $client, segments: ['tous', 'particuliers', 'actifs'])));

        $p->setSegment(Promotion::SEGMENT_FIDELITE);
        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $client, segments: ['tous', 'fidelite'])));

        $p->setSegment(Promotion::SEGMENT_LISTE);
        self::assertSame('segment', CalculPromotion::refus($p, $this->ligne(client: $client)));
        $p->addClient(self::client(5));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $client)), 'même client, autre objet');

        $p->setSegment(Promotion::SEGMENT_TOUS);
        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $client, segments: [])));
        self::assertSame('client', CalculPromotion::refus($p, $this->ligne(client: false)));
    }

    public function testConditionsQuantiteMontantCodeEtUtilisations(): void
    {
        $p = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup), 3);

        $p->setQuantiteMin(10);
        self::assertSame('quantite', CalculPromotion::refus($p, $this->ligne(quantite: 9)));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(quantite: 10)));
        $p->setQuantiteMin(null);

        $p->setMontantMin(50000);
        self::assertSame('montant', CalculPromotion::refus($p, $this->ligne(brut: 49999)));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(brut: 50000)));
        $p->setMontantMin(null);

        $p->setCode(' rentree 26 ');
        self::assertSame('RENTREE26', $p->getCode());
        self::assertSame('code', CalculPromotion::refus($p, $this->ligne()));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(code: 'Rentree26')));
        // Déjà sur la ligne : le code n'est pas redemandé à chaque enregistrement.
        self::assertNull(CalculPromotion::refus($p, $this->ligne(), true));
        $p->setCode(null);

        $p->setUtilisationsParClient(1);
        self::assertNull(CalculPromotion::refus($p, $this->ligne(utilisations: [3 => 0])));
        self::assertSame('utilisations', CalculPromotion::refus($p, $this->ligne(utilisations: [3 => 1])));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(utilisations: [4 => 5])), 'une autre promotion');
    }

    public function testSansEffetQuandLaRemiseDeLaLigneEstMeilleure(): void
    {
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup);
        self::assertSame('sans_effet', CalculPromotion::refus($p, $this->ligne(remise: 1500)));
    }

    /* ---------- Revendeurs au prix B2B ---------- */

    public function testRevendeurB2bExcluSaufExceptionAcceptee(): void
    {
        $revendeur = self::client(8)->setTypeClient('B2B');
        $p = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 20)->addProduit($this->rollup), 4);

        // Prix B2B à −10 % déjà dans la remise : la promotion à −20 % serait meilleure.
        self::assertSame('b2b', CalculPromotion::refus($p, $this->ligne(client: $revendeur, remise: 1000)));
        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $revendeur, remise: 1000, exceptions: [4])));
        // Sans cumul : la ligne passe de −10 % (B2B) à −20 %, pas à −30 %.
        self::assertSame(1000, CalculPromotion::remiseUnitaire($p, 10000, 1000));
        // Pas d'exception à demander pour une promotion moins bonne que le prix B2B.
        self::assertSame('sans_effet', CalculPromotion::refus($p, $this->ligne(client: $revendeur, remise: 2500)));
    }

    public function testPrixB2bDansLePrixUnitaireLaPromotionPartDuPrixPublic(): void
    {
        $revendeur = self::client(8)->setTypeClient('B2B');
        $p = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 20)->addProduit($this->rollup), 4);

        // Configuration du catalogue : prix B2B 9 000 F au lieu de 10 000 F, sans remise.
        $ligne = $this->ligne(client: $revendeur, prix: 9000, exceptions: [4], prixPublic: 10000);
        self::assertSame(1000, $ligne->avantageDeLaLigne());
        // −20 % du prix public = 2 000 F ; le prix B2B en donnait déjà 1 000 : la ligne finit à 8 000 F, pas à 7 200 F.
        self::assertSame(1000, CalculPromotion::remisePour($p, $ligne));
        self::assertSame('sans_effet', CalculPromotion::refus($p, $this->ligne(client: $revendeur, prix: 7500, prixPublic: 10000)));

        // Pour un client au prix public, la promotion part du prix de la ligne.
        self::assertSame(1800, CalculPromotion::remisePour($p, $this->ligne(prix: 9000, prixPublic: 10000)));
    }

    public function testUneEntrepriseAuPrixPublicNEstPasExclue(): void
    {
        $entreprise = self::client(9)->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        self::assertFalse($entreprise->isB2B());
        $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup);

        self::assertNull(CalculPromotion::refus($p, $this->ligne(client: $entreprise, segments: ['tous', 'entreprises'])));
    }

    /* ---------- Une seule promotion par ligne ---------- */

    public function testLaPlusForteRemiseLEmporteEtLesExceptionsPossiblesSontListees(): void
    {
        $dix = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup), 1);
        $mille5 = self::avecId($this->promo(Promotion::AVANTAGE_MONTANT, 1500)->addProduit($this->rollup), 2);
        $fixe = self::avecId($this->promo(Promotion::AVANTAGE_PRIX_FIXE, 9000)->addProduit($this->rollup), 3);

        $r = CalculPromotion::evaluer([$dix, $mille5, $fixe], $this->ligne());
        self::assertSame($mille5, $r['promotion']);
        self::assertSame(1500, $r['remise']);
        self::assertSame([], $r['b2b']);

        $revendeur = self::client(8)->setTypeClient('B2B');
        $r = CalculPromotion::evaluer([$dix, $mille5, $fixe], $this->ligne(client: $revendeur, remise: 500));
        self::assertNull($r['promotion']);
        self::assertSame([$mille5, $dix, $fixe], array_column($r['b2b'], 'promotion'));
        self::assertSame([1000, 500, 500], array_column($r['b2b'], 'remise'));
    }

    public function testAEgaliteCelleQuiFinitLePlusTotPuisLaPlusAncienne(): void
    {
        $a = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup), 5);
        $b = self::avecId($this->promo(Promotion::AVANTAGE_MONTANT, 1000)->addProduit($this->rollup), 6);
        $a->setFin(new \DateTimeImmutable('2026-11-30'));
        $b->setFin(new \DateTimeImmutable('2026-11-20'));
        self::assertSame($b, CalculPromotion::evaluer([$a, $b], $this->ligne())['promotion']);

        $b->setFin(new \DateTimeImmutable('2026-11-30'));
        self::assertSame($a, CalculPromotion::evaluer([$b, $a], $this->ligne())['promotion']);
    }

    public function testLaPromotionDejaPorteeEstGardeeMemeSuspendueEtComptéeUneFois(): void
    {
        $p = self::avecId($this->promo(Promotion::AVANTAGE_POURCENTAGE, 10)->addProduit($this->rollup), 9);
        $p->setActive(false);

        $r = CalculPromotion::evaluer([$p, $p], $this->ligne(), $p);
        self::assertSame($p, $r['promotion']);
        self::assertSame(1000, $r['remise']);
        self::assertNull(CalculPromotion::evaluer([$p], $this->ligne())['promotion']);
    }

    /* ---------- La ligne ---------- */

    public function testLaRemiseDeLaPromotionSAjouteAuTotalDeLaLigne(): void
    {
        foreach ([new CommandesDetails(), new DevisDetails()] as $ligne) {
            $ligne->setProduit($this->rollup)->setPrixUnitaire(10000)->setQuantite(3)->setRemise(500)->setTva(18);
            $p = $this->promo(Promotion::AVANTAGE_POURCENTAGE, 15)->setNom('Rentrée')->addProduit($this->rollup);
            $ligne->appliquerPromotion($p, CalculPromotion::remiseUnitaire($p, $ligne->getPrixUnitaire(), $ligne->getRemise()));
            $ligne->calculerTotaux();

            self::assertSame(1000, $ligne->getRemisePromotion());
            self::assertSame(3000, $ligne->getMontantPromotion());
            self::assertSame(30000 - 4500, $ligne->getTotalHt());
            self::assertSame(4500, $ligne->getMontantRemise());
            self::assertSame('Rentrée (−15 %)', $ligne->getLibellePromotion());

            $ligne->retirerPromotion()->calculerTotaux();
            self::assertSame(30000 - 1500, $ligne->getTotalHt());
            self::assertNull($ligne->getPromotion());
            self::assertSame(0, $ligne->getMontantPromotion());
        }
    }

    public function testLibellesDeLAvantage(): void
    {
        self::assertSame('−15 %', Promotion::formaterAvantage(Promotion::AVANTAGE_POURCENTAGE, 15));
        self::assertSame('−12,5 %', Promotion::formaterAvantage(Promotion::AVANTAGE_POURCENTAGE, 12.5));
        self::assertSame('−1 000 F par unité', Promotion::formaterAvantage(Promotion::AVANTAGE_MONTANT, 1000));
        self::assertSame('prix fixe 7 500 F par unité', Promotion::formaterAvantage(Promotion::AVANTAGE_PRIX_FIXE, 7500));
    }

    /* ---------- Outils ---------- */

    private function promo(string $avantage, float $valeur): Promotion
    {
        return (new Promotion())
            ->setNom('Promo')
            ->setAvantage($avantage)
            ->setValeur($valeur)
            ->setDebut(new \DateTimeImmutable('2026-11-01'))
            ->setFin(new \DateTimeImmutable('2026-11-30'));
    }

    /**
     * @param list<string>    $segments
     * @param array<int, int> $utilisations
     * @param list<int>       $exceptions
     */
    private function ligne(
        Clients|false|null $client = null,
        Produits|false|null $produit = false,
        float $prix = 10000,
        int $remise = 0,
        int $quantite = 1,
        int $brut = 10000,
        ?\DateTimeImmutable $jour = null,
        array $segments = ['tous', 'particuliers', 'actifs'],
        ?string $code = null,
        array $utilisations = [],
        array $exceptions = [],
        ?float $prixPublic = null,
    ): ContexteLigne {
        return new ContexteLigne(
            false === $client ? null : ($client ?? self::client(1)),
            false === $produit ? $this->rollup : $produit,
            $prix,
            $remise,
            $quantite,
            $brut,
            $jour ?? $this->jour,
            $segments,
            $code,
            $utilisations,
            $exceptions,
            $prixPublic,
        );
    }

    private static function client(int $id): Clients
    {
        return self::avecId((new Clients())->setNom('Client '.$id), $id);
    }

    /**
     * @template T of object
     *
     * @param T $objet
     *
     * @return T
     */
    private static function avecId(object $objet, int $id): object
    {
        (new \ReflectionProperty($objet, 'id'))->setValue($objet, $id);

        return $objet;
    }
}
