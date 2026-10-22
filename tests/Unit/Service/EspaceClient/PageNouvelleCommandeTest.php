<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Commandes;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\EspaceClient\OptionsCommande;
use App\Service\EspaceClient\PageNouvelleCommande;
use PHPUnit\Framework\TestCase;

/** Ce que montrent les étapes (maquettes CO01, CO02) : prix pour qui les voit, rien pour un demandeur. */
final class PageNouvelleCommandeTest extends TestCase
{
    public function testCatalogueUneCarteParProduitAuPrixLeMoinsCher(): void
    {
        [$b440, $b510, $micro] = $this->matieres();

        $catalogue = PageNouvelleCommande::catalogue([$b510, $b440, $micro], false, true);
        self::assertCount(1, $catalogue['produits']);
        self::assertSame(11, $catalogue['produits'][0]['config'], 'la configuration la moins chère');
        self::assertSame('dès 3 500 F / m²', $catalogue['produits'][0]['prix']);
        self::assertSame(3, $catalogue['produits'][0]['matieres']);
        // B2B : le prix de la grille revendeur.
        self::assertSame('dès 3 000 F / m²', PageNouvelleCommande::catalogue([$b440, $b510], true, true)['produits'][0]['prix']);

        // Demandeur : pas de prix, seulement l'unité de vente.
        $sansPrix = PageNouvelleCommande::catalogue([$b440, $b510], false, false)['produits'][0];
        self::assertNull($sansPrix['prix']);
        self::assertSame('sur mesure, au m²', $sansPrix['info']);
    }

    public function testNomCommercialDAbordLaDesignationACote(): void
    {
        [$b440, $b510, $micro] = $this->matieres();
        $b440->getProduit()?->setNomCommercial('Banderole');
        $micro->getSupport()?->setNomCommercial('Vitrophanie');

        $carte = PageNouvelleCommande::catalogue([$b440, $b510, $micro], false, true)['produits'][0];
        self::assertSame(['Banderole', 'Bâche imprimée'], [$carte['nom'], $carte['technique']]);
        self::assertSame(['Bâche 440 g', 'Bâche 510 g', 'Vitrophanie (Micro-perforé)'], array_column(PageNouvelleCommande::matieres([$b440, $b510, $micro], false, true), 'titre'));

        // Sans nom commercial : la désignation seule.
        [$seule] = $this->matieres();
        self::assertNull(PageNouvelleCommande::catalogue([$seule], false, true)['produits'][0]['technique']);
    }

    public function testMatieresAvecSupplementSeulementPourQuiVoitLesMontants(): void
    {
        [$b440, $b510, $micro] = $this->matieres();

        $fiches = PageNouvelleCommande::matieres([$b440, $b510, $micro], false, true);
        self::assertSame(['Bâche 440 g', 'Bâche 510 g', 'Micro-perforé'], array_column($fiches, 'titre'));
        self::assertSame([null, '+700 F / m²', '+1 500 F / m²'], array_column($fiches, 'supplement'));
        self::assertSame('+300 F/m²', $fiches[0]['finitions'][0]['detail']);
        self::assertTrue($fiches[0]['finitions'][0]['oeillets']);
        self::assertTrue($fiches[0]['finitions'][0]['parDefaut']);

        $demandeur = PageNouvelleCommande::matieres([$b440, $b510, $micro], false, false);
        self::assertSame([null, null, null], array_column($demandeur, 'supplement'));
        self::assertSame('en option', $demandeur[0]['finitions'][0]['detail']);
        self::assertStringNotContainsString('F', json_encode($demandeur, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testRecapitulatifSansMontantPourUnDemandeur(): void
    {
        [$b440] = $this->matieres();
        $ligne = NouvelleCommande::construireLigne($b440, 2, 300, 100, [101], false);
        $commande = NouvelleCommande::assembler($ligne, new OptionsCommande(Commandes::DELAI_STANDARD, 30, true, 2000, 'Adresse', null));

        $payeur = PageNouvelleCommande::recapitulatif($commande, $ligne, $b440, true, 48, 50);
        self::assertSame('Bâche 440 g · 3 × 1 m', $payeur['titre']);
        self::assertSame(['libelle' => 'Surface', 'valeur' => '6 m² × 3 500 F'], $payeur['lignes'][0]);
        // 6 m² × 3 500 + œillets 6 m² × 300 + livraison 2 000.
        self::assertSame(24800, $payeur['total']);
        self::assertSame(['pourcentage' => 50, 'maintenant' => 12400, 'ensuite' => 12400], $payeur['acompte']);

        $demandeur = PageNouvelleCommande::recapitulatif($commande, $ligne, $b440, false, 48);
        self::assertArrayNotHasKey('total', $demandeur);
        self::assertArrayNotHasKey('acompte', $demandeur);
        self::assertSame(['Surface' => '6 m²', 'Œillets tous les 50 cm' => 'choisie', 'Délai' => 'standard 48 h', 'Livraison à Bamako' => 'oui'], array_column($demandeur['lignes'], 'valeur', 'libelle'));
    }

    public function testOeillets(): void
    {
        self::assertTrue(PageNouvelleCommande::estOeillets('Œillets tous les 50 cm'));
        self::assertTrue(PageNouvelleCommande::estOeillets('oeillets'));
        self::assertFalse(PageNouvelleCommande::estOeillets('Ourlet renforcé'));
        self::assertSame(30, PageNouvelleCommande::espacementOeillets('Œillets tous les 30 cm'));
        self::assertSame(50, PageNouvelleCommande::espacementOeillets('Œillets'));
        // 3 m × 1 m, tous les 50 cm : 6 + 6 + 2 + 2.
        self::assertSame(16, PageNouvelleCommande::nombreOeillets(300, 100));
        self::assertSame(0, PageNouvelleCommande::nombreOeillets(0, 100));
    }

    /** @return array{ProduitConfiguration, ProduitConfiguration, ProduitConfiguration} */
    private function matieres(): array
    {
        $produit = (new Produits())->setNom('Bâche imprimée');
        (new \ReflectionProperty(Produits::class, 'id'))->setValue($produit, 1);
        $configs = [];
        foreach ([[11, 'Bâche 440 g', 3500, 3000], [12, 'Bâche 510 g', 4200, 3600], [13, 'Micro-perforé', 5000, 4300]] as [$id, $support, $prix, $b2b]) {
            $c = (new ProduitConfiguration())->setProduit($produit)->setSupport((new Supports())->setNom($support))
                ->setModeDimension('libre')->setModeCalcul('metre_carre')->setPrixBase($prix)->setPrixB2B($b2b);
            (new \ReflectionProperty(ProduitConfiguration::class, 'id'))->setValue($c, $id);
            $oeillets = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Œillets tous les 50 cm'))
                ->setPayante(true)->setPrix(300)->setModeCalcul('metre_carre')->setSelectionneeParDefaut(true)->setActive(true);
            (new \ReflectionProperty(ProduitConfigurationFinition::class, 'id'))->setValue($oeillets, $id + 90);
            $c->addConfigurationFinition($oeillets);
            $configs[] = $c;
        }

        return $configs;
    }
}
