<?php

namespace App\Service\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Lit un fichier d'import : classeur Excel (.xlsx, première feuille) ou
 * CSV (UTF-8 ou Windows-1252, séparé par « ; », « , » ou tabulation).
 *
 * Rend l'en-tête et les lignes de données en texte brut, avec leur numéro
 * de ligne dans le fichier ; la lecture des valeurs (montants, numéros,
 * dates) se fait ensuite, colonne par colonne (Valeurs).
 */
final class LecteurTableur
{
    public const TAILLE_MAX = 5 * 1024 * 1024;
    public const LIGNES_MAX = 5000;
    public const COLONNES_MAX = 40;

    public const EXTENSIONS = ['xlsx', 'csv', 'txt'];

    /**
     * @return array{entetes: list<string>, lignes: list<array{numero: int, valeurs: list<string>}>, encodage: ?string, separateur: ?string}
     *
     * @throws \InvalidArgumentException fichier illisible, trop gros ou trop long
     */
    public function lire(string $chemin, string $extension): array
    {
        $taille = @filesize($chemin);
        if (false === $taille || $taille <= 0) {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        if ($taille > self::TAILLE_MAX) {
            throw new \InvalidArgumentException(sprintf('Le fichier dépasse %d Mo.', self::TAILLE_MAX / 1024 / 1024));
        }
        $extension = strtolower($extension);

        return match ($extension) {
            'xlsx' => $this->lireXlsx($chemin),
            'csv', 'txt' => self::lireCsv((string) file_get_contents($chemin)),
            default => throw new \InvalidArgumentException('Envoyez un fichier Excel (.xlsx) ou CSV.'),
        };
    }

    /**
     * @return array{entetes: list<string>, lignes: list<array{numero: int, valeurs: list<string>}>, encodage: string, separateur: string}
     */
    public static function lireCsv(string $contenu): array
    {
        [$texte, $encodage] = self::enUtf8($contenu);
        $separateur = self::separateur($texte);

        $flux = fopen('php://memory', 'w+');
        if (false === $flux) {
            throw new \RuntimeException('Lecture du fichier impossible.');
        }
        fwrite($flux, $texte);
        rewind($flux);
        $rangees = [];
        $numero = 0;
        while (false !== ($champs = fgetcsv($flux, null, $separateur, '"', ''))) {
            ++$numero;
            $valeurs = array_map(static fn ($v): string => trim((string) $v), $champs);
            $rangees[] = ['numero' => $numero, 'valeurs' => $valeurs];
            if (\count($rangees) > self::LIGNES_MAX + 50) {
                break;
            }
        }
        fclose($flux);

        return self::separerEntete($rangees) + ['encodage' => $encodage, 'separateur' => $separateur];
    }

    /**
     * Texte en UTF-8, sans marque d'ordre des octets. Un fichier qui n'est
     * pas de l'UTF-8 valide vient d'un Excel français : Windows-1252.
     *
     * @return array{0: string, 1: string} texte, encodage d'origine
     */
    public static function enUtf8(string $contenu): array
    {
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            return [substr($contenu, 3), 'UTF-8'];
        }
        if (str_starts_with($contenu, "\xFF\xFE")) {
            return [(string) mb_convert_encoding(substr($contenu, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16'];
        }
        if (str_starts_with($contenu, "\xFE\xFF")) {
            return [(string) mb_convert_encoding(substr($contenu, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16'];
        }
        if (mb_check_encoding($contenu, 'UTF-8')) {
            return [$contenu, 'UTF-8'];
        }

        return [(string) mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    /**
     * Séparateur le plus présent dans la première ligne non vide, hors
     * guillemets : « ; » (Excel français), « , » ou tabulation.
     */
    public static function separateur(string $texte): string
    {
        $premiere = '';
        foreach (preg_split('/\r\n|\r|\n/', $texte) ?: [] as $ligne) {
            if ('' !== trim($ligne)) {
                $premiere = $ligne;
                break;
            }
        }
        $horsGuillemets = preg_replace('/"[^"]*"/', '', $premiere) ?? $premiere;
        $comptes = [';' => substr_count($horsGuillemets, ';'), ',' => substr_count($horsGuillemets, ','), "\t" => substr_count($horsGuillemets, "\t")];
        arsort($comptes);
        $choisi = (string) array_key_first($comptes);

        return $comptes[$choisi] > 0 ? $choisi : ';';
    }

    /**
     * La première ligne non vide est l'en-tête ; les lignes vides qui
     * suivent sont sautées.
     *
     * @param list<array{numero: int, valeurs: list<string>}> $rangees
     *
     * @return array{entetes: list<string>, lignes: list<array{numero: int, valeurs: list<string>}>}
     */
    public static function separerEntete(array $rangees): array
    {
        $entetes = null;
        $lignes = [];
        foreach ($rangees as $r) {
            $valeurs = $r['valeurs'];
            while ([] !== $valeurs && '' === end($valeurs)) {
                array_pop($valeurs);
            }
            if ([] === $valeurs) {
                continue;
            }
            if (null === $entetes) {
                if (\count($valeurs) > self::COLONNES_MAX) {
                    throw new \InvalidArgumentException(sprintf('Le fichier a plus de %d colonnes : est-ce le bon fichier ?', self::COLONNES_MAX));
                }
                $entetes = $valeurs;
                continue;
            }
            $lignes[] = ['numero' => $r['numero'], 'valeurs' => $valeurs];
            if (\count($lignes) > self::LIGNES_MAX) {
                throw new \InvalidArgumentException(sprintf('Le fichier a plus de %s lignes : coupez-le en plusieurs fichiers.', number_format(self::LIGNES_MAX, 0, ',', ' ')));
            }
        }
        if (null === $entetes) {
            throw new \InvalidArgumentException('Le fichier ne contient aucune ligne.');
        }

        return ['entetes' => $entetes, 'lignes' => $lignes];
    }

    /** Lire un .xlsx demande l'extension PHP zip (ZipArchive). */
    public static function xlsxLisible(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * @return array{entetes: list<string>, lignes: list<array{numero: int, valeurs: list<string>}>, encodage: null, separateur: null}
     */
    private function lireXlsx(string $chemin): array
    {
        if (!self::xlsxLisible()) {
            throw new \InvalidArgumentException('Ce serveur ne lit pas les classeurs .xlsx (extension PHP zip absente, voir docs/deploiement.md). Enregistrez le fichier en CSV depuis Excel et envoyez ce CSV.');
        }
        $lecteur = new Xlsx();
        try {
            if (!$lecteur->canRead($chemin)) {
                throw new \InvalidArgumentException('Ce fichier n\'est pas un classeur Excel (.xlsx) lisible.');
            }
            // Taille annoncée de la première feuille, avant de tout charger.
            $infos = $lecteur->listWorksheetInfo($chemin);
            $premiere = $infos[0] ?? null;
            if (null === $premiere) {
                throw new \InvalidArgumentException('Le classeur ne contient aucune feuille.');
            }
            if ((int) $premiere['totalRows'] > self::LIGNES_MAX + 50) {
                throw new \InvalidArgumentException(sprintf('Le fichier a plus de %s lignes : coupez-le en plusieurs fichiers.', number_format(self::LIGNES_MAX, 0, ',', ' ')));
            }
            if ((int) $premiere['totalColumns'] > self::COLONNES_MAX + 20) {
                throw new \InvalidArgumentException(sprintf('Le fichier a plus de %d colonnes : est-ce le bon fichier ?', self::COLONNES_MAX));
            }
            $lecteur->setLoadSheetsOnly([(string) $premiere['worksheetName']]);
            $lecteur->setReadEmptyCells(false);
            $classeur = $lecteur->load($chemin);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Ce classeur Excel est illisible ou abîmé. Enregistrez-le de nouveau, ou en CSV.');
        }

        $feuille = $classeur->getSheet(0);
        $rangees = [];
        foreach ($feuille->getRowIterator() as $rangee) {
            $valeurs = [];
            $colonne = 0;
            foreach ($rangee->getCellIterator() as $cellule) {
                ++$colonne;
                if ($colonne > self::COLONNES_MAX + 20) {
                    break;
                }
                $index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cellule->getColumn());
                while (\count($valeurs) < $index - 1) {
                    $valeurs[] = '';
                }
                $valeurs[] = self::texteCellule($cellule);
            }
            $rangees[] = ['numero' => $rangee->getRowIndex(), 'valeurs' => $valeurs];
        }
        $classeur->disconnectWorksheets();

        return self::separerEntete($rangees) + ['encodage' => null, 'separateur' => null];
    }

    /**
     * Valeur d'une cellule en texte : une date devient AAAA-MM-JJ, un
     * nombre entier s'écrit sans « .0 » (numéros de téléphone saisis en
     * nombre), une formule donne son résultat.
     */
    private static function texteCellule(Cell $cellule): string
    {
        try {
            $valeur = $cellule->getCalculatedValue();
        } catch (\Throwable) {
            $valeur = $cellule->getValue();
        }
        if (null === $valeur) {
            return '';
        }
        if (\is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }
        if ((\is_int($valeur) || \is_float($valeur)) && Date::isDateTime($cellule)) {
            try {
                return Date::excelToDateTimeObject((float) $valeur)->format('Y-m-d');
            } catch (\Throwable) {
                return (string) $valeur;
            }
        }
        if (\is_float($valeur)) {
            return floor($valeur) === $valeur && abs($valeur) < 1e15 ? sprintf('%.0f', $valeur) : rtrim(rtrim(sprintf('%.6F', $valeur), '0'), '.');
        }

        return trim((string) $valeur);
    }
}
