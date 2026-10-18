<?php

namespace App\Tests\Unit\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\BonLivraisonLigne;
use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Entity\Format;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Service\Catalogue\NomCommercial;
use PHPUnit\Framework\TestCase;

/**
 * Nom commercial (demande de Yaya du 16/10/2026, docs/noms-commerciaux.md) :
 * « Vinyle adhésif » reste la désignation, « Autocollant » passe devant
 * chez le client ; la recherche trouve l'un ou l'autre.
 */
final class NomCommercialTest extends TestCase
{
    public function testNettoyageDeLaSaisie(): void
    {
        self::assertNull(NomCommercial::nettoyer(null));
        self::assertNull(NomCommercial::nettoyer("  \t "));
        self::assertNull(NomCommercial::nettoyer('<b></b>'));
        self::assertSame('Autocollant vitrine', NomCommercial::nettoyer("  Autocollant\u{200B}\n  <i>vitrine</i> "));
        self::assertSame(NomCommercial::LONGUEUR, mb_strlen((string) NomCommercial::nettoyer(str_repeat('é', 300))));

        $produit = (new Produits())->setNom('Vinyle adhésif')->setNomCommercial('  Autocollant  ');
        self::assertSame('Autocollant', $produit->getNomCommercial());
        self::assertNull($produit->setNomCommercial('')->getNomCommercial());
    }

    public function testClientDAbordLeNomCommercial(): void
    {
        self::assertSame(['nom' => 'Autocollant', 'technique' => 'Vinyle adhésif'], NomCommercial::client('Autocollant', 'Vinyle adhésif'));
        self::assertSame('Autocollant (Vinyle adhésif)', NomCommercial::texteClient('Autocollant', 'Vinyle adhésif'));
        // Sans nom commercial, ou s'il redit la désignation : la désignation seule, jamais de parenthèses vides.
        self::assertSame(['nom' => 'Vinyle adhésif', 'technique' => null], NomCommercial::client(null, 'Vinyle adhésif'));
        self::assertSame('Vinyle adhésif', NomCommercial::texteClient('', 'Vinyle adhésif'));
        self::assertSame('Bâche', NomCommercial::texteClient('BACHE', 'Bâche'));
    }

    public function testErpDAbordLaDesignation(): void
    {
        self::assertSame(['nom' => 'Vinyle adhésif', 'commercial' => 'Autocollant'], NomCommercial::interne('Autocollant', 'Vinyle adhésif'));
        self::assertSame('Vinyle adhésif (Autocollant)', NomCommercial::texteInterne('Autocollant', 'Vinyle adhésif'));
        self::assertSame('Vinyle adhésif', NomCommercial::texteInterne(null, 'Vinyle adhésif'));

        $article = (new Articles())->setDesignation('Rouleau vinyle 1,37 m')->setNomCommercial('Rouleau d\'autocollant');
        self::assertSame('Rouleau vinyle 1,37 m', $article->getNomTechnique());
        self::assertSame(['nom' => 'Rouleau vinyle 1,37 m', 'commercial' => 'Rouleau d\'autocollant'], NomCommercial::interneDe($article));
        self::assertSame(['nom' => '', 'commercial' => null], NomCommercial::interneDe(null));
    }

