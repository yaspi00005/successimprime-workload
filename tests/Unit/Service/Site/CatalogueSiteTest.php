<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\CategorieProduit;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Service\Site\CatalogueSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogueSiteTest extends TestCase
{
    public function testPrixDeDepartAuPrixNormal(): void
    {
        $bache = $this->produit(1, 'Bâche', $this->categorie(1, 'Grand format'));
        $configs = [
            $this->config(11, $bache, 4500, 'metre_carre')->setPrixB2B(1000),
            $this->config(12, $bache, 3500, 'metre_carre')->setPrixB2B(900),
            $this->config(13, $bache, 0, 'metre_carre'),
        ];

        $depart = CatalogueSite::prixDepart($configs);

        self::assertSame(12, $depart['configuration']->getId());
        self::assertSame(3500, $depart['montant']);
        self::assertSame('le m²', $depart['unite']);
        self::assertSame(1, $depart['minimum']);
        self::assertNull(CatalogueSite::prixDepart([]));
    }

    public function testCategoriesDeDemoRegroupeesAvecLesVraies(): void
    {
        $grand = $this->categorie(1, 'Grand format');
        $grandDemo = $this->categorie(9, 'Grand format (démo)');
        $textile = $this->categorie(2, 'Textile');
        $banderole = $this->produit(1, 'Banderole', $grand);
        $bacheDemo = $this->produit(2, 'Bâche imprimée (démo)', $grandDemo);
        $rollup = $this->produit(3, 'Roll-up 85 × 200 cm', $grand);
        $rollupDemo = $this->produit(4, 'Roll-up 85 × 200 cm (démo)', $grandDemo);
        $tshirt = $this->produit(5, 'T-shirt DTF', $textile);
        $config = $this->config(51, $tshirt, 4000, 'unite')->setQuantiteMinimale(10)->setSupport((new Supports())->setNom('Coton'));

        $univers = CatalogueSite::regrouper([$banderole, $bacheDemo, $rollup, $rollupDemo, $tshirt], [$config]);

        self::assertSame(['Grand format', 'Textile'], array_column($univers, 'nom'));
        self::assertSame([1, 9], $univers[0]['ids']);
        // « (démo) » enlevé, et le roll-up de démonstration ne double pas le vrai.
        self::assertSame(['Banderole', 'Bâche imprimée', 'Roll-up 85 × 200 cm'], array_column($univers[0]['produits'], 'nom'));
        self::assertSame(['bache', 'bache', 'rollup'], array_column($univers[0]['produits'], 'illustration'));
        // Sans configuration chiffrable : pas de prix ni de lien vers le calculateur.
        self::assertNull($univers[0]['produits'][0]['prix']);
        self::assertNull($univers[0]['produits'][0]['configuration']);

        $produit = $univers[1]['produits'][0];
        self::assertSame(51, $produit['configuration']);
        self::assertSame(['montant' => 4000, 'unite' => 'l\'unité', 'minimum' => 10], $produit['prix']);
        self::assertSame('Coton', $produit['description']);
        self::assertSame('textile', $univers[1]['famille']);
    }

    public function testNomCommercialSurLaCarteEtDansLaRecherche(): void
    {
        $grand = $this->categorie(1, 'Grand format');
        $vinyle = $this->produit(1, 'Vinyle adhésif (démo)', $grand)->setNomCommercial('Autocollant');
        $bache = $this->produit(2, 'Bâche imprimée', $grand);
        $micro = $this->produit(3, 'Micro-perforé vitrine', $this->categorie(2, 'Vitrine'))->setNomCommercial('Vitrophanie');

        $univers = CatalogueSite::regrouper([$vinyle, $bache, $micro], []);
        // Le nom commercial d'abord, la désignation (sans « démo ») à côté ; rien à côté sans nom commercial.
        self::assertSame(['Autocollant', 'Bâche imprimée'], array_column($univers[0]['produits'], 'nom'));
        self::assertSame(['Vinyle adhésif', null], array_column($univers[0]['produits'], 'technique'));
        self::assertSame('vinyle', $univers[0]['produits'][0]['illustration']);

        // ?q= : l'un ou l'autre nom, l'univers, sans accents ni majuscules.
        $noms = static fn (array $u): array => array_merge(...array_map(static fn (array $x): array => array_column($x['produits'], 'nom'), $u));
        self::assertSame(['Autocollant'], $noms(CatalogueSite::rechercher($univers, 'autocollant')));
        self::assertSame(['Autocollant'], $noms(CatalogueSite::rechercher($univers, 'ADHESIF')));
        self::assertSame(['Vitrophanie'], $noms(CatalogueSite::rechercher($univers, 'micro perfore')));
        self::assertSame(['Autocollant', 'Bâche imprimée'], $noms(CatalogueSite::rechercher($univers, 'grand format')));
        self::assertSame([], CatalogueSite::rechercher($univers, 'casquette'));
        self::assertSame($univers, CatalogueSite::rechercher($univers, '  '));

        // Calculateur et assistant : « Autocollant (Vinyle adhésif) · … ».
        $config = $this->config(11, $vinyle, 6500, 'metre_carre')->setSupport((new Supports())->setNom('Vinyle blanc'));
        self::assertSame('Autocollant (Vinyle adhésif) · Vinyle blanc', CatalogueSite::libelle($config));
    }

    public function testUniversChoisiParSaCle(): void
    {
        $univers = [['cle' => 'grand-format', 'ids' => [1, 9]], ['cle' => 'textile', 'ids' => [2]]];

        self::assertSame(0, CatalogueSite::universChoisi($univers, 'grand-format'));
        self::assertSame(1, CatalogueSite::universChoisi($univers, 'textile'));
        self::assertNull(CatalogueSite::universChoisi($univers, 'vitrine'));
        self::assertNull(CatalogueSite::universChoisi($univers, ''));
        self::assertNull(CatalogueSite::universChoisi($univers, null));
        // Ancienne adresse ?categorie=9 : la clé de l'univers qui regroupe cette catégorie.
        self::assertSame('grand-format', CatalogueSite::cleDeCategorie($univers, 9));
        self::assertNull(CatalogueSite::cleDeCategorie($univers, 5));
    }

    public function testCleDUnivers(): void
    {
        self::assertSame('grand-format', CatalogueSite::cle('Grand format (démo)'));
        self::assertSame('signaletique-vitrine', CatalogueSite::cle('Signalétique & vitrine'));
    }

    public function testGroupesDuCalculateur(): void
    {
        $grand = $this->categorie(1, 'Grand format');
        $grandDemo = $this->categorie(9, 'Grand format (démo)');
        $configs = [
            $this->config(11, $this->produit(1, 'Bâche', $grand), 3500, 'metre_carre')->setSupport((new Supports())->setNom('Bâche 510 g')),
            $this->config(12, $this->produit(2, 'Vinyle (démo)', $grandDemo), 6500, 'metre_carre'),
        ];

        self::assertSame([['nom' => 'Grand format', 'cle' => 'grand-format', 'ids' => [1, 9], 'options' => [['id' => 11, 'nom' => 'Bâche · Bâche 510 g'], ['id' => 12, 'nom' => 'Vinyle']]]], CatalogueSite::groupes($configs));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function illustrationsProvider(): iterable
    {
        yield 'micro-perforé' => ['Micro-perforé vitrine', 'grand', 'microperfore'];
        yield 'kakémono' => ['Kakémono 80 × 180 cm', 'signaletique', 'rollup'];
        yield 'polo' => ['Polo personnalisé', 'textile', 'tshirt'];
        yield 'cartes' => ['Cartes de visite', 'petit', 'cartes'];
        yield 'flyers' => ['Flyers A5', 'petit', 'papier'];
        yield 'brochure avant le format A4' => ['Brochure A4 8 pages', 'petit', 'brochure'];
        yield 'affiches' => ['Affiches A3', 'petit', 'affiche'];
        yield 'inconnu, famille' => ['Mug photo', 'textile', 'tshirt'];
    }

    #[DataProvider('illustrationsProvider')]
    public function testIllustrationDuProduit(string $nom, string $famille, string $dessin): void
    {
        self::assertSame($dessin, CatalogueSite::illustration($nom, $famille));
    }

    public function testUniteDuPrix(): void
    {
        self::assertSame('le m²', CatalogueSite::unite('metre_carre'));
        self::assertSame('le forfait', CatalogueSite::unite('forfait'));
        self::assertSame('l\'unité', CatalogueSite::unite('inconnu'));
    }

    private function categorie(int $id, string $nom): CategorieProduit
    {
        $c = (new CategorieProduit())->setNom($nom)->setDescription('');
        (new \ReflectionProperty($c, 'id'))->setValue($c, $id);

        return $c;
    }

    private function produit(int $id, string $nom, CategorieProduit $categorie): Produits
    {
        $p = (new Produits())->setNom($nom)->setDescription('')->setCategorieProduit($categorie);
        (new \ReflectionProperty($p, 'id'))->setValue($p, $id);

        return $p;
    }

    private function config(int $id, Produits $produit, int $prix, string $mode): ProduitConfiguration
    {
        $c = (new ProduitConfiguration())->setProduit($produit)->setPrixBase($prix)->setModeCalcul($mode);
        (new \ReflectionProperty($c, 'id'))->setValue($c, $id);

        return $c;
    }
}
