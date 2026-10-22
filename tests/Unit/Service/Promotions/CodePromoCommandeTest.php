<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\Clients;
use App\Entity\CodePromo;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Service\NotificationService;
use App\Service\Promotions\CalculCodePromo;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\CodePromoRefuse;
use App\Service\Promotions\CodesPromo;
use App\Service\Promotions\ContexteLigne;
use App\Service\Promotions\Promotions;
use App\Service\Promotions\PromotionsSaisie;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Avantage d'un code promo sur la commande (docs/promotions.md, « Codes
 * promo ») : réparti sur les lignes sans promotion, hors TVA, après leurs
 * remises ; les totaux, la TVA et la colonne « Remise » restent justes.
 */
final class CodePromoCommandeTest extends TestCase
{
    private Produits $rollup;
    private Produits $bache;

    protected function setUp(): void
    {
        $this->rollup = self::avecId((new Produits())->setNom('Roll-up'), 11);
        $this->bache = self::avecId((new Produits())->setNom('Bâche'), 12);
    }

    public function testPourcentageRepartiAvecEtSansTva(): void
    {
        $commande = new Commandes();
        $a = $this->ligne($commande, $this->rollup, 10000, 3, tva: 18);   // 30 000 HT
        $b = $this->ligne($commande, $this->bache, 7000, 1, tva: 0);      //  7 000 HT
        $p = $this->promotionCommande(Promotion::COMMANDE_POURCENTAGE, 10);

        [$refus, $parts] = PromotionsSaisie::avantageCommande($p, [$a, $b]);
        self::assertNull($refus);
        self::assertSame(3700, array_sum($parts));
        $this->appliquer($commande, [$a, $b], $parts);

        self::assertSame(3000, $a->getRemiseCodePromo());
        self::assertSame(700, $b->getRemiseCodePromo());
        self::assertSame(27000, $a->getTotalHt());
        self::assertSame(31860, $a->getTotalTtc(), 'TVA 18 % sur le net');
        self::assertSame(6300, $b->getTotalHt());
        self::assertSame(6300, $b->getTotalTtc(), 'sans TVA');

        // Totaux du document = somme des lignes ; l'avantage se lit à part.
        self::assertSame(33300, $commande->getTotalHt());
        self::assertSame(38160, $commande->getTotalTtc());
        self::assertSame(3700, $commande->getRemiseCodePromo());
        self::assertSame(37000, $commande->getTotalHtAvantCodePromo());
        self::assertSame(3540 + 700, $commande->getRemiseCodePromoTtc());
        self::assertSame(38160, (int) $commande->getMontantApayer());
    }

    public function testColonneRemiseDeLaFactureEtRemiseSaisie(): void
    {
        $commande = new Commandes();
        // Remise saisie de 500 F par unité : le code s'applique après.
        $a = $this->ligne($commande, $this->rollup, 10000, 2, tva: 18, remise: 500);   // 19 000 HT avant code
        $p = $this->promotionCommande(Promotion::COMMANDE_MONTANT, 5000);
        [, $parts] = PromotionsSaisie::avantageCommande($p, [$a]);
        $this->appliquer($commande, [$a], $parts);

        self::assertSame(14000, $a->getTotalHt());
        // La facture montre tout ce qui est retiré à la ligne : 1 000 F de remise + 5 000 F de code.
        self::assertSame(6000, $a->getMontantRemise());
        self::assertSame(20000 - 6000, $a->getTotalHt());
        self::assertSame(16520, $a->getTotalTtc());
    }

