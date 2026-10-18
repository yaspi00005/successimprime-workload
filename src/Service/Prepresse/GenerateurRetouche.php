<?php

namespace App\Service\Prepresse;

/**
 * Génère le fichier final d'une retouche prépresse avec GD
 * (docs/prepresse-retouche.md) : redimensionnement selon le mode, fond
 * perdu, contour intérieur (rectangle ou forme), puis PDF, PNG ou JPEG à la
 * résolution de sortie. L'original n'est jamais modifié : il est seulement lu.
 *
 * Imagick n'est pas installé partout : tout passe par GD. Les couleurs
 * restent en RVB (GD ne sait pas faire de CMJN) : la conversion se fait au
 * RIP, et l'écran le dit.
 */
final class GenerateurRetouche
{
    public const FORMATS = ['pdf', 'png', 'jpeg'];
    public const TYPES_SOURCE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Le contour de forme se calcule sur une image d'au plus tant de pixels, puis est agrandi. */
    private const TRAVAIL_MAX_PX = 4_000_000;
    /** Écart de couleur toléré avec le fond, pour le détourage automatique (0 à 441). */
    public const TOLERANCE_FOND = 48;

    /**
     * Ce que l'outil doit savoir du fichier client avant de commencer.
     *
     * @return array{largeur: int, hauteur: int, type: string, cmjn: bool, transparence: bool, dpi: ?int, rotation: int}|null
     */
    public static function lireSource(string $chemin): ?array
    {
        $info = @getimagesize($chemin);
        if (false === $info || !\in_array($info['mime'] ?? '', self::TYPES_SOURCE, true)) {
            return null;
        }
        [$l, $h] = [(int) $info[0], (int) $info[1]];
        $rotation = 'image/jpeg' === $info['mime'] ? self::rotationExif($chemin) : 0;
        if (90 === abs($rotation)) {
            [$l, $h] = [$h, $l];
        }

        return [
            'largeur' => $l,
            'hauteur' => $h,
            'type' => $info['mime'],
            'cmjn' => 4 === ($info['channels'] ?? null),
            'transparence' => self::aUnCanalAlpha($chemin, $info['mime']),
            'dpi' => self::dpiDeclare($chemin, $info['mime']),
            'rotation' => $rotation,
        ];
    }

    /**
     * @param array<string, mixed> $reglages GeometrieRetouche::reglages()
     *
     * @return array{largeurPx: int, hauteurPx: int, transparence: bool}
     *
     * @throws \DomainException contour de forme impossible, fichier illisible
     */
    public function generer(string $source, array $reglages, string $format, string $sortie, string $titre): array
    {
        $info = self::lireSource($source) ?? throw new \DomainException('Le fichier n\'est pas une image JPG, PNG, WebP ou GIF lisible.');
        $p = GeometrieRetouche::placement($info['largeur'], $info['hauteur'], $reglages['largeurMm'], $reglages['hauteurMm'], $reglages['mode'], $reglages['fondPerdu'], $reglages['dpiSortie']);
        [$L, $H] = [$p['sortiePx']['l'], $p['sortiePx']['h']];
        self::assezDeMemoire($info['largeur'] * $info['hauteur'] + 2 * $L * $H);

        $src = self::charger($source, $info);
        $k = $reglages['dpiSortie'] / 25.4;

        $out = imagecreatetruecolor($L, $H);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagesavealpha($out, true);
        $d = $p['dest'];
        $s = $p['source'];
        $dx = (int) round($d['x'] * $k);
        $dy = (int) round($d['y'] * $k);
        $dl = max(1, (int) round(($d['x'] + $d['l']) * $k) - $dx);
        $dh = max(1, (int) round(($d['y'] + $d['h']) * $k) - $dy);
        imagecopyresampled($out, $src, $dx, $dy, (int) round($s['x']), (int) round($s['y']), $dl, $dh, (int) round($s['l']), (int) round($s['h']));
        imagedestroy($src);
        imagealphablending($out, true);

        $contour = $reglages['contour'];
        if (null !== $contour && 'forme' === $contour['type']) {
            if ('alpha' === $contour['source'] && !$info['transparence']) {
                throw new \DomainException('Pas de transparence dans ce fichier : la forme ne peut pas être suivie. Utilisez le rectangle intérieur, ou le détourage automatique si le fond est uni.');
            }
            self::contourForme($out, $contour, GeometrieRetouche::mmEnPx($contour['epaisseurMm'], $reglages['dpiSortie']), [$dx, $dy, $dl, $dh]);
        }

        // Marges du mode « adapter » : la couleur choisie sous l'image.
        $transparence = $info['transparence'] || (GeometrieRetouche::ADAPTER === $reglages['mode'] && 'transparent' === $reglages['fond']);
        if ('transparent' !== $reglages['fond'] && GeometrieRetouche::ADAPTER === $reglages['mode']) {
            $fond = imagecreatetruecolor($L, $H);
            [$r, $g, $b] = GeometrieRetouche::rvb($reglages['fond']);
            imagefill($fond, 0, 0, imagecolorallocate($fond, $r, $g, $b));
            imagealphablending($fond, true);
            imagecopy($fond, $out, 0, 0, 0, 0, $L, $H);
            imagedestroy($out);
            $out = $fond;
            imagesavealpha($out, true);
            $transparence = false;
        }

        if (null !== $contour && 'rectangle' === $contour['type']) {
            self::contourRectangle($out, $contour, $p['fondPerdu'], $k);
        }

        imageresolution($out, $reglages['dpiSortie'], $reglages['dpiSortie']);
        match ($format) {
            'png' => imagepng($out, $sortie, 6),
            'jpeg' => (static function () use ($out, $sortie): void {
                $aplat = PdfImage::surBlanc($out);
                imageresolution($aplat, imageresolution($out)[0], imageresolution($out)[1]);
                imagejpeg($aplat, $sortie, 92);
                imagedestroy($aplat);
            })(),
            default => $transparence = PdfImage::ecrire($sortie, $out, $p['sortieL'], $p['sortieH'], $p['fondPerdu'], $transparence, $titre),
        };
        imagedestroy($out);

        return ['largeurPx' => $L, 'hauteurPx' => $H, 'transparence' => 'jpeg' !== $format && $transparence];
    }

