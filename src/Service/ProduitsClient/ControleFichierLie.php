<?php

namespace App\Service\ProduitsClient;

use App\Service\AnalyseFichierImpression;

/**
 * Contrôle d'un fichier traité avant de le lier à un produit du client
 * (maquette MP02, « Contrôle du lien ») : dimensions, couleurs et calque de
 * découpe ou de perforation. Seul ce qui se lit honnêtement dans le
 * fichier est affirmé ; le reste est « à vérifier » par le graphiste, qui
 * garde la décision (il valide le lien lui-même).
 *
 * - PDF : format fini (TrimBox, sinon MediaBox), fonds perdus (BleedBox ou
 *   MediaBox autour de la TrimBox), couleurs déclarées (DeviceCMYK,
 *   DeviceRGB), couleur d'accompagnement ou calque nommé comme une découpe
 *   (CutContour, Thru-cut, Kiss-cut, Découpe, Perforation…).
 * - Image : pixels et proportions, CMJN si l'image a 4 canaux ; ni la
 *   taille réelle ni la découpe ne s'y lisent : « à vérifier ».
 * - Autres (AI non PDF, PSD, EPS…) : tout « à vérifier ».
 */
final class ControleFichierLie
{
    public const OK = AnalyseFichierImpression::OK;
    public const ATTENTION = AnalyseFichierImpression::ATTENTION;
    public const ERREUR = AnalyseFichierImpression::ERREUR;
    public const A_VERIFIER = AnalyseFichierImpression::A_VERIFIER;

    /** Octets lus au début et à la fin d'un PDF (les calques sont souvent décrits à la fin). */
    private const LECTURE = 4 * 1024 * 1024;

    /** Écart toléré sur les dimensions, en mm. */
    private const TOLERANCE_MM = 0.5;

    /** Noms de découpe ou de perforation reconnus (minuscules, sans espace ni tiret). */
    private const NOMS_DECOUPE = ['cutcontour', 'thrucut', 'kisscut', 'contourcut', 'diecut', 'decoupe', 'd#c3#a9coupe', 'découpe', 'cut', 'perforation', 'perfo', 'rainage', 'crease', 'stanze'];

    /**
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public function controler(string $chemin, ?float $largeurMm, ?float $hauteurMm): array
    {
        $image = @getimagesize($chemin);
        if (false !== $image && $image[0] > 0 && $image[1] > 0) {
            return self::controlerImage((int) $image[0], (int) $image[1], 4 === ($image['channels'] ?? null), $largeurMm, $hauteurMm);
        }
        $contenu = self::lire($chemin);
        if (str_starts_with($contenu, '%PDF')) {
            return self::controlerPdf($contenu, $largeurMm, $hauteurMm);
        }

        return [
            ['libelle' => 'Dimensions du fichier', 'valeur' => 'non lisibles dans ce format : à vérifier dans le logiciel', 'etat' => self::A_VERIFIER],
            ['libelle' => 'Couleurs', 'valeur' => 'à vérifier dans le logiciel', 'etat' => self::A_VERIFIER],
            ['libelle' => 'Découpe / perforation', 'valeur' => 'à vérifier dans le logiciel', 'etat' => self::A_VERIFIER],
        ];
    }

    /**
     * Résumé court pour la liste du dossier (« vectoriel · CMJN · calque
     * découpe »), sans taille cible.
     */
    public function resume(string $chemin): string
    {
        $image = @getimagesize($chemin);
        if (false !== $image && $image[0] > 0) {
            return \sprintf('image %d × %d px · %s', $image[0], $image[1], 4 === ($image['channels'] ?? null) ? 'CMJN' : 'RVB');
        }
        $contenu = self::lire($chemin);
        if (!str_starts_with($contenu, '%PDF')) {
            return 'fichier de travail';
        }
        $morceaux = [];
        $morceaux[] = str_contains($contenu, '/Subtype/Image') || str_contains($contenu, '/Subtype /Image') ? 'avec images' : 'vectoriel';
        $couleurs = self::couleurs($contenu);
        if (null !== $couleurs) {
            $morceaux[] = $couleurs;
        }
        $decoupe = self::decoupe($contenu);
        if (null !== $decoupe) {
            $morceaux[] = str_contains(mb_strtolower($decoupe), 'perf') ? 'perforation' : 'calque découpe';
        }

        return implode(' · ', $morceaux);
    }

