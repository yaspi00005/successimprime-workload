<?php

namespace App\Service\Prepresse;

/**
 * Le fichier de découpe (demande de Yaya du 20/10, docs/prepresse.md) :
 * un vinyle imprimé puis découpé au plotter a souvent deux fichiers, celui
 * de l'impression et celui de la découpe (le contour). Classe pure.
 *
 * - Une ligne demande un fichier de découpe quand une de ses finitions est
 *   une découpe (« Découpe à la forme », « contour », « plotter »), ou quand
 *   la prépresse l'a coché sur la ligne (« Fichier de découpe requis » ;
 *   décoché, il ne l'est plus, même avec une finition de découpe).
 * - Un fichier dont le nom dit « decoupe », « découpe », « cut » ou
 *   « contour » est rangé comme fichier de découpe au dépôt et à l'import
 *   du dossier partagé.
 */
final class RegleDecoupe
{
    /** La ligne n'en a pas besoin. */
    public const NON_REQUIS = 'non_requis';
    /** Le fichier de découpe est là. */
    public const PRESENT = 'present';
    /** Il en faut un et il n'est pas là. */
    public const MANQUANT = 'manquant';
    /** Il en faut un, mais la découpe se fait sans fichier séparé (contour dans le fichier d'impression). */
    public const SANS_FICHIER = 'sans_fichier';

    /** Mots d'une finition de découpe (minuscules, sans accents). */
    private const MOTS_FINITION = ['decoupe', 'contour', 'plotter'];

    /** Une finition de découpe ? « Découpe à la forme », « Contour », « Plotter »… */
    public static function estFinitionDecoupe(?string $nom): bool
    {
        $simple = self::simplifier((string) $nom);
        foreach (self::MOTS_FINITION as $mot) {
            if (str_contains($simple, $mot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un nom de fichier de découpe ? « decoupe_5x7.pdf », « Découpe.ai »,
     * « CutContour.eps », « logo-cut.pdf », « contour.svg ». « cut » doit
     * commencer un mot (« cutcontour », « kiss-cut ») : « biscuit » n'en est pas un.
     */
    public static function estNomFichierDecoupe(?string $nom): bool
    {
        $simple = self::simplifier(pathinfo((string) $nom, \PATHINFO_FILENAME));
        if (str_contains($simple, 'decoupe') || str_contains($simple, 'contour')) {
            return true;
        }

        return 1 === preg_match('/(^|[^a-z])cut/', $simple);
    }

    /**
     * La ligne demande-t-elle un fichier de découpe ?
     *
     * @param list<?string> $finitions noms des finitions de la ligne
     * @param bool|null     $force     case « Fichier de découpe requis » de la prépresse (null : d'après les finitions)
     */
    public static function requis(array $finitions, ?bool $force): bool
    {
        if (null !== $force) {
            return $force;
        }
        foreach ($finitions as $nom) {
            if (self::estFinitionDecoupe($nom)) {
                return true;
            }
        }

        return false;
    }

    /** NON_REQUIS, PRESENT, MANQUANT ou SANS_FICHIER. */
    public static function etat(bool $requis, bool $aFichier, bool $sansFichierSepare): string
    {
        return match (true) {
            $aFichier => self::PRESENT,
            !$requis => self::NON_REQUIS,
            $sansFichierSepare => self::SANS_FICHIER,
            default => self::MANQUANT,
        };
    }

    /** Minuscules, sans accents (« Découpe » → « decoupe »). */
    private static function simplifier(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', strtr($texte, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'î' => 'i', 'ï' => 'i', 'ç' => 'c']));

        return false !== $sans ? $sans : $texte;
    }
}