    /**
     * Trait qui part du bord du format fini (ou du retrait) et va vers
     * l'intérieur : rien n'est ajouté à l'extérieur.
     *
     * @param array{couleur: string, epaisseurMm: float, retraitMm: float} $contour
     */
    public static function contourRectangle(\GdImage $out, array $contour, float $fondPerduMm, float $k): void
    {
        $L = imagesx($out);
        $H = imagesy($out);
        $e = max(1, (int) round($contour['epaisseurMm'] * $k));
        $r = (int) round(($fondPerduMm + $contour['retraitMm']) * $k);
        if ($r * 2 >= min($L, $H)) {
            return;
        }
        [$cr, $cg, $cb] = GeometrieRetouche::rvb($contour['couleur']);
        $c = imagecolorallocate($out, $cr, $cg, $cb);
        imagealphablending($out, false);
        $x1 = $L - 1 - $r;
        $y1 = $H - 1 - $r;
        imagefilledrectangle($out, $r, $r, $x1, min($y1, $r + $e - 1), $c);
        imagefilledrectangle($out, $r, max($r, $y1 - $e + 1), $x1, $y1, $c);
        imagefilledrectangle($out, $r, $r, min($x1, $r + $e - 1), $y1, $c);
        imagefilledrectangle($out, max($r, $x1 - $e + 1), $r, $x1, $y1, $c);
        imagealphablending($out, true);
    }