    /**
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public static function controlerPdf(string $contenu, ?float $largeurMm, ?float $hauteurMm): array
    {
        $controles = [];
        $fini = self::boite($contenu, 'TrimBox') ?? self::boite($contenu, 'MediaBox');
        $imprime = self::boite($contenu, 'BleedBox') ?? self::boite($contenu, 'MediaBox');
        if (null === $fini) {
            $controles[] = ['libelle' => 'Dimensions du fichier', 'valeur' => 'format de page introuvable : à vérifier', 'etat' => self::A_VERIFIER];
        } else {
            $l = ($fini[2] - $fini[0]) * 25.4 / 72;
            $h = ($fini[3] - $fini[1]) * 25.4 / 72;
            $fond = null !== $imprime && null !== self::boite($contenu, 'TrimBox')
                ? max(0.0, min($fini[0] - $imprime[0], $fini[1] - $imprime[1], $imprime[2] - $fini[2], $imprime[3] - $fini[3]) * 25.4 / 72)
                : 0.0;
            $texte = self::mm($l).' × '.self::mm($h).' mm'.($fond >= 0.5 ? ' + '.self::mm($fond).' mm fond perdu' : '');
            $controles[] = self::comparer($texte, $l, $h, $largeurMm, $hauteurMm);
        }

        $couleurs = self::couleurs($contenu);
        $controles[] = [
            'libelle' => 'Couleurs',
            'valeur' => $couleurs ?? 'non déclarées dans le fichier : à vérifier',
            'etat' => match ($couleurs) {
                'CMJN' => self::OK,
                null => self::A_VERIFIER,
                default => self::ATTENTION,
            },
        ];

        $decoupe = self::decoupe($contenu);
        $controles[] = null !== $decoupe
            ? ['libelle' => 'Découpe / perforation', 'valeur' => 'calque « '.$decoupe.' » trouvé', 'etat' => self::OK]
            : ['libelle' => 'Découpe / perforation', 'valeur' => 'aucun calque de découpe détecté : à vérifier (sans objet si le produit n\'est pas découpé)', 'etat' => self::A_VERIFIER];

        return $controles;
    }

    /**
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public static function controlerImage(int $largeurPx, int $hauteurPx, bool $cmjn, ?float $largeurMm, ?float $hauteurMm): array
    {
        $controles = [];
        if ($largeurMm > 0 && $hauteurMm > 0) {
            $proportions = AnalyseFichierImpression::controleProportions($largeurPx, $hauteurPx, $largeurMm, $hauteurMm);
            $dpi = (int) floor(min($largeurPx / ($largeurMm / 25.4), $hauteurPx / ($hauteurMm / 25.4)));
            $controles[] = [
                'libelle' => 'Dimensions du fichier',
                'valeur' => \sprintf('%d × %d px, soit %d dpi à %s × %s mm · proportions %s', $largeurPx, $hauteurPx, $dpi, self::mm($largeurMm), self::mm($hauteurMm), AnalyseFichierImpression::OK === $proportions['etat'] ? 'conformes' : 'à vérifier'),
                'etat' => AnalyseFichierImpression::ERREUR === $proportions['etat'] ? self::ERREUR : ($dpi >= 300 && AnalyseFichierImpression::OK === $proportions['etat'] ? self::OK : self::ATTENTION),
            ];
        } else {
            $controles[] = ['libelle' => 'Dimensions du fichier', 'valeur' => \sprintf('%d × %d px : taille du produit inconnue', $largeurPx, $hauteurPx), 'etat' => self::A_VERIFIER];
        }
        $controles[] = ['libelle' => 'Couleurs', 'valeur' => $cmjn ? 'CMJN' : 'RVB : conversion en CMJN à faire', 'etat' => $cmjn ? self::OK : self::ATTENTION];
        $controles[] = ['libelle' => 'Découpe / perforation', 'valeur' => 'une image n\'a pas de calque de découpe : à vérifier', 'etat' => self::A_VERIFIER];

        return $controles;
    }

    /** Le pire état d'une liste de contrôles (erreur > attention > à vérifier > ok). */
    public static function etatGlobal(array $controles): string
    {
        $etats = array_column($controles, 'etat');

        return match (true) {
            \in_array(self::ERREUR, $etats, true) => self::ERREUR,
            \in_array(self::ATTENTION, $etats, true) => self::ATTENTION,
            \in_array(self::A_VERIFIER, $etats, true) => self::A_VERIFIER,
            default => self::OK,
        };
    }

