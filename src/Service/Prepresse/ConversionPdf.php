<?php

namespace App\Service\Prepresse;

/**
 * Conversion d'un PDF en image (docs/prepresse-retouche.md, « Convertir
 * un PDF en image ») : la page est rendue dans le navigateur avec pdf.js
 * (public/js/pdf-en-image.js), car GD ne lit pas les PDF et Imagick n'est
 * pas garanti sur les serveurs. Ici, seulement ce que le serveur vérifie
 * avant de ranger l'image reçue : un vrai PNG ou JPEG, de taille
 * raisonnable, et les textes gardés avec le fichier.
 */
final class ConversionPdf
{
    public const DESIGNATION = 'Conversion PDF';

    /** Types acceptés, avec leur extension. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    /** Limites du navigateur (canvas) : au-delà, la résolution baisse à l'écran. */
    public const COTE_MAX_PX = 16_000;
    public const PIXELS_MAX = 120_000_000;

    /** Fichier reçu au plus (un PNG de 120 millions de pixels peu compressible). */
    public const TAILLE_MAX = 300 * 1024 * 1024;

    public const DPI_MIN = 30;
    public const DPI_MAX = 2400;

    /**
     * Vérifie que le fichier reçu est bien une image PNG ou JPEG lisible,
     * dans les limites. Le type est lu dans le contenu, jamais celui
     * annoncé par le navigateur.
     *
     * @return array{type: string, extension: string, largeur: int, hauteur: int}
     *
     * @throws \DomainException avec le message à montrer
     */
    public static function verifierImage(string $chemin, ?int $taille = null): array
    {
        $taille ??= is_file($chemin) ? (int) filesize($chemin) : 0;
        if ($taille <= 0 || !is_file($chemin)) {
            throw new \DomainException('Aucune image reçue.');
        }
        if ($taille > self::TAILLE_MAX) {
            throw new \DomainException(\sprintf('Image trop lourde (%s Mo, %d Mo au plus) : baissez la résolution ou choisissez JPEG.', number_format($taille / 1048576, 1, ',', ' '), intdiv(self::TAILLE_MAX, 1048576)));
        }
        $info = @getimagesize($chemin);
        if (false === $info || !isset(self::TYPES[$info['mime'] ?? ''])) {
            throw new \DomainException('Le fichier reçu n\'est pas une image PNG ou JPEG lisible : rien n\'a été enregistré.');
        }
        [$largeur, $hauteur] = [(int) $info[0], (int) $info[1]];
        if ($largeur < 1 || $hauteur < 1) {
            throw new \DomainException('Image vide : rien n\'a été enregistré.');
        }
        if (max($largeur, $hauteur) > self::COTE_MAX_PX || $largeur * $hauteur > self::PIXELS_MAX) {
            throw new \DomainException(\sprintf('Image trop grande (%s × %s px) : %s px de côté et %s millions de pixels au plus.', self::nombre($largeur), self::nombre($hauteur), self::nombre(self::COTE_MAX_PX), self::nombre(intdiv(self::PIXELS_MAX, 1_000_000))));
        }

        return ['type' => $info['mime'], 'extension' => self::TYPES[$info['mime']], 'largeur' => $largeur, 'hauteur' => $hauteur];
    }

    /**
     * Nom par défaut : celui du PDF, la page s'il y en a plusieurs, la
     * résolution. « affiche_p2_150dpi.png ».
     */
    public static function nomParDefaut(string $nomPdf, int $page, int $pages, int $dpi, string $extension): string
    {
        $base = (string) preg_replace('/\.pdf$/i', '', trim($nomPdf));
        $base = trim((string) preg_replace('/[^\w\-]+/u', '-', $base), '-_');
        $base = mb_substr('' === $base ? 'conversion' : $base, 0, 150);

        return $base.($pages > 1 ? '_p'.$page : '').'_'.$dpi.'dpi.'.$extension;
    }

    /**
     * Réglages envoyés par le navigateur, ramenés dans des bornes sûres.
     *
     * @param array<string, mixed> $d
     *
     * @return array{page: int, pages: int, dpi: int, echelle: int, fondPerdu: bool, largeurMm: ?float, hauteurMm: ?float}
     */
    public static function reglages(array $d): array
    {
        $pages = max(1, min(9999, (int) ($d['pages'] ?? 1)));
        $echelle = (int) ($d['echelle'] ?? 1);
        $mm = static fn (mixed $v): ?float => is_numeric($v) && (float) $v > 0 && (float) $v < 100_000 ? round((float) $v, 1) : null;

        return [
            'page' => max(1, min($pages, (int) ($d['page'] ?? 1))),
            'pages' => $pages,
            'dpi' => max(self::DPI_MIN, min(self::DPI_MAX, (int) ($d['dpi'] ?? 0))),
            'echelle' => \in_array($echelle, [1, ...GeometrieRetouche::ECHELLES], true) ? $echelle : 1,
            'fondPerdu' => filter_var($d['fondPerdu'] ?? false, \FILTER_VALIDATE_BOOL),
            'largeurMm' => $mm($d['largeurMm'] ?? null),
            'hauteurMm' => $mm($d['hauteurMm'] ?? null),
        ];
    }

    /**
     * Observation gardée avec l'image : page, résolution, taille.
     *
     * @param array{page: int, pages: int, dpi: int, echelle: int, fondPerdu: bool, largeurMm: ?float, hauteurMm: ?float} $r
     */
    public static function observation(string $nomPdf, array $r, int $largeurPx, int $hauteurPx, string $extension): string
    {
        $parties = ['page '.$r['page'].' sur '.$r['pages']];
        $dpi = $r['dpi'].' dpi';
        if ($r['echelle'] > 1) {
            $dpi .= \sprintf(' à la taille d\'impression (PDF au 1/%d, agrandi ×%d)', $r['echelle'], $r['echelle']);
        }
        $parties[] = $dpi;
        if (null !== $r['largeurMm'] && null !== $r['hauteurMm']) {
            $parties[] = GeometrieRetouche::mm($r['largeurMm'] * $r['echelle'] / 10).' × '.GeometrieRetouche::mm($r['hauteurMm'] * $r['echelle'] / 10).' cm';
        }
        $parties[] = self::nombre($largeurPx).' × '.self::nombre($hauteurPx).' px';
        $parties[] = ('png' === $extension ? 'PNG' : 'JPEG').', RVB';
        if ($r['fondPerdu']) {
            $parties[] = 'fond perdu gardé';
        }

        return 'Conversion PDF : '.implode(' · ', $parties).'. PDF d\'origine conservé : '.$nomPdf.'.';
    }

    private static function nombre(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
