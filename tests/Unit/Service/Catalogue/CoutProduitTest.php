<?php

namespace App\Tests\Unit\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\Machines;
use App\Entity\ProduitArticleStock;
use App\Entity\Produits;
use App\Service\Catalogue\CoutProduit;
use App\Service\Catalogue\GardeFousPrix;
use PHPUnit\Framework\TestCase;

/**
 * Coût de revient et marge des produits (docs/produits.md).
 */
final class CoutProduitTest extends TestCase
{
    public function testLaMargeEstEnPourcentageDuPrix(): void
    {
        self::assertSame(59, CoutProduit::marge(3500, 1433));
        self::assertSame(0, CoutProduit::marge(1000, 1000));
        self::assertSame(-50, CoutProduit::marge(1000, 1500));
        self::assertNull(CoutProduit::marge(null, 100));
        self::assertNull(CoutProduit::marge(1000, null));
        self::assertNull(CoutProduit::marge(0, 100));
    }

    public function testLeNiveauSuitLaCibleEtLeMinimum(): void
    {
        self::assertSame('bonne', CoutProduit::niveau(30, 30, 25));
        self::assertSame('moyenne', CoutProduit::niveau(27, 30, 25));
        self::assertSame('faible', CoutProduit::niveau(24, 30, 25));
        self::assertSame('inconnue', CoutProduit::niveau(null, 30, 25));
    }

    public function testLaMargeMoyenneEstPondereeParLesVentes(): void
    {
        $m = CoutProduit::margeMoyenne([
            ['marge' => 60, 'ventes' => 300000],
            ['marge' => 20, 'ventes' => 100000],
            ['marge' => 90, 'ventes' => 0],
            ['marge' => null, 'ventes' => 50000],
        ]);
        self::assertSame(['marge' => 50, 'ponderee' => true], $m);

        // Sans vente : moyenne simple des marges connues.
        self::assertSame(['marge' => 40, 'ponderee' => false], CoutProduit::margeMoyenne([['marge' => 60, 'ventes' => 0], ['marge' => 20, 'ventes' => 0]]));
        self::assertSame(['marge' => null, 'ponderee' => false], CoutProduit::margeMoyenne([]));
    }

    public function testLeCoutAdditionneMatiereEncreEtMachine(): void
    {
        $bache = (new Articles())->setDesignation('Bâche 510 g')->setCoutMoyen(650);
        $machine = (new Machines())->setNom('Solvant 3,20 m')->setCoutEncreM2(650)->setCoutHoraire(6000)->setVitesseM2Heure(45);
        $produit = (new Produits())->setModeCalcul('metre_carre')->setMachine($machine);
        $produit->addArticlesStock((new ProduitArticleStock())->setArticle($bache)->setCoefficient(1)->setModeCalcul(ProduitArticleStock::MODE_SURFACE));

        $d = CoutProduit::detail($produit);

        self::assertSame(CoutProduit::SOURCE_CALCULE, $d['source']);
        self::assertSame([650, 650, 133], array_column($d['lignes'], 'cout'));
        self::assertSame(1433, $d['total']);
        self::assertSame([], $d['manquants']);
    }

    public function testLeCoutSaisiNeSertQueSiRienNeSeCalcule(): void
    {
        $service = (new Produits())->setModeCalcul('forfait')->setCoutRevientSaisi(2000);
        $d = CoutProduit::detail($service);
        self::assertSame(CoutProduit::SOURCE_SAISI, $d['source']);
        self::assertSame(2000, $d['total']);

        $sansRien = (new Produits())->setModeCalcul('forfait');
        self::assertNull(CoutProduit::detail($sansRien)['total']);
    }

    public function testUnPrixNegocieHorsGardeFousDemandeLAccord(): void
    {
        // Remise de 10 % tout juste : accepté ; au-delà : accord de la direction.
        self::assertSame([], GardeFousPrix::controler(5000, 4500, 1600, 10, 25));
        self::assertSame([GardeFousPrix::RAISON_REMISE], GardeFousPrix::controler(6500, 5600, 2450, 10, 25));
        // Marge sous le minimum, même avec une petite remise.
        self::assertSame([GardeFousPrix::RAISON_MARGE], GardeFousPrix::controler(2000, 1900, 1600, 10, 25));
        // Coût inconnu : seule la remise compte.
        self::assertSame([], GardeFousPrix::controler(2000, 1900, null, 10, 25));
        self::assertSame(13.8, GardeFousPrix::remise(6500, 5600));
    }
}
