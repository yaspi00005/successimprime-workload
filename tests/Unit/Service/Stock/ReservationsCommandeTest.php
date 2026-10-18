<?php

namespace App\Tests\Unit\Service\Stock;

use App\Entity\Articles;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitArticleStock;
use App\Entity\Produits;
use App\Entity\StockSorties;
use App\Service\NotificationService;
use App\Service\Stock\ReservationsCommande as R;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Débit du stock par commande (docs/stock-et-options.md, 18/10/2026).
 */
final class ReservationsCommandeTest extends TestCase
{
    private const BACHE = 7;

    public function testCommandeValideeReserveSaNomenclature(): void
    {
        self::assertSame(R::RESERVER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [self::BACHE => 12.0], []));
    }

    public function testDejaReserveALaBonneQuantiteNeChangeRien(): void
    {
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [self::BACHE => 12.0], [self::BACHE => 12.0]));
        // Les réservations sont enregistrées à 3 décimales.
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [self::BACHE => 1.0 / 3], [self::BACHE => 0.333]));
    }

    public function testQuantiteChangeeAvantProductionRecalcule(): void
    {
        self::assertSame(R::RESERVER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [self::BACHE => 20.0], [self::BACHE => 12.0]));
        self::assertSame(R::RESERVER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [self::BACHE => 12.0, 9 => 1.0], [self::BACHE => 12.0]));
    }

    public function testBrouillonOuEnAttenteNeReservePasEtLibere(): void
    {
        self::assertSame(R::GARDER, R::decision(false, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [], []));
        self::assertSame(R::LIBERER, R::decision(false, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [], [self::BACHE => 12.0]));
    }

    public function testLigneAnnuleeOuLivreeLibereCeQuiReste(): void
    {
        self::assertSame(R::LIBERER, R::decision(true, CommandesDetails::PRODUCTION_ANNULEE, false, [self::BACHE => 12.0], [self::BACHE => 12.0]));
        // Même si du stock est déjà sorti : on libère le reste, on ne remet rien.
        self::assertSame(R::LIBERER, R::decision(true, CommandesDetails::PRODUCTION_ANNULEE, true, [self::BACHE => 12.0], [self::BACHE => 2.0]));
        self::assertSame(R::LIBERER, R::decision(true, CommandesDetails::PRODUCTION_LIVREE, true, [self::BACHE => 12.0], [self::BACHE => 2.0]));
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_ANNULEE, false, [self::BACHE => 12.0], []));
    }

    public function testStockDejaSortiNeRecalculePlus(): void
    {
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_TERMINEE, true, [self::BACHE => 20.0], [self::BACHE => 2.0]));
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_EN_LIVRAISON, true, [self::BACHE => 20.0], []));
    }

    public function testProduitSansNomenclatureNeReserveRien(): void
    {
        self::assertSame(R::GARDER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [], []));
        self::assertSame(R::LIBERER, R::decision(true, CommandesDetails::PRODUCTION_A_PRODUIRE, false, [], [self::BACHE => 3.0]));
    }

    /**
     * Les chemins de création posent le statut ; seul isStatut() (comme
     * commandeEstValidee du comptoir) décide, avec la corbeille et la
     * validation interne de l'entreprise cliente.
     */
    public function testCommandeValideeSelonLeChemin(): void
    {
        // Comptoir confirmé, devis converti, conception payée.
        self::assertTrue(R::commandeValidee((new Commandes())->setStatut(true)));
        // Comptoir en brouillon, espace client, Mes produits, commande pour un client : à confirmer.
        self::assertFalse(R::commandeValidee((new Commandes())->setStatut(false)));
        self::assertFalse(R::commandeValidee((new Commandes())->setStatut(true)->setDeleted(true)));
        self::assertFalse(R::commandeValidee((new Commandes())->setStatut(true)->setValidationEntreprise(Commandes::VALIDATION_A_FAIRE)));
        self::assertFalse(R::commandeValidee((new Commandes())->setStatut(true)->setValidationEntreprise(Commandes::VALIDATION_REFUSEE)));
        self::assertTrue(R::commandeValidee((new Commandes())->setStatut(true)->setValidationEntreprise(Commandes::VALIDATION_ACCEPTEE)));
    }

    public function testLaNomenclatureSuffitSansLeDrapeauGestionDeStock(): void
    {
        $bache = self::article(self::BACHE, 'Bâche 440 g');
        $produit = (new Produits())->setGestionStock(false);
        $produit->addArticlesStock((new ProduitArticleStock())->setArticle($bache)->setCoefficient('1')->setModeCalcul(ProduitArticleStock::MODE_SURFACE));
        $ligne = (new CommandesDetails())->setProduit($produit)->setTypeLigne(CommandesDetails::TYPE_PRODUIT)->setQuantite(2)->setSurface('6');

        $besoins = $this->stock()->calculerBesoinsDetail($ligne);

        self::assertTrue($produit->debiteLeStock());
        self::assertEqualsWithDelta(12.0, $besoins[self::BACHE]['quantite'], 0.0001);
    }

    public function testArticleAssocieSeulDebiteAussi(): void
    {
        $produit = (new Produits())->setArticleStock(self::article(3, 'Support kakémono'));
        $ligne = (new CommandesDetails())->setProduit($produit)->setTypeLigne(CommandesDetails::TYPE_PRODUIT)->setQuantite(4);

        self::assertTrue($produit->debiteLeStock());
        self::assertEqualsWithDelta(4.0, $this->stock()->calculerBesoinsDetail($ligne)[3]['quantite'], 0.0001);
    }

    public function testProduitSansArticleLieEstSignale(): void
    {
        $produit = new Produits();
        self::assertFalse($produit->debiteLeStock());

        // Une liaison inactive ne compte pas.
        $produit->addArticlesStock((new ProduitArticleStock())->setArticle(self::article(1, 'Encre'))->setCoefficient('1')->setActif(false));
        self::assertFalse($produit->debiteLeStock());

        $ligne = (new CommandesDetails())->setProduit($produit)->setTypeLigne(CommandesDetails::TYPE_PRODUIT)->setQuantite(4);
        self::assertSame([], $this->stock()->calculerBesoinsDetail($ligne));
    }

    public function testLivraisonApresProductionNeSortPasUneDeuxiemeFois(): void
    {
        $sorties = $this->createMock(EntityRepository::class);
        $sorties->expects(self::once())->method('count')->with(self::callback(static fn (array $c): bool => StockSorties::ORIGINE_PRODUCTION === $c['origine']))->willReturn(1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(StockSorties::class)->willReturn($sorties);
        $em->expects(self::never())->method('persist');

        $produit = (new Produits())->setArticleStock(self::article(3, 'Support'));
        $ligne = (new CommandesDetails())->setProduit($produit)->setTypeLigne(CommandesDetails::TYPE_PRODUIT)->setQuantite(4);
        (new \ReflectionProperty($ligne, 'id'))->setValue($ligne, 12);

        (new StockService($em, $this->createStub(NotificationService::class)))
            ->consommerPourDetail($ligne, StockSorties::ORIGINE_LIVRAISON, 'LIV-DIRECT-000012', 4);
    }

    private function stock(): StockService
    {
        return new StockService($this->createStub(EntityManagerInterface::class), $this->createStub(NotificationService::class));
    }

    private static function article(int $id, string $designation): Articles
    {
        $a = (new Articles())->setDesignation($designation);
        (new \ReflectionProperty($a, 'id'))->setValue($a, $id);

        return $a;
    }
}
