<?php

namespace App\Service\Production;

use App\Service\Prepresse\NomFichierPartage;

/**
 * Règle de nommage des fichiers de production (maquette PD01, « Le nom du
 * fichier est fabriqué par l'ERP », docs/production-rouleau.md) :
 *
 *   CMD-000183_L1_BACHE440_300x100_x2_OURLET-OEIL50.pdf
 *   commande _ ligne _ support _ taille en cm _ quantité _ finitions
 *
 * Le numéro est la forme courte de la commande (CMD-000183, que le dossier
 * partagé sait déjà relire), la ligne est son rang dans la commande (triée
 * par id), les codes viennent des noms (« Bâche 440 g frontlit » →
 * BACHE440) sauf si un code de production est réglé sur le support ou la
 * finition. ASCII, majuscules, sans espace, 150 caractères au plus, sans
 * caractère interdit par Windows. Le fichier de découpe prend le même nom
 * suivi de « _decoupe ».
 *
 * Classe pure : aucun accès à la base ni au disque.
 */
final class NomFichierProduction
{
    public const LONGUEUR_MAX = 150;
    public const SUFFIXE_DECOUPE = '_decoupe';
    /** Longueur d'un code tiré d'un nom (mot principal + nombre). */
    public const CODE_MAX = 16;

    /** Mots longs raccourcis dans les codes (« Œillets tous les 50 cm » → OEIL50). */
    private const ABREGES = ['OEILLETS' => 'OEIL', 'OEILLET' => 'OEIL'];

    /** CMD-000183-10-2026 → CMD-000183 (même forme courte que DossierPartage::pourCommande). */
    public static function numeroCourt(string $numero): string
    {
        $court = (string) preg_replace('/-\d{2}-\d{4}$/', '', strtoupper(trim($numero)));

        return trim((string) preg_replace('/[^A-Z0-9-]+/', '', $court), '-');
    }

    /**
     * Code tiré d'un nom : le premier mot et le premier nombre, en ASCII
     * majuscules. « Bâche 440 g frontlit » → BACHE440, « Fourreau haut et
     * bas » → FOURREAU, « T-shirt coton » → TSHIRT. Ce qui suit « · » (la
     * laize d'un article) est ignoré.
     */
    public static function code(string $nom): string
    {
        $nom = trim(explode('·', $nom)[0]);
        // Les mots composés restent d'un seul tenant (T-shirt, Micro-perforé).
        $nom = (string) preg_replace('/(?<=\p{L})-(?=\p{L})/u', '', $nom);
        $simple = NomFichierPartage::simplifier($nom);
        $mots = '' === $simple ? [] : explode(' ', $simple);
        $mot = '';
        foreach ($mots as $m) {
            if (!ctype_digit($m)) {
                $mot = strtoupper($m);
                break;
            }
        }
        $mot = self::ABREGES[$mot] ?? $mot;
        $nombre = preg_match('/\d+/', $simple, $n) ? $n[0] : '';

        return substr(substr($mot, 0, self::CODE_MAX - \strlen($nombre)).$nombre, 0, self::CODE_MAX);
    }

    /** Code réglé à la main (support, finition) s'il y en a un, sinon celui tiré du nom. */
    public static function codeRegle(?string $regle, string $nom): string
    {
        $propre = self::nettoyerCode($regle);

        return '' !== $propre ? $propre : self::code($nom);
    }

    /** Un code saisi : majuscules, chiffres et tiret seulement. */
    public static function nettoyerCode(?string $code): string
    {
        $simple = NomFichierPartage::simplifier((string) $code);

        return substr(strtoupper(str_replace(' ', '', $simple)), 0, 30);
    }

    /** 300 → « 300 », 85,5 → « 85,5 ». */
    public static function cm(float $valeur): string
    {
        $arrondi = round($valeur, 1);

        return floor($arrondi) == $arrondi ? (string) (int) $arrondi : str_replace('.', ',', (string) $arrondi);
    }

