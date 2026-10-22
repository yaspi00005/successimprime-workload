<?php

namespace App\Service\Conception\Generateur;

use App\Service\Conception\PromptConception;

/**
 * Générateur de démonstration (développement, essais, données de démo) :
 * une affiche dessinée avec GD à partir des textes du client, marquée
 * « aperçu de démonstration ». Aucun appel réseau. Refusé en production.
 */
final class GenerateurDemo implements GenerateurImages
{
    public function __construct(
        private readonly string $dossierPolices,
        private readonly bool $production = false,
    ) {
    }

    public function nom(): string
    {
        return 'demo';
    }

    public function modele(): string
    {
        return 'apercu-gd';
    }

    public function estDisponible(): bool
    {
        return !$this->production && \function_exists('imagecreatetruecolor');
    }

    /**
     * @param array<string, string> $textes entete, titre, sousTitre, date, contact, couleur1, couleur2, graine
     */
    public function generer(string $prompt, string $orientation, array $textes = []): ImageGeneree
    {
        if (!$this->estDisponible()) {
            throw new \RuntimeException('Générateur de démonstration indisponible.');
        }
        [$l, $h] = match ($orientation) {
            PromptConception::PORTRAIT => [768, 1152],
            PromptConception::PAYSAGE => [1152, 768],
            default => [960, 960],
        };
        $graine = crc32(($textes['graine'] ?? '').$prompt);
        $image = imagecreatetruecolor($l, $h);
        imagealphablending($image, true);

        $fond = self::rgb($textes['couleur1'] ?? '', [19, 40, 92]);
        $accent = self::rgb($textes['couleur2'] ?? '', [242, 194, 48]);
        if (self::clair($fond)) {
            // Fond trop clair pour un texte blanc : le fond prend la couleur foncée.
            [$fond, $accent] = [[19, 40, 92], $fond];
        }
        // Dégradé vertical du fond vers une teinte plus sombre.
        for ($y = 0; $y < $h; ++$y) {
            $t = $y / $h;
            $c = imagecolorallocate($image, ...array_map(static fn (int $v): int => (int) round($v * (1 - 0.55 * $t)), $fond));
            imageline($image, 0, $y, $l, $y, $c);
        }
        // Halo et disque d'accent, placés selon l'image (chaque génération diffère).
        $cx = (int) ($l * (0.55 + (($graine % 25) / 100)));
        $cy = (int) ($h * (0.2 + ((intdiv($graine, 25) % 12) / 100)));
        $r = (int) (min($l, $h) * (0.11 + (($graine % 7) / 100)));
        imagefilledellipse($image, $cx, $cy, $r * 5, $r * 5, imagecolorallocatealpha($image, $accent[0], $accent[1], $accent[2], 112));
        imagefilledellipse($image, $cx, $cy, $r * 2, $r * 2, imagecolorallocate($image, ...$accent));
        // Vague claire en bas.
        imagefilledellipse($image, (int) ($l * 0.3), (int) ($h * 1.02), (int) ($l * 1.9), (int) ($h * 0.42), imagecolorallocatealpha($image, $accent[0], $accent[1], $accent[2], 100));
        imagefilledrectangle($image, 0, (int) ($h * 0.86), $l, $h, imagecolorallocatealpha($image, 0, 0, 0, 70));

        $blanc = imagecolorallocate($image, 255, 255, 255);
        $or = imagecolorallocate($image, ...$accent);
        $marge = (int) ($l * 0.08);
        $largeurTexte = $l - 2 * $marge;

        $y = (int) ($h * 0.09);
        if ('' !== ($entete = trim($textes['entete'] ?? ''))) {
            $y = $this->ecrire($image, mb_strtoupper($entete), $marge, $y, (int) ($l * 0.032), $largeurTexte * 0.7, $blanc, false, 3) + (int) ($h * 0.02);
        }
        $titre = mb_strtoupper(trim($textes['titre'] ?? '') ?: 'Votre titre');
        $y = max($y, (int) ($h * 0.42));
        $y = $this->ecrire($image, $titre, $marge, $y, (int) ($l * 0.105), $largeurTexte, $blanc, true, 3) + (int) ($h * 0.015);
        if ('' !== ($sous = trim($textes['sousTitre'] ?? ''))) {
            $y = $this->ecrire($image, $sous, $marge, $y, (int) ($l * 0.05), $largeurTexte, $or, true, 2);
        }
        $bas = (int) ($h * 0.74);
        if ('' !== ($date = trim($textes['date'] ?? ''))) {
            $bas = $this->ecrire($image, $date, $marge, $bas, (int) ($l * 0.04), $largeurTexte, $blanc, false, 2) + (int) ($h * 0.008);
        }
        if ('' !== ($contact = trim($textes['contact'] ?? ''))) {
            $this->ecrire($image, $contact, $marge, $bas, (int) ($l * 0.04), $largeurTexte, $blanc, true, 2);
        }
        $this->ecrire($image, 'APERÇU DE DÉMONSTRATION · PAS UNE IMAGE IA', $marge, (int) ($h * 0.905), (int) ($l * 0.026), $largeurTexte, imagecolorallocate($image, 255, 255, 255), true, 1);

        ob_start();
        imagepng($image, null, 6);
        $contenu = (string) ob_get_clean();

        return ImageGeneree::depuis($contenu);
    }

