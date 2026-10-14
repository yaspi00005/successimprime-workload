<?php

namespace App\Service\Import;

use App\Entity\ContactClient;

/**
 * Lecture des valeurs d'une cellule de fichier d'import, telles qu'on les
 * tape à Bamako dans Excel : « 1 500 000 », « 1500000,00 », « 1.500.000 »,
 * « +223 76 12 34 56 », « 15/11/2026 », « oui ».
 *
 * Tout est statique et pur, pour être testé sans base.
 */
final class Valeurs
{
    /** Espaces qu'Excel ou Word mettent dans les nombres (insécable, fine insécable). */
    private const ESPACES = [' ', "\u{00A0}", "\u{202F}", "\u{2009}", "\t"];

    /**
     * Montant en francs CFA : un entier. Les centimes nuls sont acceptés
     * (« 1500000,00 »), pas les autres : le franc CFA n'a pas de centimes.
     *
     * @throws \InvalidArgumentException si ce n'est pas un montant
     */
    public static function montant(string $brut, bool $negatifPermis = false): int
    {
        $nombre = self::nombre($brut);
        if (null === $nombre) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas un montant.', trim($brut)));
        }
        if (abs($nombre - round($nombre)) > 0.0001) {
            throw new \InvalidArgumentException(sprintf('« %s » : les montants sont en francs, sans centimes.', trim($brut)));
        }
        $entier = (int) round($nombre);
        if ($entier < 0 && !$negatifPermis) {
            throw new \InvalidArgumentException(sprintf('« %s » : le montant ne peut pas être négatif.', trim($brut)));
        }
        if (abs($entier) > 100_000_000_000) {
            throw new \InvalidArgumentException(sprintf('« %s » : montant trop grand.', trim($brut)));
        }

        return $entier;
    }

    /**
     * Nombre écrit à la française ou à l'anglaise, ou null.
     *
     * Les espaces séparent les milliers. Quand la virgule et le point sont
     * tous les deux là, le dernier des deux marque les décimales. Seul, un
     * séparateur suivi de groupes de 3 chiffres exactement marque les
     * milliers (« 1.500.000 », « 1,500 ») ; sinon il marque les décimales
     * (« 2,5 », « 1500000,00 »).
     */
    public static function nombre(string $brut): ?float
    {
        $s = str_replace(self::ESPACES, '', trim($brut));
        $s = preg_replace('/\s*(F|FCFA|F CFA|CFA|XOF)$/iu', '', $s) ?? $s;
        $s = str_replace(self::ESPACES, '', $s);
        if ('' === $s) {
            return null;
        }
        $negatif = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            // (1 500) : écriture comptable d'un nombre négatif.
            $s = $m[1];
            $negatif = true;
        }
        if (str_starts_with($s, '-') || str_starts_with($s, '−')) {
            $s = preg_replace('/^(-|−)/u', '', $s) ?? $s;
            $negatif = !$negatif;
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        if (!preg_match('/^[0-9.,]+$/', $s) || !preg_match('/[0-9]/', $s)) {
            return null;
        }
        $virgule = strrpos($s, ',');
        $point = strrpos($s, '.');
        if (false !== $virgule && false !== $point) {
            $decimal = $virgule > $point ? ',' : '.';
            $milliers = ',' === $decimal ? '.' : ',';
            if (substr_count($s, $decimal) > 1) {
                return null;
            }
            $s = str_replace($milliers, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif (false !== $virgule || false !== $point) {
            $sep = false !== $virgule ? ',' : '.';
            if (preg_match('/^\d{1,3}(\\'.$sep.'\d{3})+$/', $s) && !self::ressembleDecimal($s, $sep)) {
                $s = str_replace($sep, '', $s);
            } elseif (1 === substr_count($s, $sep)) {
                $s = str_replace($sep, '.', $s);
            } else {
                return null;
            }
        }
        if (!is_numeric($s)) {
            return null;
        }

        return $negatif ? -(float) $s : (float) $s;
    }

    /**
     * « 1,500 » est ambigu : on le lit comme 1 500 (milliers), sauf « 0,500 »
     * qui ne peut être qu'un décimal.
     */
    private static function ressembleDecimal(string $s, string $sep): bool
    {
        return str_starts_with($s, '0'.$sep);
    }

    /**
     * Numéro malien sur 8 chiffres, sans indicatif (+223, 00223 et 223
     * sont retirés), ou null s'il n'a pas 8 chiffres. Même règle que la
     * saisie d'un client (ContactClient::chiffres).
     */
    public static function telephone(string $brut): ?string
    {
        $brut = trim($brut);
        if ('' === $brut) {
            return null;
        }
        // Excel a pu lire le numéro comme un nombre : 76123456.0 ou 7.6123456E7.
        if (preg_match('/^\d+(\.0+)?$/', $brut) || preg_match('/^\d(\.\d+)?E\+?\d+$/i', $brut)) {
            $brut = sprintf('%.0f', (float) $brut);
        }
        $chiffres = ContactClient::chiffres($brut);

        return 8 === \strlen($chiffres) ? $chiffres : null;
    }

    /** « oui », « x », « 1 », « vrai » : vrai ; « non », « 0 », vide : faux ; sinon null. */
    public static function booleen(string $brut): ?bool
    {
        $c = self::cle($brut);

        return match (true) {
            '' === $c, \in_array($c, ['non', 'n', '0', 'faux', 'false', 'no'], true) => false,
            \in_array($c, ['oui', 'o', 'x', '1', 'vrai', 'true', 'yes', 'y'], true) => true,
            default => null,
        };
    }

    /**
     * Date « 15/11/2026 », « 15-11-26 », « 2026-11-15 », ou numéro de série
     * d'Excel (46341). Null si vide.
     *
     * @throws \InvalidArgumentException si ce n'est pas une date
     */
    public static function date(string $brut): ?\DateTimeImmutable
    {
        $brut = trim($brut);
        if ('' === $brut) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $brut, $m)) {
            [$a, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $brut, $m)) {
            [$j, $mo, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $a = $a < 100 ? 2000 + $a : $a;
        } elseif (preg_match('/^\d{5}(\.0+)?$/', $brut)) {
            // Numéro de série Excel : jours depuis le 30/12/1899.
            return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) $brut.' days');
        } else {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas une date (jj/mm/aaaa).', $brut));
        }
        if (!checkdate($mo, $j, $a)) {
            throw new \InvalidArgumentException(sprintf('« %s » n\'est pas une date qui existe.', $brut));
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $a, $mo, $j));
    }

    /**
     * Clé de comparaison d'un nom : minuscules, sans accents, sans
     * ponctuation, espaces réduits. « Hôtel  du Niger » = « hotel du niger ».
     */
    public static function cle(string $texte): string
    {
        $t = mb_strtolower(trim($texte));
        $t = strtr($t, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '²' => '2', '’' => '\'',
        ]);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    /** Texte d'une cellule : espaces en trop retirés, vide = null. */
    public static function texte(?string $brut, int $max = 255): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $brut) ?? '');

        return '' === $t ? null : mb_substr($t, 0, $max);
    }

    /** « 1 500 000 F » pour l'aperçu et les messages. */
    public static function francs(int $montant): string
    {
        return number_format($montant, 0, ',', ' ').' F';
    }
}
