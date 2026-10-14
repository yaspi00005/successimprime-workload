<?php

namespace App\Tests\Unit\Service\Import;

use App\Service\Import\Colonnes;
use App\Service\Import\LecteurTableur;
use PHPUnit\Framework\TestCase;

/**
 * Lecture des fichiers CSV d'import : encodages, séparateurs, en-tête
 * (docs/import-donnees.md).
 */
final class LecteurTableurTest extends TestCase
{
    private const CSV = "Nom;Téléphone;Plafond\r\nTraoré Mariam;76 12 34 50;\"1 500 000\"\r\nCoulibaly Aïssata;66554433;\r\n";

    public function testCsvUtf8PointVirgule(): void
    {
        $lu = LecteurTableur::lireCsv(self::CSV);

        self::assertSame('UTF-8', $lu['encodage']);
        self::assertSame(';', $lu['separateur']);
        self::assertSame(['Nom', 'Téléphone', 'Plafond'], $lu['entetes']);
        self::assertSame(['Traoré Mariam', '76 12 34 50', '1 500 000'], $lu['lignes'][0]['valeurs']);
        self::assertSame(2, $lu['lignes'][0]['numero']);
        // Cellule vide en fin de ligne retirée.
        self::assertSame(['Coulibaly Aïssata', '66554433'], $lu['lignes'][1]['valeurs']);
    }

    public function testCsvAvecMarqueUtf8(): void
    {
        $lu = LecteurTableur::lireCsv("\xEF\xBB\xBF".self::CSV);

        self::assertSame('UTF-8', $lu['encodage']);
        self::assertSame('Nom', $lu['entetes'][0]);
    }

    public function testCsvWindows1252DExcelFrancais(): void
    {
        $lu = LecteurTableur::lireCsv((string) mb_convert_encoding(self::CSV, 'Windows-1252', 'UTF-8'));

        self::assertSame('Windows-1252', $lu['encodage']);
        self::assertSame('Téléphone', $lu['entetes'][1]);
        self::assertSame('Traoré Mariam', $lu['lignes'][0]['valeurs'][0]);
        self::assertSame('Coulibaly Aïssata', $lu['lignes'][1]['valeurs'][0]);
    }

    public function testCsvVirguleEtGuillemets(): void
    {
        $lu = LecteurTableur::lireCsv("Désignation,Prix d'achat\n\"Vinyle blanc mat 1,37 m\",\"45000,00\"\n");

        self::assertSame(',', $lu['separateur']);
        self::assertSame(['Vinyle blanc mat 1,37 m', '45000,00'], $lu['lignes'][0]['valeurs']);
    }

    public function testCsvTabulation(): void
    {
        $lu = LecteurTableur::lireCsv("Fournisseur\tMontant\nSahel Supplies\t850 000\n");

        self::assertSame("\t", $lu['separateur']);
        self::assertSame(['Sahel Supplies', '850 000'], $lu['lignes'][0]['valeurs']);
    }

    public function testLignesVidesAvantEtApresLEntete(): void
    {
        $lu = LecteurTableur::lireCsv("\n;;\nCompte;Solde\n\nCaisse;185000\n");

        self::assertSame(['Compte', 'Solde'], $lu['entetes']);
        self::assertCount(1, $lu['lignes']);
        self::assertSame(5, $lu['lignes'][0]['numero']);
    }

