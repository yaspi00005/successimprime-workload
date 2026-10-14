<?php

namespace App\Service\Statistiques\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * Classeur Excel d'un export de statistiques : une feuille propre par
 * tableau, avec le titre, l'entreprise et la période en tête, la ligne
 * d'en-tête figée et filtrable, et les formats (F CFA, %, m², dates).
 */
final class ExportExcel
{
    public const FORMATS_EXCEL = [
        'entier' => '#,##0',
        'fcfa' => '#,##0 "F"',
        'pourcent' => '0%',
        'm2' => '#,##0.0 "m²"',
        'date' => 'dd/mm/yyyy',
    ];

    private const BLEU = '0F4590';

    public function classeur(Export $export, string $entreprise, \DateTimeImmutable $editeLe, string $editePar): Spreadsheet
    {
        $classeur = new Spreadsheet();
        $classeur->getProperties()
            ->setCreator($entreprise)
            ->setTitle($export->titre)
            ->setSubject($export->periode);
        $classeur->removeSheetByIndex(0);

        $tableaux = [] === $export->tableaux ? [new Tableau($export->titre, ['Information' => 'texte'], [['Aucun chiffre à exporter avec vos droits.']])] : $export->tableaux;
        $noms = [];
        foreach ($tableaux as $tableau) {
            $feuille = $classeur->createSheet();
            $feuille->setTitle(self::nomFeuille($tableau->titre, $noms));
            $this->remplir($feuille, $export, $tableau, $entreprise, $editeLe, $editePar);
        }
        $classeur->setActiveSheetIndex(0);

        return $classeur;
    }

    public function reponse(Export $export, string $entreprise, \DateTimeImmutable $editeLe, string $editePar): Response
    {
        $flux = fopen('php://temp', 'w+');
        if (false === $flux) {
            throw new \RuntimeException('Impossible de générer le fichier Excel.');
        }
        (new Xlsx($this->classeur($export, $entreprise, $editeLe, $editePar)))->save($flux);
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);

        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => \sprintf('attachment; filename="%s"', $export->nomFichier('xlsx')),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Nom de feuille Excel : 31 caractères au plus, sans []:*?/\, unique.
     *
     * @param list<string> $dejaPris
     */
    public static function nomFeuille(string $titre, array &$dejaPris): string
    {
        $nom = trim((string) preg_replace(['/[\[\]:*?\/\\\\]+/', '/\s+/'], ' ', $titre));
        $nom = mb_substr('' === $nom ? 'Feuille' : $nom, 0, 31);
        $base = $nom;
        for ($i = 2; \in_array(mb_strtolower($nom), array_map('mb_strtolower', $dejaPris), true); ++$i) {
            $suffixe = ' ('.$i.')';
            $nom = mb_substr($base, 0, 31 - mb_strlen($suffixe)).$suffixe;
        }
        $dejaPris[] = $nom;

        return $nom;
    }

    /**
     * Valeur écrite dans la cellule : nombre pour les formats chiffrés
     * (un % devient une fraction pour le format 0 %), date Excel.
     */
    public static function valeurCellule(mixed $valeur, string $format): mixed
    {
        if (null === $valeur || '' === $valeur) {
            return null;
        }

        return match ($format) {
            'entier', 'fcfa' => is_numeric($valeur) ? (int) round((float) $valeur) : $valeur,
            'm2' => is_numeric($valeur) ? round((float) $valeur, 1) : $valeur,
            'pourcent' => is_numeric($valeur) ? round((float) $valeur / 100, 4) : $valeur,
            'date' => $valeur instanceof \DateTimeInterface ? Date::PHPToExcel($valeur) : $valeur,
            default => (string) $valeur,
        };
    }

    private function remplir(Worksheet $feuille, Export $export, Tableau $tableau, string $entreprise, \DateTimeImmutable $editeLe, string $editePar): void
    {
        $nbColonnes = max(1, \count($tableau->colonnes));
        $derniere = Coordinate::stringFromColumnIndex($nbColonnes);

        $feuille->setCellValue('A1', $export->titre.' · '.$tableau->titre);
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::BLEU);
        $feuille->setCellValue('A2', $entreprise.' · '.$export->periode);
        $feuille->setCellValue('A3', 'Édité le '.$editeLe->format('d/m/Y à H:i').' par '.$editePar);
        $feuille->getStyle('A2:A3')->getFont()->getColor()->setRGB('475569');

        $entete = 5;
        foreach ($tableau->colonnes as $i => $colonne) {
            $feuille->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$entete, $colonne['libelle']);
        }
        $feuille->getStyle('A'.$entete.':'.$derniere.$entete)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::BLEU]],
        ]);

        $ligne = $entete + 1;
        if ($tableau->estVide()) {
            $feuille->setCellValue('A'.$ligne, 'Aucune donnée sur la période.');
            $feuille->getStyle('A'.$ligne)->getFont()->setItalic(true);
            ++$ligne;
        }
        foreach ($tableau->lignes as $valeurs) {
            $this->ecrireLigne($feuille, $tableau, $valeurs, $ligne);
            ++$ligne;
        }
        if (null !== $tableau->total) {
            $this->ecrireLigne($feuille, $tableau, $tableau->total, $ligne);
            $feuille->getStyle('A'.$ligne.':'.$derniere.$ligne)->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::BLEU]]],
            ]);
            ++$ligne;
        }
        if (null !== $tableau->note || null !== $export->note) {
            $feuille->setCellValue('A'.($ligne + 1), trim(($tableau->note ?? '').' '.($export->note ?? '')));
            $feuille->getStyle('A'.($ligne + 1))->getFont()->setItalic(true)->getColor()->setRGB('64748B');
        }

        // Formats par colonne, sur les lignes de données et de total.
        foreach ($tableau->colonnes as $i => $colonne) {
            if (isset(self::FORMATS_EXCEL[$colonne['format']])) {
                $lettre = Coordinate::stringFromColumnIndex($i + 1);
                $feuille->getStyle($lettre.($entete + 1).':'.$lettre.max($entete + 1, $ligne))->getNumberFormat()->setFormatCode(self::FORMATS_EXCEL[$colonne['format']]);
            }
        }

        $feuille->freezePane('A'.($entete + 1));
        if (!$tableau->estVide()) {
            $feuille->setAutoFilter('A'.$entete.':'.$derniere.($entete + \count($tableau->lignes)));
        }
        for ($i = 1; $i <= $nbColonnes; ++$i) {
            $feuille->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $feuille->setSelectedCell('A'.($entete + 1));
    }

    /**
     * @param list<mixed> $valeurs
     */
    private function ecrireLigne(Worksheet $feuille, Tableau $tableau, array $valeurs, int $ligne): void
    {
        foreach (array_values($valeurs) as $i => $valeur) {
            $format = $tableau->colonnes[$i]['format'] ?? 'texte';
            $cellule = Coordinate::stringFromColumnIndex($i + 1).$ligne;
            $valeur = self::valeurCellule($valeur, $format);
            if ('texte' === $format && \is_string($valeur)) {
                // Un texte qui commence par « = » ne doit jamais devenir une formule.
                $feuille->setCellValueExplicit($cellule, $valeur, DataType::TYPE_STRING);
            } else {
                $feuille->setCellValue($cellule, $valeur);
            }
        }
    }
}
