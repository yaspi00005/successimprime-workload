<?php

namespace App\Service\EspaceClient;

use App\Service\AnalyseFichierImpression;

/**
 * « Texte près du bord » (nouvelle commande, maquette CO03) : une image
 * JPG ou PNG montre-t-elle des détails nets (texte, logo, filet) dans la
 * zone de sécurité, là où passent les œillets ou la coupe ? Simple repère
 * pour le client, jamais bloquant : le graphiste vérifie au BAT et décale
 * sans frais. PDF et fichiers de travail : « à vérifier » par le graphiste.
 *
 * L'image est réduite (COTE px au plus) avant la mesure : quelques
 * dizaines de millisecondes, même pour une grande bâche.
 */
final class BordsFichier
{
    public const LIBELLE = 'Texte près du bord';

    /** Côté le plus long de l'image réduite, en pixels. */
    private const COTE = 800;

    /** Au-delà, l'image n'est pas ouverte (mémoire du serveur). */
    private const PIXELS_MAX = 60_000_000;

    /** Écart de luminosité entre deux pixels voisins qui fait un bord net (texte, filet). */
    private const SEUIL_NET = 70;

    /** Part de pixels nets dans un morceau de la zone de sécurité au-delà de laquelle on prévient. */
    private const DENSITE_MAX = 0.12;

    /** Chaque bord est coupé en autant de morceaux (un texte occupe une petite partie du bord). */
    private const MORCEAUX = 24;

    /** Marge de sécurité selon la taille : œillets d'une bâche, coupe d'un petit format. */
    public static function marge(float $largeurCm, float $hauteurCm): float
    {
        $cote = max($largeurCm, $hauteurCm);

        return match (true) {
            $cote >= 100 => 4.0,
            $cote >= 30 => 2.0,
            default => 0.4,
        };
    }

    /**
     * @return array{libelle: string, valeur: string, etat: string}
     */
    public static function controler(string $chemin, ?float $largeurCm, ?float $hauteurCm, bool $oeillets = false): array
    {
        if (!($largeurCm > 0) || !($hauteurCm > 0)) {
            return self::aVerifier('Vérifié par le graphiste au BAT.');
        }
        $marge = self::marge($largeurCm, $hauteurCm);
        $risque = $oeillets ? 'risque avec les œillets' : 'risque à la coupe';
        $info = @getimagesize($chemin);
        if (false === $info || !\in_array($info[2], [\IMAGETYPE_JPEG, \IMAGETYPE_PNG], true) || !\function_exists('imagecreatefromjpeg')) {
            return self::aVerifier(\sprintf('Le graphiste vérifie qu\'aucun texte n\'est à moins de %s cm du bord (%s).', self::cm($marge), $risque));
        }
        [$largeur, $hauteur] = [(int) $info[0], (int) $info[1]];
        if ($largeur * $hauteur > self::PIXELS_MAX || !self::memoireSuffisante($largeur * $hauteur)) {
            return self::aVerifier(\sprintf('Image très grande : le graphiste vérifie la zone de %s cm le long des bords.', self::cm($marge)));
        }
        $image = \IMAGETYPE_JPEG === $info[2] ? @imagecreatefromjpeg($chemin) : @imagecreatefrompng($chemin);
        if (false === $image) {
            return self::aVerifier('Image illisible : vérifiée par le graphiste.');
        }
        $ratio = min(1, self::COTE / max($largeur, $hauteur));
        $petite = imagescale($image, max(8, (int) round($largeur * $ratio)), max(8, (int) round($hauteur * $ratio)), \IMG_BILINEAR_FIXED);
        imagedestroy($image);
        if (false === $petite) {
            return self::aVerifier('Image illisible : vérifiée par le graphiste.');
        }
        // Le fichier peut être tourné d'un quart de tour par rapport à la commande : côté long sur côté long.
        $tourne = (imagesx($petite) >= imagesy($petite)) !== ($largeurCm >= $hauteurCm);
        [$lCm, $hCm] = $tourne ? [$hauteurCm, $largeurCm] : [$largeurCm, $hauteurCm];
        $densite = self::densiteMaxAuxBords(self::gris($petite), $marge / $lCm, $marge / $hCm);
        imagedestroy($petite);

        return $densite > self::DENSITE_MAX
            ? ['libelle' => self::LIBELLE, 'valeur' => \sprintf('Des détails (texte, logo) semblent à moins de %s cm du bord : %s. Le graphiste les décalera, sans frais.', self::cm($marge), $risque), 'etat' => AnalyseFichierImpression::ATTENTION]
            : ['libelle' => self::LIBELLE, 'valeur' => \sprintf('Rien d\'important à moins de %s cm du bord.', self::cm($marge)), 'etat' => AnalyseFichierImpression::OK];
    }