    public function testFichierVide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LecteurTableur::lireCsv("\n\n");
    }

    public function testTropDeLignes(): void
    {
        $csv = "Nom;Téléphone\n".str_repeat("Client;76123456\n", LecteurTableur::LIGNES_MAX + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('plus de 5 000 lignes');
        LecteurTableur::lireCsv($csv);
    }

    public function testCinqMilleLignesPassent(): void
    {
        $csv = "Nom;Téléphone\n".str_repeat("Client;76123456\n", LecteurTableur::LIGNES_MAX);

        self::assertCount(LecteurTableur::LIGNES_MAX, LecteurTableur::lireCsv($csv)['lignes']);
    }

    public function testColonnesReconnuesSousToutesLeursFormes(): void
    {
        $colonnes = [
            ['cle' => 'nom', 'libelle' => 'Nom', 'alias' => ['client', 'raison sociale'], 'obligatoire' => true],
            ['cle' => 'telephone', 'libelle' => 'Téléphone', 'alias' => ['tel', 'portable'], 'obligatoire' => true],
            ['cle' => 'prixAchat', 'libelle' => 'Prix d\'achat', 'alias' => ['cout'], 'obligatoire' => false, 'droit' => 'ROLE_COUT_MARGE'],
        ];
        $tout = static fn (string $role): bool => true;

        $r = Colonnes::associer(['TÉL.', 'Raison sociale *', 'Couleur', 'Coût (F)'], $colonnes, $tout);
        self::assertSame(['telephone' => 0, 'nom' => 1, 'prixAchat' => 3], $r['index']);
        self::assertSame(['Couleur'], $r['inconnues']);
        self::assertSame([], $r['manquantes']);

        $r = Colonnes::associer(['Portable', 'Tel'], $colonnes, $tout);
        self::assertSame(['Nom'], $r['manquantes']);
        self::assertSame(['Tel'], $r['doublees']);
    }

    public function testColonneDeCoutsSansLeDroit(): void
    {
        $colonnes = [
            ['cle' => 'designation', 'libelle' => 'Désignation', 'alias' => [], 'obligatoire' => true],
            ['cle' => 'prixAchat', 'libelle' => 'Prix d\'achat', 'alias' => ['cout'], 'obligatoire' => false, 'droit' => 'ROLE_COUT_MARGE'],
        ];

        $r = Colonnes::associer(['Désignation', 'Prix d\'achat'], $colonnes, static fn (string $role): bool => 'ROLE_COUT_MARGE' !== $role);

        self::assertSame(['designation' => 0], $r['index']);
        self::assertSame(['Prix d\'achat'], $r['interdites']);
    }

    public function testLignesRangeesParCle(): void
    {
        $lignes = Colonnes::lignes([
            ['numero' => 2, 'valeurs' => [' 76 12 34 50 ', 'Traoré Mariam', 'ignoré']],
            ['numero' => 3, 'valeurs' => ['', '', 'seulement une colonne inconnue']],
        ], ['telephone' => 0, 'nom' => 1]);

        self::assertCount(1, $lignes);
        self::assertSame(['telephone' => '76 12 34 50', 'nom' => 'Traoré Mariam'], $lignes[0]->valeurs);
        self::assertSame(2, $lignes[0]->numero);
    }

    public function testClasseurExcel(): void
    {
        if (!LecteurTableur::xlsxLisible()) {
            self::markTestSkipped('Extension PHP zip absente : les .xlsx ne se lisent pas ici.');
        }
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->fromArray([['Nom', 'Téléphone', 'Montant', 'Échéance'], ['Traoré Mariam', 76123450, 1500000, null]]);
        $feuille->setCellValue('D2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTimeImmutable('2026-11-15')));
        $feuille->getStyle('D2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $chemin = (string) tempnam(sys_get_temp_dir(), 'import-test-');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($chemin);

        try {
            $lu = (new LecteurTableur())->lire($chemin, 'xlsx');
        } finally {
            @unlink($chemin);
        }

        self::assertSame(['Nom', 'Téléphone', 'Montant', 'Échéance'], $lu['entetes']);
        self::assertSame('Traoré Mariam', $lu['lignes'][0]['valeurs'][0]);
        self::assertSame('76123450', $lu['lignes'][0]['valeurs'][1]);
        self::assertSame('2026-11-15', $lu['lignes'][0]['valeurs'][3]);
    }

    public function testClasseurSansExtensionZip(): void
    {
        if (LecteurTableur::xlsxLisible()) {
            self::markTestSkipped('Extension PHP zip présente.');
        }
        $chemin = (string) tempnam(sys_get_temp_dir(), 'import-test-');
        file_put_contents($chemin, 'PK');

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Enregistrez le fichier en CSV');
            (new LecteurTableur())->lire($chemin, 'xlsx');
        } finally {
            @unlink($chemin);
        }
    }
}