    /**
     * Le nom complet. $extension : celle du fichier source (pdf par défaut).
     *
     * @param list<string> $codesFinitions
     */
    public static function nom(string $numeroCourt, int $rang, ?string $codeSupport, ?float $largeurCm, ?float $hauteurCm, int $quantite, array $codesFinitions = [], ?string $extension = 'pdf'): string
    {
        $parties = [self::numeroCourt($numeroCourt) ?: 'CMD', 'L'.max(1, $rang)];
        if (null !== $codeSupport && '' !== self::nettoyerCode($codeSupport)) {
            $parties[] = self::nettoyerCode($codeSupport);
        }
        if (null !== $largeurCm && null !== $hauteurCm && $largeurCm > 0 && $hauteurCm > 0) {
            $parties[] = self::cm($largeurCm).'x'.self::cm($hauteurCm);
        }
        $parties[] = 'x'.max(1, $quantite);
        $finitions = array_values(array_unique(array_filter(array_map(self::nettoyerCode(...), $codesFinitions))));
        if ([] !== $finitions) {
            $parties[] = implode('-', $finitions);
        }

        return self::assembler(implode('_', $parties), $extension);
    }

    /** CMD-…_x2_OEIL50.pdf + découpe en .svg → CMD-…_x2_OEIL50_decoupe.svg. */
    public static function nomDecoupe(string $nomProduction, ?string $extension): string
    {
        return self::assembler(pathinfo($nomProduction, \PATHINFO_FILENAME).self::SUFFIXE_DECOUPE, $extension);
    }

    /** Extension d'un nom de fichier, en minuscules, lettres et chiffres seulement. */
    public static function extension(?string $nom, string $defaut = 'pdf'): string
    {
        $ext = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo((string) $nom, \PATHINFO_EXTENSION)));

        return '' !== $ext && \strlen($ext) <= 5 ? $ext : $defaut;
    }

    /**
     * Rang d'une ligne dans sa commande (L1, L2…) : position parmi les ids
     * des lignes triés.
     *
     * @param list<int> $idsLignes
     */
    public static function rang(array $idsLignes, int $id): int
    {
        $ids = array_values(array_unique(array_map('intval', $idsLignes)));
        sort($ids);
        $position = array_search($id, $ids, true);

        return false === $position ? 1 : $position + 1;
    }

    /**
     * Les parties d'un nom, pour l'écran « Règles de nommage » :
     * [texte, rôle] dans l'ordre.
     *
     * @return list<array{texte: string, role: string}>
     */
    public static function parties(string $nom): array
    {
        $base = pathinfo($nom, \PATHINFO_FILENAME);
        $decoupe = str_ends_with($base, self::SUFFIXE_DECOUPE);
        if ($decoupe) {
            $base = substr($base, 0, -\strlen(self::SUFFIXE_DECOUPE));
        }
        $morceaux = explode('_', $base);
        $parties = [];
        foreach ($morceaux as $i => $m) {
            $role = match (true) {
                0 === $i => 'commande',
                1 === $i && preg_match('/^L\d+$/', $m) => 'ligne',
                (bool) preg_match('/^\d+(,\d)?x\d+(,\d)?$/', $m) => 'taille',
                (bool) preg_match('/^x\d+$/', $m) => 'quantite',
                2 === $i => 'support',
                default => 'finition',
            };
            $parties[] = ['texte' => $m, 'role' => $role];
        }
        if ($decoupe) {
            $parties[] = ['texte' => 'decoupe', 'role' => 'decoupe'];
        }

        return $parties;
    }

    private static function assembler(string $base, ?string $extension): string
    {
        $ext = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $extension)) ?: 'pdf';
        // Jamais de caractère interdit par Windows, ni de point ou d'espace en fin de nom.
        $base = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\s]+/', '-', $base), ' .-');

        return substr($base, 0, self::LONGUEUR_MAX - \strlen($ext) - 1).'.'.$ext;
    }
}
