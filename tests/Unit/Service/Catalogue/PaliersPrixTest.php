<?php

namespace App\Tests\Unit\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\Clients;
use App\Entity\CommandesDetails;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Service\Catalogue\CoutProduit;
use App\Service\Catalogue\EditionProduit;
use App\Service\Catalogue\PaliersPrix;
use App\Service\Catalogue\TarifClient;
use App\Service\EspaceClient\NouvelleCommande;
use PHPUnit\Framework\TestCase;

/**
 * Paliers de prix, grilles et coût incomplet (docs/produits.md,
 * « Paliers de prix et grilles »).
 */
final class PaliersPrixTest extends TestCase
{
    private function flyers(): ProduitConfiguration
    {
        // De 1 à 100 pièces : 150 F ; de 101 à 200 : 120 F ; 201 et plus : −30 %.
        return (new ProduitConfiguration())->setProduit((new Produits())->setNom('Flyers A5'))
            ->setModeDimension('aucune')->setModeCalcul('unite')->setPrixBase(150)
            ->setPaliersQuantite([['de' => 101, 'prix' => 120], ['de' => '201', 'remise' => '30']]);
    }

    private function bache(): ProduitConfiguration
    {
        // 5 000 F le m², −10 % dès 50 pièces, et −5 % dès 10 m², −12 % dès 20 m².
        return (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(5000)
            ->setPaliersQuantite([['de' => 50, 'remise' => 10]])
            ->setRemisesSurface([['m2' => '20', 'remise' => 12], ['m2' => '10', 'remise' => '5']]);
    }

    public function testLesPaliersSontNettoyesEtTries(): void
    {
        $p = PaliersPrix::normaliserQuantite([
            ['de' => '201', 'remise' => '30,5'],
            ['de' => 101, 'prix' => '120'],
            ['de' => 1, 'prix' => 100],          // « dès 1 » : c'est le prix de la configuration
            ['de' => 300, 'prix' => '', 'remise' => ''], // incomplet
            ['de' => 400, 'remise' => 150],      // borné à 90 %
            'n\'importe quoi',
        ]);
        self::assertSame([
            ['de' => 101, 'prix' => 120, 'remise' => null],
            ['de' => 201, 'prix' => null, 'remise' => 30.5],
            ['de' => 400, 'prix' => null, 'remise' => 90.0],
        ], $p);

        self::assertSame([['m2' => 10.0, 'prix' => null, 'remise' => 5.0], ['m2' => 20.0, 'prix' => null, 'remise' => 12.0]], $this->bache()->getRemisesSurface());
        self::assertSame([], PaliersPrix::normaliserSurface([['m2' => 0, 'remise' => 5], ['m2' => 10, 'remise' => 0]]));
    }

    public function testLePalierDeQuantiteDonneLePrixUnitaire(): void
    {
        $c = $this->flyers();
        self::assertSame(0.0, PaliersPrix::volumeConfiguration($c, 100, 0)['taux']);
        $v = PaliersPrix::volumeConfiguration($c, 101, 0);
        self::assertSame(20.0, $v['taux']);
        self::assertSame(PaliersPrix::SOURCE_QUANTITE, $v['source']);
        self::assertSame(120, PaliersPrix::net(150, $v['taux'], 0));
        self::assertSame(105, PaliersPrix::net(150, PaliersPrix::volumeConfiguration($c, 500, 0)['taux'], 0));
        self::assertSame(['source' => 'quantite', 'seuil' => 201, 'taux' => 30.0], $v['prochain']);

        self::assertSame([
            ['de' => 1, 'a' => 100, 'prix' => 150, 'taux' => 0.0, 'remise' => 0],
            ['de' => 101, 'a' => 200, 'prix' => 120, 'taux' => 20.0, 'remise' => 30],
            ['de' => 201, 'a' => null, 'prix' => 105, 'taux' => 30.0, 'remise' => 45],
        ], PaliersPrix::tranches($c->getPaliersQuantite(), 150));
    }

    /** Maquette PR02 : bâche 440 g à 3 500 F le m², paliers tapés en prix au m² ; le prix de base ne bouge pas. */
    public function testLePalierDeSurfaceAuPrixDuM2DevientUneRemise(): void
    {
        $surface = PaliersPrix::normaliserSurface([['m2' => '50', 'prix' => '2 900'], ['m2' => 10, 'prix' => 3200], ['m2' => 30, 'remise' => '']]);
        self::assertSame([['m2' => 10.0, 'prix' => 3200, 'remise' => null], ['m2' => 50.0, 'prix' => 2900, 'remise' => null]], $surface);

        // 130 bâches de 150 × 200 cm : 390 m² sur la ligne, palier « 50 m² et plus ».
        $v = PaliersPrix::volume([], $surface, 3500, 130, 390.0, 'metre_carre');
        self::assertSame(PaliersPrix::SOURCE_SURFACE, $v['source']);
        self::assertSame(2900, PaliersPrix::net(3500, $v['taux'], 0));
        self::assertSame(600, PaliersPrix::remiseParUnite(3500, $v['taux'], 0));
        // 6 m² : pas encore de palier, le prochain est à 10 m² (−8,6 %).
        $v = PaliersPrix::volume([], $surface, 3500, 2, 6.0, 'metre_carre');
        self::assertSame(0.0, $v['taux']);
        self::assertSame(['source' => 'surface', 'seuil' => 10.0, 'taux' => 8.6], $v['prochain']);

        self::assertSame([
            ['de' => null, 'a' => 9.0, 'avant' => null, 'prix' => 3500, 'taux' => 0.0, 'remise' => 0],
            ['de' => 10.0, 'a' => 49.0, 'avant' => null, 'prix' => 3200, 'taux' => 8.6, 'remise' => 300],
            ['de' => 50.0, 'a' => null, 'avant' => null, 'prix' => 2900, 'taux' => 17.1, 'remise' => 600],
        ], PaliersPrix::tranchesSurface($surface, 3500));
        self::assertSame(2.5, PaliersPrix::tranchesSurface([['m2' => 2.5, 'prix' => null, 'remise' => 5.0]], 1000)[0]['avant']);
    }

    public function testLaLigneEnLigneSuitLePalier(): void
    {
        $l100 = NouvelleCommande::construireLigne($this->flyers(), 100, null, null, [], false);
        self::assertSame(15000, $l100->getTotalTtc());

        $l150 = NouvelleCommande::construireLigne($this->flyers(), 150, null, null, [], false);
        self::assertSame(150, (int) $l150->getPrixUnitaire(), 'la facture garde le prix normal');
        self::assertSame(30, $l150->getRemise(), 'le palier devient la remise par pièce');
        self::assertSame(18000, $l150->getTotalTtc()); // 150 × 120 F

        // Revendeur : son prix revendeur, sans palier.
        $revendeur = $this->flyers()->setPrixB2B(100);
        $b2b = NouvelleCommande::construireLigne($revendeur, 150, null, null, [], true);
        self::assertSame(0, $b2b->getRemise());
        self::assertSame(15000, $b2b->getTotalTtc());
    }

    public function testLaRemiseDeSurfaceEtLePalierNeSeCumulentPas(): void
    {
        $c = $this->bache();
        // 2 bâches de 3 × 2 m = 12 m² : −5 % (surface), pas de palier de pièces.
        $l = NouvelleCommande::construireLigne($c, 2, 300, 200, [], false);
        self::assertSame(250, $l->getRemise());
        self::assertSame(57000, $l->getTotalTtc()); // 12 × 4 750

        // 4 bâches = 24 m² : −12 %.
        self::assertSame(105600, NouvelleCommande::construireLigne($c, 4, 300, 200, [], false)->getTotalTtc());

        // 60 petites affiches de 0,5 × 0,5 m = 15 m² : palier pièces −10 % contre surface −5 % : le meilleur, pas les deux.
        $v = PaliersPrix::volumeConfiguration($c, 60, 15.0);
        self::assertSame(10.0, $v['taux']);
        self::assertSame(PaliersPrix::SOURCE_QUANTITE, $v['source']);
        $petites = NouvelleCommande::construireLigne($c, 60, 50, 50, [], false);
        self::assertSame(67500, $petites->getTotalTtc()); // 15 m² × 4 500

        // Juste sous 10 m² : prix normal ; la prochaine remise est annoncée.
        $v = PaliersPrix::volumeConfiguration($c, 1, 9.5);
        self::assertSame(0.0, $v['taux']);
        self::assertSame(['source' => 'surface', 'seuil' => 10.0, 'taux' => 5.0], $v['prochain']);

        // Un produit qui n'est pas au m² ignore les remises de surface.
        self::assertSame(0.0, PaliersPrix::volume([], [['m2' => 1.0, 'remise' => 5.0]], 100, 1, 50.0, 'unite')['taux']);
    }

    public function testCumulPalierPuisGrillePuisPrixNegocie(): void
    {
        $c = $this->flyers();
        // Partenaire −5 % sur le prix du palier : 120 × 0,95 = 114 F, soit 36 F de remise par pièce.
        $r = TarifClient::calculer(150, $c, 150, 0, false, 5.0, null);
        self::assertSame(36, $r['remise']);
        self::assertSame(20.0, $r['volume']['taux']);

        // Un prix négocié plus bas l'emporte (on garde le plus bas des deux, sans cumul).
        self::assertSame(50, TarifClient::calculer(150, $c, 150, 0, false, 5.0, 100)['remise']);
        // Plus haut que le prix du palier et de la grille : le client garde le meilleur prix.
        self::assertSame(36, TarifClient::calculer(150, $c, 150, 0, false, 5.0, 140)['remise']);

        // Revendeur : ni palier ni grille, son prix négocié seulement.
        $b2b = TarifClient::calculer(100, $c, 500, 0, true, 10.0, null);
        self::assertSame(0, $b2b['remise']);
        self::assertSame(0.0, $b2b['volume']['taux']);
        self::assertSame(10, TarifClient::calculer(100, $c, 500, 0, true, 10.0, 90)['remise']);

        // Sans palier, la grille seule (Association −10 %).
        self::assertSame(15, TarifClient::calculer(150, null, 1, 0, false, 10.0, null)['remise']);
    }

    public function testLaGrilleDuClient(): void
    {
        $client = new Clients();
        self::assertSame('B2C', $client->getGrille());
        $client->setGrilleTarifaire('Association');
        self::assertSame('ASSOCIATION', $client->getGrille());
        $client->setTypeClient('B2B');
        self::assertSame('B2B', $client->getGrille(), 'le revendeur l\'emporte');
        self::assertNull((new Clients())->setGrilleTarifaire('vip')->getGrilleTarifaire());
    }

    public function testLeResumePourLHistoriqueTientEnTrenteCaracteres(): void
    {
        $c = $this->bache();
        $r = EditionProduit::resumePaliers($c->getPaliersQuantite(), $c->getRemisesSurface());
        self::assertSame('50:10% 10m²:5% 20m²:12%', $r);
        self::assertLessThanOrEqual(30, mb_strlen((string) EditionProduit::resumePaliers(array_fill(0, 8, ['de' => 1000, 'prix' => 99999, 'remise' => null]), [])));
        self::assertNull(EditionProduit::resumePaliers([], []));
    }

    public function testUnPrixDAchatManquantRendLeCoutIncomplet(): void
    {
        $bache = (new Articles())->setDesignation('Bâche 510 g')->setCoutMoyen(650);
        $oeillets = (new Articles())->setDesignation('Œillets laiton');
        $produit = (new Produits())->setModeCalcul('metre_carre');
        $produit->addArticlesStock((new ProduitArticleStock())->setArticle($bache)->setCoefficient(1)->setModeCalcul(ProduitArticleStock::MODE_SURFACE));
        $produit->addArticlesStock((new ProduitArticleStock())->setArticle($oeillets)->setCoefficient(4)->setModeCalcul(ProduitArticleStock::MODE_SURFACE));

        $d = CoutProduit::detail($produit);
        self::assertSame(650, $d['total'], 'le total ne compte que ce qui a un prix');
        self::assertFalse($d['complet']);
        self::assertSame([['id' => null, 'designation' => 'Œillets laiton']], $d['prixAchatManquants']);

        $oeillets->setPrixAchat(25);
        $d = CoutProduit::detail($produit);
        self::assertTrue($d['complet']);
        self::assertSame(750, $d['total']);
        self::assertSame([], $d['prixAchatManquants']);

        // Coût saisi à la main : il fait foi.
        $service = (new Produits())->setModeCalcul('forfait')->setCoutRevientSaisi(2000);
        self::assertTrue(CoutProduit::detail($service)['complet']);
    }

    public function testLaRemiseDeVolumeSeRecalculeEnPiecesAuM2(): void
    {
        // Une ligne sans palier garde sa remise (ajustement de l'Atelier 3D, etc.).
        $sans = (new ProduitConfiguration())->setProduit(new Produits())->setModeCalcul('unite')->setPrixBase(100)->setModeDimension('aucune');
        $ligne = NouvelleCommande::construireLigne($sans, 10, null, null, [], false)->setRemise(7);
        PaliersPrix::appliquerVolume($ligne, $sans, false);
        self::assertSame(7, $ligne->getRemise());

        self::assertSame('Palier dès 101 pièces : −20 %', PaliersPrix::libelle(PaliersPrix::volumeConfiguration($this->flyers(), 150, 0)));
        self::assertSame('Remise dès 10 m² : −5 %', PaliersPrix::libelle(PaliersPrix::volumeConfiguration($this->bache(), 1, 12.0)));
        self::assertNull(PaliersPrix::libelle(PaliersPrix::volumeConfiguration($this->flyers(), 2, 0)));
        self::assertInstanceOf(CommandesDetails::class, $ligne);
    }
}