    /**
     * Trait qui suit la silhouette, vers l'intérieur : distance de chaque
     * pixel de la forme au bord (chanfrein 5-7), puis le trait couvre les
     * pixels à moins de l'épaisseur, bords lissés. La forme vient de la
     * transparence (PNG), ou du fond uni détecté aux coins (JPG).
     *
     * @param array{couleur: string, source: string} $contour
     * @param array{0: int, 1: int, 2: int, 3: int} $zone image posée (x, y, largeur, hauteur) en pixels de sortie
     *
     * @throws \DomainException forme introuvable
     */
    public static function contourForme(\GdImage $out, array $contour, float $epaisseurPx, array $zone): void
    {
        $L = imagesx($out);
        $H = imagesy($out);
        $k = min(1.0, sqrt(self::TRAVAIL_MAX_PX / ($L * $H)));
        $travail = $out;
        if ($k < 1.0) {
            $travail = imagescale($out, max(1, (int) round($L * $k)), max(1, (int) round($H * $k)), \IMG_BILINEAR_FIXED);
            imagesavealpha($travail, true);
        }
        $w = imagesx($travail);
        $h = imagesy($travail);
        $zoneTravail = [(int) floor($zone[0] * $k), (int) floor($zone[1] * $k), max(1, (int) round($zone[2] * $k)), max(1, (int) round($zone[3] * $k))];
        $masque = 'auto' === $contour['source']
            ? self::masqueFondUni($travail, $zoneTravail)
            : self::masqueAlpha($travail);
        if ($travail !== $out) {
            imagedestroy($travail);
        }

        $t = $epaisseurPx * $k;
        $dist = self::distances($masque, $w, $h, (int) ceil($t) + 2);
        [$cr, $cg, $cb] = GeometrieRetouche::rvb($contour['couleur']);
        $couverture = static fn (int $i): float => $dist[$i] > 0 ? max(0.0, min(1.0, $t - $dist[$i] / 5 + 1)) : 0.0;

        imagealphablending($out, false);
        if ($k >= 1.0) {
            foreach ($dist as $i => $d) {
                if ($d > 0 && ($a = $couverture($i)) > 0) {
                    self::melanger($out, $i % $w, intdiv($i, $w), $cr, $cg, $cb, $a);
                }
            }
        } else {
            // Agrandi à la taille de sortie : couverture interpolée (bilinéaire) pour garder des bords lisses.
            foreach ($dist as $i => $d) {
                if ($d <= 0 || $couverture($i) <= 0) {
                    continue;
                }
                $x = $i % $w;
                $y = intdiv($i, $w);
                for ($Y = max(0, (int) ceil($y / $k - 0.5)), $Ymax = min($H - 1, (int) ceil(($y + 1) / $k - 0.5) - 1); $Y <= $Ymax; ++$Y) {
                    for ($X = max(0, (int) ceil($x / $k - 0.5)), $Xmax = min($L - 1, (int) ceil(($x + 1) / $k - 0.5) - 1); $X <= $Xmax; ++$X) {
                        $u = ($X + 0.5) * $k - 0.5;
                        $v = ($Y + 0.5) * $k - 0.5;
                        $x0 = max(0, min($w - 1, (int) floor($u)));
                        $y0 = max(0, min($h - 1, (int) floor($v)));
                        $x1 = min($w - 1, $x0 + 1);
                        $y1 = min($h - 1, $y0 + 1);
                        $fu = max(0.0, min(1.0, $u - $x0));
                        $fv = max(0.0, min(1.0, $v - $y0));
                        $a = (1 - $fv) * ((1 - $fu) * $couverture($y0 * $w + $x0) + $fu * $couverture($y0 * $w + $x1))
                            + $fv * ((1 - $fu) * $couverture($y1 * $w + $x0) + $fu * $couverture($y1 * $w + $x1));
                        if ($a > 0) {
                            self::melanger($out, $X, $Y, $cr, $cg, $cb, $a);
                        }
                    }
                }
            }
        }
        imagealphablending($out, true);
    }

    /**
     * Forme = pixels assez opaques. Il faut à la fois du transparent et de l'opaque.
     *
     * @return list<bool>
     */
    public static function masqueAlpha(\GdImage $im): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $masque = [];
        $dedans = 0;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $opaque = ((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 64;
                $masque[] = $opaque;
                $dedans += (int) $opaque;
            }
        }
        $part = $dedans / ($w * $h);
        if ($part > 0.995) {
            throw new \DomainException('Pas de transparence dans ce fichier : la forme ne peut pas être suivie. Utilisez le rectangle intérieur, ou le détourage automatique si le fond est uni.');
        }
        if ($part < 0.005) {
            throw new \DomainException('Image presque entièrement transparente : aucune forme à suivre.');
        }