    public function testPasDeDoubleRemiseSurUneLigneEnPromotion(): void
    {
        $commande = new Commandes();
        $enPromo = $this->ligne($commande, $this->rollup, 10000, 1, tva: 0);
        $autre = $this->ligne($commande, $this->bache, 10000, 1, tva: 0);
        $promoLigne = self::avecId((new Promotion())->setNom('Roll-up')->setValeur(20)->addProduit($this->rollup)->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-30')), 3);
        $enPromo->appliquerPromotion($promoLigne, 2000);
        $enPromo->calculerTotaux();

        $p = $this->promotionCommande(Promotion::COMMANDE_POURCENTAGE, 10);
        [$refus, $parts] = PromotionsSaisie::avantageCommande($p, [$enPromo, $autre]);
        self::assertNull($refus);
        $this->appliquer($commande, [$enPromo, $autre], $parts);

        self::assertSame(0, $enPromo->getRemiseCodePromo(), 'la ligne garde sa promotion, sans part du code');
        self::assertSame(8000, $enPromo->getTotalHt());
        self::assertSame(1000, $autre->getRemiseCodePromo());
        self::assertSame(9000, $autre->getTotalHt());
    }

    public function testMinimumEtCodeSansEffet(): void
    {
        $commande = new Commandes();
        $a = $this->ligne($commande, $this->rollup, 10000, 1, tva: 18);
        $p = $this->promotionCommande(Promotion::COMMANDE_POURCENTAGE, 10)->setMinimumCommande(20000);
        self::assertSame('minimum', PromotionsSaisie::avantageCommande($p, [$a])[0]);
        $p->setMinimumCommande(10000);
        self::assertNull(PromotionsSaisie::avantageCommande($p, [$a])[0]);

        // Le minimum se juge après les remises des lignes : 10 000 − 500 de promotion.
        $a->appliquerPromotion(self::avecId((new Promotion())->setNom('Autre')->setValeur(5), 9), 500);
        self::assertSame('minimum', PromotionsSaisie::avantageCommande($p, [$a])[0]);
        $p->setMinimumCommande(null);

        // Toutes les lignes ont déjà une autre promotion : le code n'apporte rien.
        $a->appliquerPromotion(self::avecId((new Promotion())->setNom('Autre')->setValeur(5), 9), 500);
        self::assertSame('sans_effet', PromotionsSaisie::avantageCommande($p, [$a])[0]);

        // Un code qui ne vise que des articles : sa promotion sur la ligne suffit.
        $articles = self::avecId((new Promotion())->setNom('Articles')->setValeur(10)->addProduit($this->rollup), 21);
        $a->appliquerPromotion($articles, 1000);
        [$refus, $parts] = PromotionsSaisie::avantageCommande($articles, [$a]);
        self::assertNull($refus);
        self::assertSame([], $parts);
    }

    public function testLeDevisSuitLaMemeRegleEtLaRemiseNeDepassePasLaLigne(): void
    {
        $devis = new Devis();
        $ligne = (new DevisDetails())->setProduit($this->rollup)->setPrixUnitaire(4000)->setQuantite(1)->setTva(18);
        $devis->addDevisDetail($ligne);
        $ligne->setRemiseCodePromo(9999)->calculerTotaux();
        // Plafonnée à ce qui reste de la ligne.
        self::assertSame(4000, $ligne->getRemiseCodePromo());
        self::assertSame(0, $ligne->getTotalHt());
        self::assertSame(0, $ligne->getTotalTtc());

        $ligne->setRemiseCodePromo(1000)->calculerTotaux();
        self::assertSame(3000, $ligne->getTotalHt());
        self::assertSame(3540, $ligne->getTotalTtc());
        self::assertSame(1180, $ligne->getRemiseCodePromoTtc());
    }

    public function testUneLigneAnnuleeNeCompteNiDansLeTotalNiDansLeCode(): void
    {
        $commande = new Commandes();
        $a = $this->ligne($commande, $this->rollup, 10000, 1, tva: 0);
        $b = $this->ligne($commande, $this->bache, 10000, 1, tva: 0);
        $a->setRemiseCodePromo(1000)->calculerTotaux();
        $b->setRemiseCodePromo(1000)->calculerTotaux();
        $b->setStatutProduction(CommandesDetails::PRODUCTION_ANNULEE);
        $commande->recalculerTotaux();
        self::assertSame(9000, $commande->getTotalHt());
        self::assertSame(1000, $commande->getRemiseCodePromo());
        self::assertSame(1000, $commande->getRemiseCodePromoTtc());
    }

    /* ---------- Débloquer la promotion, B2B ---------- */

    public function testUnePromotionACodesGeneresNeSAppliqueQuAvecUnCodeVerifie(): void
    {
        $p = self::avecId((new Promotion())->setNom('Codes')->setValeur(10)->addProduit($this->rollup)
            ->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-30')), 5);
        self::assertTrue($p->estSansCode());
        self::assertNull(CalculPromotion::refus($p, $this->contexte()));

        // Réservée aux codes générés : plus rien sans un de ses codes vérifié.
        $p->setACodes(true);
        self::assertFalse($p->estSansCode());
        self::assertSame('code', CalculPromotion::refus($p, $this->contexte()));
        self::assertSame('code', CalculPromotion::refus($p, $this->contexte(code: 'RENTREE25')), 'le texte seul ne suffit pas');
        self::assertSame('code', CalculPromotion::refus($p, $this->contexte(debloquees: [6])), 'une autre promotion');
        self::assertNull(CalculPromotion::refus($p, $this->contexte(debloquees: [5])));
        // Déjà sur la ligne : elle part avec le code retiré du document (sinon l'utilisation serait rendue et la remise gardée).
        self::assertSame('code', CalculPromotion::refus($p, $this->contexte(), true));
        self::assertNull(CalculPromotion::refus($p, $this->contexte(debloquees: [5]), true));
        // Un code simple, lui, n'est pas redemandé à la ligne qui porte déjà sa promotion (comme avant).
        $simple = self::avecId((new Promotion())->setNom('Simple')->setValeur(10)->addProduit($this->rollup)->setCode('ETE')
            ->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-30')), 7);
        self::assertSame('code', CalculPromotion::refus($simple, $this->contexte()));
        self::assertNull(CalculPromotion::refus($simple, $this->contexte(), true));

        // Un avantage sur la commande la réserve aussi aux codes, même sans code encore créé.
        $q = (new Promotion())->setAvantageCommande(Promotion::COMMANDE_MONTANT)->setValeurCommande(2000);
        self::assertTrue($q->exigeCodeGenere());
        self::assertFalse($q->estSansCode());
        self::assertSame('−2 000 F sur la commande', $q->getLibelleAvantageCommande());
        $q->setAvantageCommande(Promotion::COMMANDE_POURCENTAGE)->setValeurCommande(10)->setPlafondCommande(20000);
        self::assertSame('−10 % sur la commande (au plus 20 000 F)', $q->getLibelleAvantageCommande());
    }

    public function testRevendeurB2bExcluDuCode(): void
    {
        $revendeur = self::avecId((new Clients())->setNom('Revendeur')->setTypeClient('B2B'), 8);
        self::assertTrue($revendeur->isB2B());
        $p = (new Promotion())->setNom('R')->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-30'));
        $code = CodePromo::creer($p, CodePromo::TYPE_LOT, 'SI7K4P2Q', 'x', new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable('2026-11-01'));
        self::assertSame('b2b', \App\Service\Promotions\CalculCodePromo::refus($code, $revendeur->isB2B(), new \DateTimeImmutable('2026-11-10'), 0, 0, false, ['tous']));
        // Même déjà sur le document : un revendeur ne garde pas l'avantage.
        self::assertSame('b2b', \App\Service\Promotions\CalculCodePromo::refus($code, true, new \DateTimeImmutable('2026-11-10'), 0, 0, true, ['tous']));
    }

    public function testEnLigneUnRevendeurLitLaMemeReponsePourToutCode(): void
    {
        // Le prix revendeur se dit avant de chercher le code : la réponse ne dit pas si le code existe.
        $saisie = new PromotionsSaisie(
            $this->createStub(EntityManagerInterface::class),
            (new \ReflectionClass(Promotions::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(NotificationService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(CodesPromo::class))->newInstanceWithoutConstructor(),
        );
        $commande = (new Commandes())->setClients(self::avecId((new Clients())->setNom('Revendeur')->setTypeClient('B2B'), 8));
        foreach (['SI-7K4P-2Q', 'N1MPORTEQUOI'] as $tape) {
            try {
                $saisie->appliquerCodeEnLigne($commande, $tape, new \DateTimeImmutable('2026-11-10'));
                self::fail('code accepté pour un revendeur');
            } catch (CodePromoRefuse $e) {
                self::assertSame('b2b', $e->refus);
                self::assertSame(CalculCodePromo::GENERIQUE.' '.CalculCodePromo::REFUS_CLIENT['b2b'], $e->getMessage());
            }
        }
        self::assertNull($commande->getCodeSaisi());
    }

    public function testLaPromotionACodesEstEnvoyeeActiveMaisNeSeMontrePasDansLEspace(): void
    {
        $p = (new Promotion())->setAvantageCommande(Promotion::COMMANDE_POURCENTAGE)->setValeurCommande(10);
        // Active en ligne (le site vérifie le code), mais pas dans « Mes promotions » (Promotions::pourLeClient : estSansCode).
        self::assertTrue(Promotion::activeEnLigne(true, $p));
        self::assertFalse($p->estSansCode());
        self::assertTrue(method_exists(Promotions::class, 'pourLeClient'));
    }

    /* ---------- Outils ---------- */

    private function ligne(Commandes $commande, Produits $produit, int $prix, int $quantite, int $tva, int $remise = 0): CommandesDetails
    {
        $l = (new CommandesDetails())->setProduit($produit)->setPrixUnitaire($prix)->setQuantite($quantite)->setRemise($remise)->setTva($tva);
        $commande->addCommandesDetail($l);
        $l->calculerTotaux();

        return $l;
    }

    /**
     * @param list<CommandesDetails> $lignes
     * @param array<int, int>        $parts
     */
    private function appliquer(Commandes $commande, array $lignes, array $parts): void
    {
        foreach ($lignes as $l) {
            $l->setRemiseCodePromo($parts[spl_object_id($l)] ?? 0)->calculerTotaux();
        }
        $commande->recalculerTotaux();
    }

    private function promotionCommande(string $avantage, float $valeur): Promotion
    {
        return self::avecId((new Promotion())->setNom('Code')->setAvantageCommande($avantage)->setValeurCommande($valeur)
            ->setDebut(new \DateTimeImmutable('2026-11-01'))->setFin(new \DateTimeImmutable('2026-11-30')), 40);
    }

    /** @param list<int> $debloquees */
    private function contexte(?string $code = null, array $debloquees = []): ContexteLigne
    {
        return new ContexteLigne(
            self::avecId((new Clients())->setNom('Client'), 1),
            $this->rollup, 10000, 0, 1, 10000, new \DateTimeImmutable('2026-11-10'), ['tous'], $code, [], [], null, $debloquees,
        );
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