    /**
     * Plus forte densité de pixels nets parmi les morceaux des quatre zones
     * de sécurité (bandes le long des bords, en part de la largeur et de
     * la hauteur).
     *
     * @param list<list<int>> $gris luminosité 0-255, ligne par ligne
     */
    public static function densiteMaxAuxBords(array $gris, float $partLargeur, float $partHauteur): float
    {
        $h = \count($gris);
        $l = \count($gris[0] ?? []);
        if ($h < 4 || $l < 4) {
            return 0.0;
        }
        $bandeX = max(2, min((int) floor($l / 3), (int) round($partLargeur * $l)));
        $bandeY = max(2, min((int) floor($h / 3), (int) round($partHauteur * $h)));
        $net = static function (int $x, int $y) use ($gris, $l, $h): bool {
            $v = $gris[$y][$x];

            return ($x + 1 < $l && abs($gris[$y][$x + 1] - $v) > self::SEUIL_NET) || ($y + 1 < $h && abs($gris[$y + 1][$x] - $v) > self::SEUIL_NET);
        };
        $zones = [];
        // Bandes du haut et du bas, coupées sur la largeur ; bandes de gauche et de droite, coupées sur la hauteur.
        // Le dernier pixel du bord (filet de coupe, fond perdu) est laissé de côté.
        foreach ([[1, $bandeY], [$h - $bandeY, $h - 1]] as [$y0, $y1]) {
            for ($m = 0; $m < self::MORCEAUX; ++$m) {
                $zones[] = [(int) floor($m * $l / self::MORCEAUX), (int) floor(($m + 1) * $l / self::MORCEAUX), $y0, $y1];
            }
        }
        foreach ([[1, $bandeX], [$l - $bandeX, $l - 1]] as [$x0, $x1]) {
            for ($m = 0; $m < self::MORCEAUX; ++$m) {
                $zones[] = [$x0, $x1, max($bandeY, (int) floor($m * $h / self::MORCEAUX)), min($h - $bandeY, (int) floor(($m + 1) * $h / self::MORCEAUX))];
            }
        }
        $max = 0.0;
        foreach ($zones as [$x0, $x1, $y0, $y1]) {
            $total = 0;
            $nets = 0;
            for ($y = $y0; $y < $y1; ++$y) {
                for ($x = max(1, $x0); $x < min($l - 1, $x1); ++$x) {
                    ++$total;
                    $nets += $net($x, $y) ? 1 : 0;
                }
            }
            if ($total >= 6) {
                $max = max($max, $nets / $total);
            }
        }

        return $max;
    }

    /** @return list<list<int>> */
    private static function gris(\GdImage $image): array
    {
        $l = imagesx($image);
        $h = imagesy($image);
        $vraiesCouleurs = imageistruecolor($image);
        $gris = [];
        for ($y = 0; $y < $h; ++$y) {
            $ligne = [];
            for ($x = 0; $x < $l; ++$x) {
                $c = imagecolorat($image, $x, $y);
                [$r, $v, $b] = $vraiesCouleurs ? [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF] : array_values(\array_slice(imagecolorsforindex($image, $c), 0, 3));
                $ligne[] = (int) (0.299 * $r + 0.587 * $v + 0.114 * $b);
            }
            $gris[] = $ligne;
        }

        return $gris;
    }

    private static function memoireSuffisante(int $pixels): bool
    {
        $limite = trim((string) \ini_get('memory_limit'));
        if ('' === $limite || '-1' === $limite) {
            return true;
        }
        $octets = (int) $limite * match (strtolower(substr($limite, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        // Image ouverte (4 octets par pixel et un peu plus) et sa copie réduite.
        return $pixels * 5 < 0.8 * ($octets - memory_get_usage());
    }

    /** @return array{libelle: string, valeur: string, etat: string} */
    private static function aVerifier(string $valeur): array
    {
        return ['libelle' => self::LIBELLE, 'valeur' => $valeur, 'etat' => AnalyseFichierImpression::A_VERIFIER];
    }

    private static function cm(float $cm): string
    {
        return str_replace('.', ',', (string) round($cm, 1));
    }
}
