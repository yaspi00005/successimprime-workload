<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Modèle Excel d'un type d'import : la feuille à remplir (en-tête et deux
 * lignes d'exemple, que l'import reconnaît et écarte si on les oublie) et
 * une feuille « Notes » qui explique chaque colonne.
 *
 * Les colonnes de coûts n'y figurent que pour qui a le droit de les voir.
 */
final class ModeleImport
{
    /**
     * Colonnes du modèle pour cette personne.
     *
     * @param callable(string): bool $droit
     *
     * @return list<array<string, mixed>>
     */
    public static function colonnes(TypeImport $type, callable $droit): array
    {
        return array_values(array_filter($type->colonnes(), static fn (array $c): bool => !isset($c['droit']) || $droit($c['droit'])));
    }

    /** En-tête d'une colonne : « Téléphone * » pour une colonne obligatoire. */
    public static function entete(array $colonne): string
    {
        return $colonne['libelle'].($colonne['obligatoire'] ? ' *' : '');
    }

    /**
     * Écrit le classeur dans un fichier temporaire et rend son chemin.
     *
     * @param callable(string): bool $droit
     */
    public function ecrire(TypeImport $type, callable $droit): string
    {
        $colonnes = self::colonnes($type, $droit);
        $classeur = new Spreadsheet();
        $classeur->getProperties()->setCreator('Succès Imprim')->setTitle('Modèle d\'import · '.$type->libelle());

        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(mb_substr($type->libelle(), 0, 31));
        foreach ($colonnes as $i => $c) {
            $lettre = Coordinate::stringFromColumnIndex($i + 1);
            $feuille->setCellValueExplicit($lettre.'1', self::entete($c), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            // Tout en texte : Excel ne transforme ni les numéros de téléphone ni les codes.
            $feuille->getStyle($lettre.'2:'.$lettre.'5001')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            foreach ([0, 1] as $n) {
                $feuille->setCellValueExplicit($lettre.($n + 2), $c['exemples'][$n], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $feuille->getColumnDimension($lettre)->setWidth(max(14, min(40, max(mb_strlen(self::entete($c)), mb_strlen($c['exemples'][0]), mb_strlen($c['exemples'][1])) + 4)));
            $feuille->getComment($lettre.'1')->getText()->createTextRun($c['aide']);
        }
        $derniere = Coordinate::stringFromColumnIndex(max(1, \count($colonnes)));
        $entete = $feuille->getStyle('A1:'.$derniere.'1');
        $entete->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $entete->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1D4ED8');
        $feuille->getStyle('A2:'.$derniere.'3')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
        $feuille->freezePane('A2');

        $notes = $classeur->createSheet();
        $notes->setTitle('Notes');
        $notes->setCellValue('A1', 'Import · '.$type->libelle());
        $notes->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ligne = 3;
        foreach ([
            'Remplissez la première feuille, une ligne par fiche, à partir de la ligne 2. Les deux lignes d\'exemple (en gris) sont reconnues et écartées : vous pouvez les laisser ou les effacer.',
            'Les colonnes marquées * sont obligatoires. Les autres peuvent rester vides ou être retirées ; leur ordre est libre.',
            'Montants en francs CFA, sans centimes : « 1 500 000 », « 1500000 » ou « 1500000,00 ». Dates en jj/mm/aaaa.',
            'Envoyez le fichier tel quel (.xlsx) ou enregistré en CSV (séparateur « ; » ou « , », UTF-8 ou Windows). 5 Mo et 5 000 lignes au plus.',
            ...$type->notes(),
        ] as $phrase) {
            $notes->setCellValue('A'.$ligne, '• '.$phrase);
            $notes->mergeCells('A'.$ligne.':D'.$ligne);
            $notes->getStyle('A'.$ligne)->getAlignment()->setWrapText(true);
            $notes->getRowDimension($ligne)->setRowHeight(max(15, 15 * (int) ceil(mb_strlen($phrase) / 110)));
            ++$ligne;
        }
        ++$ligne;
        foreach (['Colonne', 'Obligatoire', 'Exemple', 'À savoir'] as $i => $titre) {
            $notes->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$ligne, $titre);
        }
        $notes->getStyle('A'.$ligne.':D'.$ligne)->getFont()->setBold(true);
        foreach ($colonnes as $c) {
            ++$ligne;
            $notes->setCellValueExplicit('A'.$ligne, $c['libelle'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $notes->setCellValue('B'.$ligne, $c['obligatoire'] ? 'oui' : 'non');
            $notes->setCellValueExplicit('C'.$ligne, '' !== $c['exemples'][0] ? $c['exemples'][0] : $c['exemples'][1], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $notes->setCellValue('D'.$ligne, $c['aide'].([] !== $c['alias'] ? ' Aussi accepté en en-tête : '.implode(', ', \array_slice($c['alias'], 0, 4)).'.' : ''));
            $notes->getStyle('D'.$ligne)->getAlignment()->setWrapText(true);
        }
        $notes->getColumnDimension('A')->setWidth(26);
        $notes->getColumnDimension('B')->setWidth(12);
        $notes->getColumnDimension('C')->setWidth(28);
        $notes->getColumnDimension('D')->setWidth(80);
        $classeur->setActiveSheetIndex(0);

        $chemin = tempnam(sys_get_temp_dir(), 'modele-import-');
        if (false === $chemin) {
            throw new \RuntimeException('Fichier temporaire impossible.');
        }
        (new Xlsx($classeur))->save($chemin);
        $classeur->disconnectWorksheets();

        return $chemin;
    }

    /**
     * Une ligne d'exemple du modèle, laissée dans le fichier ? Toutes ses
     * cellules remplies dans l'exemple ont la même valeur.
     *
     * @param array<string, string> $valeurs valeurs de la ligne par clé de colonne
     */
    public static function estExemple(TypeImport $type, array $valeurs): bool
    {
        foreach ([0, 1] as $n) {
            $compare = 0;
            $pareil = true;
            foreach ($type->colonnes() as $c) {
                $exemple = $c['exemples'][$n];
                if ('' === $exemple || !\array_key_exists($c['cle'], $valeurs)) {
                    continue;
                }
                ++$compare;
                if (Valeurs::cle($exemple) !== Valeurs::cle($valeurs[$c['cle']])) {
                    $pareil = false;
                    break;
                }
            }
            if ($pareil && $compare >= 2) {
                return true;
            }
        }

        return false;
    }
}
