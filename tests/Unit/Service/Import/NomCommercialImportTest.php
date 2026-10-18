<?php

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\Colonnes;
use App\Service\Import\ImportArticles;
use App\Service\Import\ImportProduits;
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
}
