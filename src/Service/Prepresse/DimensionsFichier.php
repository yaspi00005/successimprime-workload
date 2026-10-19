<?php

namespace App\Service\Prepresse;

/**
 * Dimensions d'un fichier déposé, pour l'associer à un format (PQ02) :
 * pixels et résolution d'une image (JFIF, PNG pHYs), page d'un PDF
 * (TrimBox, sinon MediaBox, en mm). Seul le début du fichier est lu : un
 * PDF de bâche de 500 Mo ne monte jamais en mémoire.
 *
 * Les lectures d'octets sont publiques et pures (testées sans fichier).
 */
final class DimensionsFichier
{
    /** Octets lus au début d'une image pour sa résolution. */
    private const LECTURE_IMAGE = 256 * 1024;

    /** Octets lus au début d'un PDF pour sa page (comme AnalyseFichierImpression). */
    private const LECTURE_PDF = 4 * 1024 * 1024;

    /** Noms d'appareil photo ou de messagerie : une photo, pas un fichier d'impression. */
    private const PHOTO = '/^(IMG|DSC|DSCN|PXL|P\d{3,}|WhatsApp Image|Screenshot|Capture)[ _-]?/i';

    /**
     * @return array{largeurPx: ?int, hauteurPx: ?int, dpi: ?float, largeurMm: ?float, hauteurMm: ?float, vectoriel: bool, photo: bool}
     */
    public function lire(string $chemin, string $nom): array
    {
        $vide = ['largeurPx' => null, 'hauteurPx' => null, 'dpi' => null, 'largeurMm' => null, 'hauteurMm' => null, 'vectoriel' => false, 'photo' => (bool) preg_match(self::PHOTO, $nom)];
        if (!is_file($chemin) || !is_readable($chemin)) {
            return $vide;
        }
        $image = @getimagesize($chemin);
        if (false !== $image && $image[0] > 0 && $image[1] > 0) {
            $debut = (string) @file_get_contents($chemin, false, null, 0, self::LECTURE_IMAGE);
            $dpi = match ($image[2] ?? null) {
                \IMAGETYPE_JPEG => self::dpiJpeg($debut),
                \IMAGETYPE_PNG => self::dpiPng($debut),
                default => null,
            };

            return ['largeurPx' => (int) $image[0], 'hauteurPx' => (int) $image[1], 'dpi' => $dpi] + $vide;
        }
        $debut = (string) @file_get_contents($chemin, false, null, 0, self::LECTURE_PDF);
        if (str_starts_with($debut, '%PDF')) {
            $page = self::pagePdf($debut);

            return ['largeurMm' => $page[0] ?? null, 'hauteurMm' => $page[1] ?? null, 'vectoriel' => true] + $vide;
        }
        // Illustrator récent : un PDF dans un .ai ; EPS : la BoundingBox.
        if (str_starts_with($debut, '%!PS') && preg_match('/%%BoundingBox:\s*(-?\d+)\s+(-?\d+)\s+(-?\d+)\s+(-?\d+)/', $debut, $m)) {
            return ['largeurMm' => abs((float) $m[3] - (float) $m[1]) * 25.4 / 72, 'hauteurMm' => abs((float) $m[4] - (float) $m[2]) * 25.4 / 72, 'vectoriel' => true] + $vide;
        }

        return $vide;
    }

    /**
     * Résolution d'un JPEG : en-tête JFIF (APP0), unités 1 = dpi, 2 = points
     * par cm. Null si absente ou sans unité.
     */
    public static function dpiJpeg(string $octets): ?float
    {
        $position = strpos($octets, "JFIF\0");
        if (false === $position || \strlen($octets) < $position + 12) {
            return null;
        }
        $unite = \ord($octets[$position + 7]);
        $x = unpack('n', substr($octets, $position + 8, 2))[1] ?? 0;
        if ($x <= 0) {
            return null;
        }

        return match ($unite) {
            1 => (float) $x,
            2 => round($x * 2.54, 1),
            default => null,
        };
    }

    /** Résolution d'un PNG : bloc pHYs, points par mètre (unité 1). */
    public static function dpiPng(string $octets): ?float
    {
        $position = strpos($octets, 'pHYs');
        if (false === $position || \strlen($octets) < $position + 13) {
            return null;
        }
        $x = unpack('N', substr($octets, $position + 4, 4))[1] ?? 0;
        $unite = \ord($octets[$position + 12]);

        return 1 === $unite && $x > 0 ? round($x * 0.0254, 1) : null;
    }

    /**
     * Page d'un PDF en mm (largeur, hauteur) : le format fini (TrimBox)
     * d'abord, la MediaBox comprend les fonds perdus.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function pagePdf(string $contenu): ?array
    {
        foreach (['TrimBox', 'MediaBox'] as $boite) {
            if (preg_match('#/'.$boite.'\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]#', $contenu, $m)) {
                $l = abs((float) $m[3] - (float) $m[1]) * 25.4 / 72;
                $h = abs((float) $m[4] - (float) $m[2]) * 25.4 / 72;
                if ($l > 0 && $h > 0) {
                    return [round($l, 1), round($h, 1)];
                }
            }
        }

        return null;
    }
}
