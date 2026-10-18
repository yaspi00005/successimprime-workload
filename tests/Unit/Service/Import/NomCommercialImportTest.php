<?php

namespace App\Tests\Unit\Service\Import;

use App\Entity\ImportDonnees;
use App\Entity\Produits;
use App\Service\Import\Colonnes;
use App\Service\Import\ContexteImport;
use App\Service\Import\ImportArticles;
use App\Service\Import\ImportProduits;
use App\Service\Import\LigneImport;
use App\Service\Import\ModeleImport;
use App\Service\Import\References;
use App\Service\Import\TypeImport;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Colonne facultative « Nom commercial » des imports d'articles du
 * catalogue et du stock (docs/noms-commerciaux.md, docs/import-donnees.md).
 */
final class NomCommercialImportTest extends TestCase
{
    /** @return list<TypeImport> */
    private function types(): array
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return [new ImportProduits($em, new References($em)), new ImportArticles($em, new References($em))];
    }

    public function testEnTeteEtSesAlias(): void
    {
        $tous = static fn (string $r): bool => true;
        foreach ($this->types() as $type) {
            $premiere = $type instanceof ImportProduits ? 'Nom' : 'Désignation';
            foreach (['Nom commercial', 'NOM USUEL', 'Appellation', 'nom courant'] as $entete) {
                $a = Colonnes::associer([$premiere, $entete], $type->colonnes(), $tous);
                self::assertSame(1, $a['index']['nomCommercial'] ?? null, $type->cle().' : '.$entete);
                self::assertSame([], $a['inconnues'], $type->cle().' : '.$entete);
            }
            // Facultative : un fichier sans elle reste bon.
            $sans = Colonnes::associer([$premiere], $type->colonnes(), $tous);
            self::assertArrayNotHasKey('nomCommercial', $sans['index']);
            self::assertNotContains('Nom commercial', $sans['manquantes']);
            // Le modèle Excel la propose, juste après le nom.
            $entetes = array_map(ModeleImport::entete(...), ModeleImport::colonnes($type, $tous));
            self::assertSame('Nom commercial', $entetes[1], $type->cle());
        }
    }

    /**
     * Annuler un import de prix dont la case « Nom commercial » était vide :
     * le nom changé depuis (page Noms commerciaux) ne bloque pas
     * l'annulation et n'est pas écrasé ; les prix reviennent.
     */
    public function testAnnulationGardeUnNomCommercialQueLImportNAvaitPasChange(): void
    {
        $produit = (new Produits())->setNom('Vinyle adhésif')->setPrixBase(5000.0)->setNomCommercial('Vitrophanie');
        $import = $this->importMaj(['prixBase' => 4000, 'prixB2B' => null, 'nomCommercial' => 'Autocollant'], ['prixBase' => 5000, 'prixB2B' => null, 'nomCommercial' => 'Autocollant']);
        $type = $this->importProduits($produit);

        self::assertSame([], $type->blocagesAnnulation($import));
        $type->annuler($import, new ContexteImport(null, 'Test', new \DateTimeImmutable(), static fn (string $r): bool => true));
        self::assertSame(4000.0, $produit->getPrixBase());
        self::assertSame('Vitrophanie', $produit->getNomCommercial());
    }

    /** Un nom commercial donné par l'import revient à l'ancien à l'annulation ; changé depuis, il bloque. */
    public function testAnnulationRemetLeNomCommercialChangeParLImport(): void
    {
        $produit = (new Produits())->setNom('Vinyle adhésif')->setPrixBase(5000.0)->setNomCommercial('Autocollant');
        $import = $this->importMaj(['prixBase' => 5000, 'prixB2B' => null, 'nomCommercial' => null], ['prixBase' => 5000, 'prixB2B' => null, 'nomCommercial' => 'Autocollant']);
        $type = $this->importProduits($produit);

        self::assertSame([], $type->blocagesAnnulation($import));
        $type->annuler($import, new ContexteImport(null, 'Test', new \DateTimeImmutable(), static fn (string $r): bool => true));
        self::assertNull($produit->getNomCommercial());

        $produit->setNomCommercial('Sticker');
        self::assertCount(1, $type->blocagesAnnulation($import));
    }

    /**
     * @param array<string, int|string|null> $avant
     * @param array<string, int|string|null> $apres
     */
    private function importMaj(array $avant, array $apres): ImportDonnees
    {
        return (new ImportDonnees('produits', 'catalogue.xlsx', 'empreinte', 1, null, 'Test', new \DateTimeImmutable()))
            ->noter(['ligne' => 2, 'statut' => LigneImport::MAJ, 'id' => 7, 'avant' => $avant, 'apres' => $apres]);
    }

    private function importProduits(Produits $produit): ImportProduits
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($produit);

        return new ImportProduits($em, new References($em));
    }
}