    public function testLigneDeDocument(): void
    {
        $vinyle = (new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant');
        $ligne = (new CommandesDetails())->setProduit($vinyle)->setDesignation('Vinyle adhésif (100 x 50 cm)');

        self::assertSame($vinyle, NomCommercial::elementDeLigne($ligne));
        self::assertSame(['nom' => 'Autocollant', 'technique' => 'Vinyle adhésif (100 x 50 cm)'], NomCommercial::ligne($ligne->getDesignation(), $vinyle));
        self::assertSame('Autocollant · Vinyle adhésif (100 x 50 cm)', NomCommercial::texteLigne($ligne->getDesignation(), $vinyle));
        // Une désignation qui porte déjà le nom commercial (commande en ligne, saisie à la main) reste seule.
        self::assertSame(['nom' => 'Autocollants vitrine', 'technique' => null], NomCommercial::ligne('Autocollants vitrine', $vinyle));
        self::assertSame(['nom' => 'Autocollant vitrine 2 m', 'technique' => null], NomCommercial::ligne('Autocollant vitrine 2 m', $vinyle));
        // Sans désignation : les noms du produit.
        self::assertSame(['nom' => 'Autocollant', 'technique' => 'Vinyle adhésif'], NomCommercial::ligne('', $vinyle));
        // Sans produit ni nom commercial : la désignation.
        self::assertSame(['nom' => 'Pose sur vitrine', 'technique' => null], NomCommercial::ligne('Pose sur vitrine', null));
    }

    public function testElementVenduParUneLigne(): void
    {
        $produit = (new Produits())->setNom('Bâche imprimée')->setNomCommercial('Banderole');
        $article = (new Articles())->setDesignation('Pied roll-up alu')->setNomCommercial('Pied de roll-up');

        self::assertSame($article, NomCommercial::elementDeLigne((new CommandesDetails())->setProduit($produit)->setArticle($article)->setTypeLigne(CommandesDetails::TYPE_ARTICLE)));
        self::assertNull(NomCommercial::elementDeLigne((new CommandesDetails())->setProduit($produit)->setTypeLigne(CommandesDetails::TYPE_LIBRE)));
        self::assertSame($produit, NomCommercial::elementDeLigne((new DevisDetails())->setProduit($produit)));
        // Bon de livraison : la ligne de commande qu'il livre.
        $bl = (new BonLivraisonLigne())->setCommandeDetail((new CommandesDetails())->setProduit($produit));
        self::assertSame($produit, NomCommercial::elementDeLigne($bl));
        self::assertNull(NomCommercial::elementDeLigne(new BonLivraisonLigne()));
    }

    public function testConfigurationDuCatalogue(): void
    {
        $config = (new ProduitConfiguration())
            ->setProduit((new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant'))
            ->setSupport((new Supports())->setNom('Vinyle blanc mat'))
            ->setFormat((new Format())->setNom('Sur mesure'));

        self::assertSame(['nom' => 'Autocollant · Vinyle blanc mat · Sur mesure', 'technique' => 'Vinyle adhésif', 'texte' => 'Autocollant (Vinyle adhésif) · Vinyle blanc mat · Sur mesure'], NomCommercial::configuration($config));

        // Un support au même nom commercial que son produit garde sa désignation (pas « Vitrophanie · Vitrophanie »).
        $micro = (new ProduitConfiguration())
            ->setProduit((new Produits())->setNom('Micro-perforé vitrine')->setNomCommercial('Vitrophanie'))
            ->setSupport((new Supports())->setNom('Micro-perforé')->setNomCommercial('Vitrophanie'));
        self::assertSame('Vitrophanie (Micro-perforé vitrine) · Micro-perforé', NomCommercial::texteConfiguration($micro));

        // Support seul à porter un nom commercial.
        $rollup = (new ProduitConfiguration())
            ->setProduit((new Produits())->setNom('Roll-up'))
            ->setSupport((new Supports())->setNom('Bâche 440 g roll-up')->setNomCommercial('Toile de roll-up'));
        self::assertSame(['nom' => 'Roll-up · Toile de roll-up', 'technique' => 'Bâche 440 g roll-up', 'texte' => 'Roll-up · Toile de roll-up (Bâche 440 g roll-up)'], NomCommercial::configuration($rollup));
        self::assertSame('—', NomCommercial::texteConfiguration(null));
    }

    public function testRechercheSansAccentsNiMajuscules(): void
    {
        $vinyle = (new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant');

        self::assertTrue(NomCommercial::elementCorrespond('autocollant', $vinyle));
        // Le pluriel de Yaya : « on pourra trouver les vinyles, on pourra trouver autocollants ».
        self::assertTrue(NomCommercial::elementCorrespond('autocollants', $vinyle));
        self::assertTrue(NomCommercial::elementCorrespond('vinyles', $vinyle));
        self::assertTrue(NomCommercial::correspond('panneaux', 'Panneau publicitaire'));
        self::assertSame('autocollant 85 x 200', NomCommercial::cle('Autocollants 85 x 200'));
        self::assertTrue(NomCommercial::elementCorrespond('VINYLE', $vinyle));
        self::assertTrue(NomCommercial::elementCorrespond('adhesif', $vinyle));
        self::assertTrue(NomCommercial::elementCorrespond('Adhésif', $vinyle));
        self::assertFalse(NomCommercial::elementCorrespond('banderole', $vinyle));
        self::assertFalse(NomCommercial::elementCorrespond('vinyle', null));
        self::assertTrue(NomCommercial::correspond('', 'n\'importe quoi'), 'une recherche vide trouve tout');
        self::assertTrue(NomCommercial::correspond('akylux', null, 'PVC expansé / Akylux'));
        self::assertSame('vinyle adhesif autocollant', NomCommercial::cle('Vinyle adhésif', null, '', 'Autocollant'));

        $type = (new TypesImpression())->setNom('Éco-solvant intérieur')->setNomCommercial('Impression intérieure');
        self::assertSame('Éco-solvant intérieur', $type->getNomTechnique());
        self::assertTrue(NomCommercial::elementCorrespond('eco solvant', $type));
        self::assertTrue(NomCommercial::elementCorrespond('interieure', $type));
    }
}