    /** Nom de la couleur ou du calque de découpe trouvé dans le PDF, sinon null. */
    public static function decoupe(string $contenu): ?string
    {
        // Couleur d'accompagnement : /Separation /CutContour ; calque : /OCG … /Name (CutContour).
        $noms = [];
        if (preg_match_all('#/Separation\s*/([^\s/\[\]<>()]+)#', $contenu, $m)) {
            $noms = array_merge($noms, $m[1]);
        }
        if (preg_match_all('#/Name\s*\(([^)]{1,60})\)#', $contenu, $m)) {
            $noms = array_merge($noms, $m[1]);
        }
        foreach ($noms as $nom) {
            $lisible = rawurldecode(str_replace('#', '%', $nom));
            $cle = preg_replace('/[\s_\-]+/', '', mb_strtolower($lisible));
            foreach (self::NOMS_DECOUPE as $mot) {
                if ('cut' === $mot ? 'cut' === $cle : str_contains((string) $cle, $mot)) {
                    return $lisible;
                }
            }
        }

        return null;
    }

    /** « CMJN », « CMJN avec des éléments RVB », « RVB », ou null si rien n'est déclaré. */
    public static function couleurs(string $contenu): ?string
    {
        $cmjn = str_contains($contenu, '/DeviceCMYK');
        $rvb = str_contains($contenu, '/DeviceRGB') || str_contains($contenu, '/sRGB');

        return match (true) {
            $cmjn && !$rvb => 'CMJN',
            $cmjn => 'CMJN avec des éléments RVB',
            $rvb => 'RVB : conversion en CMJN à faire',
            default => null,
        };
    }

    /**
     * @return array{libelle: string, valeur: string, etat: string}
     */
    private static function comparer(string $texte, float $l, float $h, ?float $largeurMm, ?float $hauteurMm): array
    {
        if (!($largeurMm > 0) || !($hauteurMm > 0)) {
            return ['libelle' => 'Dimensions du fichier', 'valeur' => $texte.' · taille du produit inconnue', 'etat' => self::A_VERIFIER];
        }
        if (abs($l - $largeurMm) <= self::TOLERANCE_MM && abs($h - $hauteurMm) <= self::TOLERANCE_MM) {
            return ['libelle' => 'Dimensions du fichier', 'valeur' => $texte, 'etat' => self::OK];
        }
        if (abs($l - $hauteurMm) <= self::TOLERANCE_MM && abs($h - $largeurMm) <= self::TOLERANCE_MM) {
            return ['libelle' => 'Dimensions du fichier', 'valeur' => $texte.' · tourné d\'un quart de tour', 'etat' => self::ATTENTION];
        }

        return ['libelle' => 'Dimensions du fichier', 'valeur' => $texte.' · attendu '.self::mm($largeurMm).' × '.self::mm($hauteurMm).' mm', 'etat' => self::ERREUR];
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null x1, y1, x2, y2 rangés */
    private static function boite(string $contenu, string $nom): ?array
    {
        if (!preg_match('#/'.$nom.'\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]#', $contenu, $m)) {
            return null;
        }
        [$x1, $y1, $x2, $y2] = array_map('floatval', \array_slice($m, 1, 4));

        return [min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2)];
    }

    private static function lire(string $chemin): string
    {
        $taille = (int) @filesize($chemin);
        $debut = (string) @file_get_contents($chemin, false, null, 0, self::LECTURE);
        if ($taille > 2 * self::LECTURE) {
            $debut .= "\n".(string) @file_get_contents($chemin, false, null, $taille - self::LECTURE, self::LECTURE);
        } elseif ($taille > self::LECTURE) {
            $debut = (string) @file_get_contents($chemin);
        }

        return $debut;
    }

    private static function mm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',');
    }
}