    /**
     * Écrit un texte coupé en lignes, rend le bas du bloc. Police TrueType
     * si GD la sait, sinon la police intégrée agrandie (aspect pixel).
     */
    private function ecrire(\GdImage $image, string $texte, int $x, int $y, int $taille, float $largeur, int $couleur, bool $gras, int $lignesMax): int
    {
        $police = $this->dossierPolices.'/'.($gras ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');
        $ttf = \function_exists('imagettftext') && is_file($police);
        $mesure = $ttf
            ? static fn (string $t): int => (int) (imagettfbbox($taille, 0, $police, $t)[2] ?? 0)
            : static fn (string $t): int => \strlen(self::ascii($t)) * imagefontwidth(5) * self::echelle($taille);

        $lignes = [];
        $ligne = '';
        foreach (preg_split('/\s+/u', $texte) ?: [] as $mot) {
            $essai = '' === $ligne ? $mot : $ligne.' '.$mot;
            if ('' !== $ligne && $mesure($essai) > $largeur) {
                $lignes[] = $ligne;
                $ligne = $mot;
            } else {
                $ligne = $essai;
            }
        }
        $lignes[] = $ligne;
        $lignes = \array_slice($lignes, 0, $lignesMax);

        $pas = (int) ($taille * 1.25);
        foreach ($lignes as $i => $t) {
            if ($ttf) {
                imagettftext($image, $taille, 0, $x, $y + $taille + $i * $pas, $couleur, $police, $t);
                continue;
            }
            $echelle = self::echelle($taille);
            $t = self::ascii($t);
            $petit = imagecreatetruecolor(max(1, \strlen($t) * imagefontwidth(5)), imagefontheight(5));
            imagesavealpha($petit, true);
            imagefill($petit, 0, 0, imagecolorallocatealpha($petit, 0, 0, 0, 127));
            imagestring($petit, 5, 0, 0, $t, imagecolorallocate($petit, ...self::composantes($image, $couleur)));
            imagecopyresized($image, $petit, $x, $y + $i * $pas, 0, 0, imagesx($petit) * $echelle, imagesy($petit) * $echelle, imagesx($petit), imagesy($petit));
        }

        return $y + \count($lignes) * $pas;
    }

    private static function echelle(int $taille): int
    {
        return max(1, (int) round($taille / 12));
    }

    private static function ascii(string $texte): string
    {
        $t = \function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte) : false;

        return false !== $t ? $t : (preg_replace('/[^\x20-\x7E]/', '?', $texte) ?? '');
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function composantes(\GdImage $image, int $couleur): array
    {
        $c = imagecolorsforindex($image, $couleur);

        return [$c['red'], $c['green'], $c['blue']];
    }

    /**
     * @param array{0: int, 1: int, 2: int} $defaut
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex, array $defaut): array
    {
        if (!preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m)) {
            return $defaut;
        }

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }

    /** @param array{0: int, 1: int, 2: int} $c */
    private static function clair(array $c): bool
    {
        return (0.299 * $c[0] + 0.587 * $c[1] + 0.114 * $c[2]) > 170;
    }
}