        return $masque;
    }

    /**
     * Forme = tout ce qui n'est pas le fond : couleur lue aux quatre coins de
     * l'image posée, puis remplissage depuis les bords (et le transparent)
     * tant que la couleur reste proche. Une forme intérieure de la couleur du
     * fond mais entourée par le logo reste dans la forme.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $zone
     *
     * @return list<bool>
     */
    public static function masqueFondUni(\GdImage $im, array $zone): array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        [$zx, $zy, $zl, $zh] = $zone;
        $zx = max(0, min($w - 1, $zx));
        $zy = max(0, min($h - 1, $zy));
        $zx2 = max($zx, min($w - 1, $zx + $zl - 1));
        $zy2 = max($zy, min($h - 1, $zy + $zh - 1));
        $coins = [];
        foreach ([[$zx, $zy], [$zx2, $zy], [$zx, $zy2], [$zx2, $zy2]] as [$cx, $cy]) {
            $coins[] = self::moyenne($im, $cx, $cy, $zx, $zy, $zx2, $zy2);
        }
        foreach ($coins as $a) {
            foreach ($coins as $b) {
                if (self::ecart($a, $b) > self::TOLERANCE_FOND) {
                    throw new \DomainException('Le fond n\'est pas uni (les coins n\'ont pas la même couleur) : le détourage automatique n\'est pas possible. Utilisez le rectangle intérieur, ou détourez dans votre logiciel et réimportez un PNG.');
                }
            }
        }
        $fond = [array_sum(array_column($coins, 0)) / 4, array_sum(array_column($coins, 1)) / 4, array_sum(array_column($coins, 2)) / 4];

        $n = $w * $h;
        $estFond = array_fill(0, $n, false);
        $pile = [];
        $proche = static function (int $c) use ($fond): bool {
            if ((($c >> 24) & 0x7F) >= 64) {
                return true;
            }

            return self::ecart([($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF], $fond) <= self::TOLERANCE_FOND;
        };
        for ($x = 0; $x < $w; ++$x) {
            $pile[] = $x;
            $pile[] = ($h - 1) * $w + $x;
        }
        for ($y = 0; $y < $h; ++$y) {
            $pile[] = $y * $w;
            $pile[] = $y * $w + $w - 1;
        }
        while ([] !== $pile) {
            $i = array_pop($pile);
            if ($estFond[$i]) {
                continue;
            }
            $x = $i % $w;
            $y = intdiv($i, $w);
            if (!$proche(imagecolorat($im, $x, $y))) {
                continue;
            }
            $estFond[$i] = true;
            if ($x > 0 && !$estFond[$i - 1]) {
                $pile[] = $i - 1;
            }
            if ($x < $w - 1 && !$estFond[$i + 1]) {
                $pile[] = $i + 1;
            }
            if ($y > 0 && !$estFond[$i - $w]) {
                $pile[] = $i - $w;
            }
            if ($y < $h - 1 && !$estFond[$i + $w]) {
                $pile[] = $i + $w;
            }
        }
        $masque = [];
        $dedans = 0;
        foreach ($estFond as $f) {
            $masque[] = !$f;
            $dedans += (int) !$f;
        }
        $part = $dedans / $n;
        if ($part < 0.005 || $part > 0.97) {
            throw new \DomainException('Aucune forme nette trouvée sur le fond : le détourage automatique n\'est pas possible. Utilisez le rectangle intérieur.');
        }

        return $masque;
    }

    /**
     * Distance de chaque pixel de la forme au plus proche pixel hors forme
     * (chanfrein 5-7 : 5 par pas droit, 7 en diagonale ; hors de l'image =
     * hors forme). 0 hors forme. Bornée à $max pixels.
     *
     * @param list<bool> $masque
     *
     * @return list<int>
     */
    public static function distances(array $masque, int $w, int $h, int $max): array
    {
        $inf = ($max + 1) * 5;
        $d = [];
        foreach ($masque as $dedans) {
            $d[] = $dedans ? $inf : 0;
        }
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $i = $y * $w + $x;
                if (0 === $d[$i]) {
                    continue;
                }
                $v = $d[$i];
                $v = min($v, $x > 0 ? $d[$i - 1] + 5 : 5);
                if ($y > 0) {
                    $v = min($v, $d[$i - $w] + 5, $x > 0 ? $d[$i - $w - 1] + 7 : 5, $x < $w - 1 ? $d[$i - $w + 1] + 7 : 5);
                } else {
                    $v = min($v, 5);
                }
                $d[$i] = $v;
            }
        }
        for ($y = $h - 1; $y >= 0; --$y) {
            for ($x = $w - 1; $x >= 0; --$x) {
                $i = $y * $w + $x;
                if (0 === $d[$i]) {
                    continue;
                }
                $v = $d[$i];
                $v = min($v, $x < $w - 1 ? $d[$i + 1] + 5 : 5);
                if ($y < $h - 1) {
                    $v = min($v, $d[$i + $w] + 5, $x < $w - 1 ? $d[$i + $w + 1] + 7 : 5, $x > 0 ? $d[$i + $w - 1] + 7 : 5);
                } else {
                    $v = min($v, 5);
                }
                $d[$i] = $v;
            }
        }

        return $d;
    }

    private static function melanger(\GdImage $im, int $x, int $y, int $r, int $g, int $b, float $a): void
    {
        $c = imagecolorat($im, $x, $y);
        $alpha = ($c >> 24) & 0x7F;
        imagesetpixel($im, $x, $y, imagecolorallocatealpha(
            $im,
            (int) round((($c >> 16) & 0xFF) * (1 - $a) + $r * $a),
            (int) round((($c >> 8) & 0xFF) * (1 - $a) + $g * $a),
            (int) round(($c & 0xFF) * (1 - $a) + $b * $a),
            $alpha,
        ));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function moyenne(\GdImage $im, int $cx, int $cy, int $x0, int $y0, int $x1, int $y1): array
    {
        $s = [0, 0, 0];
        $n = 0;
        for ($y = max($y0, $cy - 2); $y <= min($y1, $cy + 2); ++$y) {
            for ($x = max($x0, $cx - 2); $x <= min($x1, $cx + 2); ++$x) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 24) & 0x7F) >= 64) {
                    // Coin transparent : compte comme du blanc (fond le plus courant).
                    $s[0] += 255;
                    $s[1] += 255;
                    $s[2] += 255;
                } else {
                    $s[0] += ($c >> 16) & 0xFF;
                    $s[1] += ($c >> 8) & 0xFF;
                    $s[2] += $c & 0xFF;
                }
                ++$n;
            }
        }

        return [$s[0] / max(1, $n), $s[1] / max(1, $n), $s[2] / max(1, $n)];
    }

    /** @param array{0: float|int, 1: float|int, 2: float|int} $a @param array{0: float|int, 1: float|int, 2: float|int} $b */
    private static function ecart(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /** @param array{type: string, rotation: int} $info */
    private static function charger(string $chemin, array $info): \GdImage
    {
        $im = match ($info['type']) {
            'image/jpeg' => @imagecreatefromjpeg($chemin),
            'image/png' => @imagecreatefrompng($chemin),
            'image/webp' => @imagecreatefromwebp($chemin),
            'image/gif' => @imagecreatefromgif($chemin),
            default => false,
        };
        if (!$im instanceof \GdImage) {
            throw new \DomainException('Le fichier est abîmé ou illisible.');
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        if (0 !== $info['rotation']) {
            // Photo de téléphone : tournée comme elle s'affiche (EXIF), comme dans le navigateur.
            $tournee = imagerotate($im, -$info['rotation'], imagecolorallocatealpha($im, 0, 0, 0, 127));
            imagedestroy($im);
            $im = $tournee;
        }

        return $im;
    }

    /** Angle (sens horaire) à appliquer pour afficher la photo droite, d'après l'EXIF. */
    private static function rotationExif(string $chemin): int
    {
        if (!\function_exists('exif_read_data')) {
            return 0;
        }
        $exif = @exif_read_data($chemin);

        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => 90,
            8 => -90,
            default => 0,
        };
    }

    private static function aUnCanalAlpha(string $chemin, string $type): bool
    {
        if ('image/png' === $type) {
            $f = @fopen($chemin, 'rb');
            if (false === $f) {
                return false;
            }
            $tete = (string) fread($f, 4096);
            fclose($f);

            // Type de couleur 4 ou 6 (avec alpha), ou bloc tRNS (couleur transparente).
            return \in_array(\ord($tete[25] ?? "\0"), [4, 6], true) || str_contains($tete, 'tRNS');
        }

        return \in_array($type, ['image/webp', 'image/gif'], true);
    }

    /** Résolution écrite dans le fichier (JFIF ou pHYs), ou null : souvent 72, sans rapport avec l'impression. */
    private static function dpiDeclare(string $chemin, string $type): ?int
    {
        $f = @fopen($chemin, 'rb');
        if (false === $f) {
            return null;
        }
        $tete = (string) fread($f, 65536);
        fclose($f);
        if ('image/jpeg' === $type && false !== ($p = strpos($tete, "JFIF\0")) && \strlen($tete) > $p + 12) {
            $unite = \ord($tete[$p + 7]);
            $x = unpack('n', substr($tete, $p + 8, 2))[1];

            return match ($unite) {
                1 => $x ?: null,
                2 => $x ? (int) round($x * 2.54) : null,
                default => null,
            };
        }
        if ('image/png' === $type && false !== ($p = strpos($tete, 'pHYs')) && \strlen($tete) > $p + 13) {
            $x = unpack('N', substr($tete, $p + 4, 4))[1];

            return 1 === \ord($tete[$p + 12]) && $x > 0 ? (int) round($x * 0.0254) : null;
        }

        return null;
    }

    private static function assezDeMemoire(int $pixels): void
    {
        $besoin = $pixels * 5 + 256 * 1024 * 1024;
        $limite = self::octets((string) ini_get('memory_limit'));
        if ($limite > 0 && $limite < $besoin) {
            @ini_set('memory_limit', (string) $besoin);
        }
    }

    private static function octets(string $v): int
    {
        $v = trim($v);
        if ('-1' === $v || '' === $v) {
            return -1;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
