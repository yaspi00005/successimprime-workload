<?php

namespace App\Service\DossiersClients;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Miniatures des fichiers des clients (maquette DS01), faites par
 * l'analyse planifiée et gardées sous var/dossiers-clients/miniatures :
 * jamais pendant l'affichage d'une page.
 *
 * - JPG, PNG, GIF, WebP : réduites avec GD (pas au-delà de 60 millions de
 *   pixels, pour ne pas épuiser la mémoire).
 * - AI, INDD, PDF : l'aperçu JPEG que les logiciels Adobe rangent dans le
 *   fichier (métadonnées XMP « xmpGImg:image ») ; un AI enregistré
 *   « compatible PDF » en a presque toujours un.
 * - PSD : l'aperçu JPEG rangé dans l'en-tête du fichier (ressource 1036).
 * - Avec l'extension Imagick (si elle est installée un jour), la première
 *   page des PDF.
 * Sinon : pas de miniature, l'écran montre une icône du type.
 */
final class Miniatures
{
    public const LARGEUR = 360;
    private const PIXELS_MAX = 60_000_000;
    private const LECTURE = 6 * 1024 * 1024;

    public function __construct(#[Autowire('%kernel.project_dir%/var/dossiers-clients/miniatures')] private readonly string $dossier)
    {
    }

    public function dossier(): string
    {
        return $this->dossier;
    }

    /** Chemin d'une miniature déjà faite, ou null (nom contrôlé : pas de remontée). */
    public function chemin(?string $nom): ?string
    {
        if (null === $nom || !preg_match('/^[a-f0-9]{40}\.jpg$/', $nom)) {
            return null;
        }
        $chemin = $this->dossier.'/'.$nom;

        return is_file($chemin) ? $chemin : null;
    }

    /**
     * Fait la miniature d'un fichier (si elle n'existe pas déjà pour cette
     * version du fichier) et renvoie son nom, ou null.
     */
    public function faire(string $chemin, string $signature): ?string
    {
        $nom = sha1($chemin.'|'.$signature).'.jpg';
        if (null !== $this->chemin($nom)) {
            return $nom;
        }
        $jpeg = $this->apercu($chemin);
        if (null === $jpeg) {
            return null;
        }
        $image = @imagecreatefromstring($jpeg);
        if (false === $image) {
            return null;
        }
        $reduite = self::reduire($image);
        if (!is_dir($this->dossier) && !@mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            return null;
        }
        $ok = imagejpeg($reduite, $this->dossier.'/'.$nom, 78);

        return $ok ? $nom : null;
    }

    /** Octets d'une image (JPEG, PNG…) qui représente le fichier, ou null. */
    public function apercu(string $chemin): ?string
    {
        $ext = strtolower(pathinfo($chemin, \PATHINFO_EXTENSION));
        $taille = (int) @filesize($chemin);
        if ($taille <= 0) {
            return null;
        }
        if (\in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $info = @getimagesize($chemin);
            if (false === $info || $info[0] * $info[1] > self::PIXELS_MAX || $taille > 80 * 1024 * 1024) {
                return null;
            }
            $octets = @file_get_contents($chemin);

            return false === $octets ? null : $octets;
        }
        if ('psd' === $ext || 'psb' === $ext) {
            return self::apercuPsd($chemin);
        }
        if (\in_array($ext, ['ai', 'indd', 'pdf', 'eps'], true)) {
            $xmp = self::apercuXmp(self::lire($chemin));
            if (null !== $xmp) {
                return $xmp;
            }
            if ('pdf' === $ext || 'ai' === $ext) {
                return self::premierePageImagick($chemin);
            }
        }

        return null;
    }

    /**
     * L'aperçu JPEG des métadonnées XMP d'un fichier Adobe
     * (« <xmpGImg:image>…base64…</xmpGImg:image> »).
     */
    public static function apercuXmp(string $contenu): ?string
    {
        if (!preg_match('#<xmpGImg:image>([A-Za-z0-9+/=\s&\#;x]{200,})</xmpGImg:image>#', $contenu, $m)
            && !preg_match('#xmpGImg:image="([A-Za-z0-9+/=\s&\#;x]{200,})"#', $contenu, $m)) {
            return null;
        }
        // Les retours à la ligne sont souvent écrits « &#xA; ».
        $b64 = (string) preg_replace('/&#x?[0-9A-Fa-f]+;|\s+/', '', $m[1]);
        $jpeg = base64_decode($b64, true);

        return false !== $jpeg && str_starts_with($jpeg, "\xFF\xD8") ? $jpeg : null;
    }

    /** Aperçu JPEG d'un PSD : ressource 1036 (ou 1033, plus ancienne) de la section des ressources. */
    public static function apercuPsd(string $chemin): ?string
    {
        $f = @fopen($chemin, 'rb');
        if (false === $f) {
            return null;
        }
        try {
            $entete = (string) fread($f, 26);
            if (!str_starts_with($entete, '8BPS')) {
                return null;
            }
            $modeCouleurs = unpack('N', (string) fread($f, 4));
            if (false === $modeCouleurs) {
                return null;
            }
            fseek($f, $modeCouleurs[1], \SEEK_CUR);
            $long = unpack('N', (string) fread($f, 4));
            if (false === $long) {
                return null;
            }
            $fin = ftell($f) + min($long[1], self::LECTURE);
            while (ftell($f) < $fin - 12) {
                if ('8BIM' !== fread($f, 4)) {
                    return null;
                }
                $id = unpack('n', (string) fread($f, 2));
                $lNom = \ord((string) fread($f, 1));
                fseek($f, $lNom + (0 === ($lNom + 1) % 2 ? 0 : 1), \SEEK_CUR);
                $t = unpack('N', (string) fread($f, 4));
                if (false === $id || false === $t) {
                    return null;
                }
                if (\in_array($id[1], [1036, 1033], true) && $t[1] > 28) {
                    fseek($f, 28, \SEEK_CUR);
                    $jpeg = (string) fread($f, $t[1] - 28);

                    return str_starts_with($jpeg, "\xFF\xD8") ? $jpeg : null;
                }
                fseek($f, $t[1] + ($t[1] % 2), \SEEK_CUR);
            }
        } finally {
            fclose($f);
        }

        return null;
    }

    private static function premierePageImagick(string $chemin): ?string
    {
        if (!class_exists(\Imagick::class)) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->setResolution(48, 48);
            $im->readImage($chemin.'[0]');
            $im->setImageFormat('jpeg');

            return $im->getImageBlob();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function reduire(\GdImage $image): \GdImage
    {
        $l = imagesx($image);
        $h = imagesy($image);
        $largeur = min(self::LARGEUR, $l);
        $hauteur = max(1, (int) round($h * $largeur / max(1, $l)));
        $sortie = imagecreatetruecolor($largeur, $hauteur);
        imagefill($sortie, 0, 0, (int) imagecolorallocate($sortie, 255, 255, 255));
        imagecopyresampled($sortie, $image, 0, 0, 0, 0, $largeur, $hauteur, $l, $h);

        return $sortie;
    }

    /** Début et fin du fichier : l'XMP est souvent au début (AI) ou à la fin (PDF). */
    private static function lire(string $chemin): string
    {
        $f = @fopen($chemin, 'rb');
        if (false === $f) {
            return '';
        }
        $debut = (string) fread($f, self::LECTURE);
        $taille = (int) @filesize($chemin);
        $fin = '';
        if ($taille > 2 * self::LECTURE) {
            fseek($f, -self::LECTURE, \SEEK_END);
            $fin = (string) fread($f, self::LECTURE);
        }
        fclose($f);

        return $debut.$fin;
    }
}
