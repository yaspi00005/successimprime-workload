<?php

namespace App\Service\Prepresse;

/**
 * Lecture du nom d'un fichier posé dans le dossier partagé
 * (var/uploads/dossier_partage, docs/fichiers-lourds.md) :
 *
 * - « 123_nom.pdf » ou « 123-nom.pdf » : 123 est la référence du travail
 *   (la ligne de commande, « Réf : #123 » sur la fiche) ;
 * - « CMD-000378_5,5x7.pdf », « CMD-000378-10-2026 5,5x7.pdf » : le
 *   numéro de la commande, puis le format qui choisit la ligne.
 *
 * Et les fichiers à laisser tranquilles : cachés, temporaires, en cours
 * de copie (taille qui change, modifiés depuis moins de 30 s). Classe pure.
 */
final class NomFichierPartage
{
    /** Un fichier modifié depuis moins longtemps est peut-être encore en cours de copie. */
    public const REPOS_SECONDES = 30;

    /** Extensions des copies en cours (navigateur, Windows, Syncthing, rsync). */
    private const PARTIELS = ['part', 'partial', 'crdownload', 'download', 'tmp', 'temp', 'filepart', 'opdownload', '!ut', 'syncthing'];

    /**
     * @return array{type: ?string, ligne: ?int, numero: ?string, reste: string}
     *                                                                          type : « ligne », « commande » ou null (pas de référence)
     */
    public static function lire(string $nom): array
    {
        $nom = trim($nom);
        if (preg_match('/^(\d{1,9})[-_](.+)$/', $nom, $m)) {
            return ['type' => 'ligne', 'ligne' => (int) $m[1], 'numero' => null, 'reste' => $m[2]];
        }
        // CMD-000378, CMD-000378-10-2026, CMD-PQ0378 : jusqu'au premier séparateur qui n'est pas « -MM-AAAA ».
        if (preg_match('/^(CMD-[A-Z0-9]{2,12}(?:-\d{2}-\d{4})?)(?=$|[\s_.(\[]|-(?!\d{2}-\d{4}))[\s_-]*(.*)$/i', $nom, $m)) {
            return ['type' => 'commande', 'ligne' => null, 'numero' => strtoupper($m[1]), 'reste' => $m[2]];
        }

        return ['type' => null, 'ligne' => null, 'numero' => null, 'reste' => $nom];
    }

    /**
     * À ignorer pour de bon : fichier caché, verrou d'Office, copie en
     * cours d'après son extension.
     */
    public static function estIgnore(string $nom): bool
    {
        if ('' === $nom || '.' === $nom[0] || str_starts_with($nom, '~$') || 'Thumbs.db' === $nom || 'desktop.ini' === strtolower($nom)) {
            return true;
        }
        $extension = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));

        return \in_array($extension, self::PARTIELS, true);
    }

    /**
     * Encore en cours d'écriture ? Taille différente entre deux lectures,
     * ou modifié depuis moins de 30 s.
     */
    public static function enCoursDeCopie(int $taille, int $tailleRelue, int $modifieLe, int $maintenant): bool
    {
        return $taille !== $tailleRelue || $maintenant - $modifieLe < self::REPOS_SECONDES;
    }

    /**
     * Nom sûr : un seul niveau, sans remontée ni caractère de contrôle. Le
     * dossier partagé ne se lit qu'à plat.
     */
    public static function nomSur(string $nom): bool
    {
        return '' !== $nom && basename($nom) === $nom && !str_contains($nom, '..') && !preg_match('/[\x00-\x1F\/\\\\]/', $nom);
    }

    /**
     * Nom rangé : « CMD-000378-10-2026_5,5x7cm.pdf » (commande et format),
     * sans caractère interdit par Windows.
     */
    public static function nomRange(string $numero, ?string $format, string $nomOrigine): string
    {
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($nomOrigine, \PATHINFO_EXTENSION)));
        $format = null !== $format ? str_replace([' × ', ' ', '×'], ['x', '', 'x'], $format) : (string) pathinfo(self::lire($nomOrigine)['reste'], \PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/', '-', $numero.('' !== $format ? '_'.$format : '')), ' .-');

        return mb_substr($base, 0, 150).('' !== $extension ? '.'.$extension : '');
    }

    /** Pour comparer un nom de client au nom du fichier : minuscules, sans accents ni ponctuation. */
    public static function simplifier(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', false !== $sans ? $sans : $texte));
    }
}
