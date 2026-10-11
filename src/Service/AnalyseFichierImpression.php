<?php

namespace App\Service;

/**
 * Contrôle automatique d'un fichier client avant impression (maquette
 * 25) : résolution réelle au format commandé, proportions, mode de
 * couleurs et polices. Ce qui ne peut pas être lu dans le fichier est
 * signalé « à vérifier » : l'infographiste garde la main.
 */
final class AnalyseFichierImpression
{
    public const OK = 'ok';
    public const ATTENTION = 'attention';
    public const ERREUR = 'erreur';
    public const A_VERIFIER = 'a_verifier';

    /** Octets lus dans un PDF pour y chercher format, couleurs et polices. */
    private const LECTURE_PDF = 4 * 1024 * 1024;

    /**
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public function analyser(string $chemin, ?float $largeurCm, ?float $hauteurCm): array
    {
        $image = @getimagesize($chemin);
        if (false !== $image && $image[0] > 0 && $image[1] > 0) {
            return $this->analyserImage($image, $largeurCm, $hauteurCm);
        }

        $debut = (string) @file_get_contents($chemin, false, null, 0, self::LECTURE_PDF);
        if (str_starts_with($debut, '%PDF')) {
            return $this->analyserPdf($debut, $largeurCm, $hauteurCm);
        }

        return [[
            'libelle' => 'Type de fichier',
            'valeur' => 'Non lisible automatiquement (fichier de travail ou archive) : à ouvrir dans le logiciel',
            'etat' => self::A_VERIFIER,
        ]];
    }

    /**
     * Résolution conseillée selon la taille : de près, un petit format
     * se regarde à la main ; une bâche se regarde de loin.
     */
    public static function resolutionConseillee(float $plusGrandCoteCm): int
    {
        return match (true) {
            $plusGrandCoteCm <= 50 => 300,
            $plusGrandCoteCm <= 150 => 150,
            default => 72,
        };
    }

    /**
     * @return array{libelle: string, valeur: string, etat: string}
     */
    public static function controleResolution(int $largeurPx, int $hauteurPx, float $largeurCm, float $hauteurCm): array
    {
        // Le fichier peut être tourné d'un quart de tour : on compare côté long à côté long.
        $dpi = (int) floor(min(
            max($largeurPx, $hauteurPx) / (max($largeurCm, $hauteurCm) / 2.54),
            min($largeurPx, $hauteurPx) / (min($largeurCm, $hauteurCm) / 2.54),
        ));
        $conseil = self::resolutionConseillee(max($largeurCm, $hauteurCm));

        return [
            'libelle' => sprintf('Résolution réelle à %s × %s cm', self::cm($largeurCm), self::cm($hauteurCm)),
            'valeur' => sprintf('%d dpi (conseillé : %d)', $dpi, $conseil),
            'etat' => $dpi >= $conseil ? self::OK : ($dpi >= 0.7 * $conseil ? self::ATTENTION : self::ERREUR),
        ];
    }

    /**
     * @return array{libelle: string, valeur: string, etat: string}
     */
    public static function controleProportions(float $largeurFichier, float $hauteurFichier, float $largeurCm, float $hauteurCm): array
    {
        $rapportFichier = min($largeurFichier, $hauteurFichier) / max($largeurFichier, $hauteurFichier);
        $rapportCommande = min($largeurCm, $hauteurCm) / max($largeurCm, $hauteurCm);
        $ecart = abs($rapportFichier - $rapportCommande) / $rapportCommande;
        $tourne = ($largeurFichier >= $hauteurFichier) !== ($largeurCm >= $hauteurCm) && abs($largeurCm - $hauteurCm) > 0.01;

        return [
            'libelle' => 'Ratio des dimensions',
            'valeur' => sprintf(
                '%s · %s%s',
                number_format($rapportFichier, 3, ',', ' '),
                $ecart <= 0.02 ? 'conforme' : sprintf('écart de %d %% avec la commande (%s)', (int) round($ecart * 100), number_format($rapportCommande, 3, ',', ' ')),
                $tourne ? ' · fichier tourné' : '',
            ),
            'etat' => $ecart <= 0.02 ? ($tourne ? self::ATTENTION : self::OK) : ($ecart <= 0.05 ? self::ATTENTION : self::ERREUR),
        ];
    }

    /**
     * @param array<int|string, mixed> $image résultat de getimagesize()
     *
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    private function analyserImage(array $image, ?float $largeurCm, ?float $hauteurCm): array
    {
        [$largeurPx, $hauteurPx] = [(int) $image[0], (int) $image[1]];
        $controles = [];
        if ($largeurCm > 0 && $hauteurCm > 0) {
            $controles[] = self::controleResolution($largeurPx, $hauteurPx, $largeurCm, $hauteurCm);
        } else {
            $controles[] = ['libelle' => 'Résolution', 'valeur' => sprintf('%d × %d px, dimensions de la commande inconnues', $largeurPx, $hauteurPx), 'etat' => self::A_VERIFIER];
        }

        $cmjn = 4 === ($image['channels'] ?? null);
        $controles[] = [
            'libelle' => 'Mode colorimétrique',
            'valeur' => $cmjn ? 'CMJN' : 'RVB : les couleurs vives peuvent ternir à l\'impression',
            'etat' => $cmjn ? self::OK : self::ATTENTION,
        ];
        $controles[] = ['libelle' => 'Polices', 'valeur' => 'Image : pas de police à incorporer', 'etat' => self::OK];
        if ($largeurCm > 0 && $hauteurCm > 0) {
            $controles[] = self::controleProportions($largeurPx, $hauteurPx, $largeurCm, $hauteurCm);
        }

        return $controles;
    }

    /**
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    private function analyserPdf(string $contenu, ?float $largeurCm, ?float $hauteurCm): array
    {
        $controles = [];
        $format = null;
        if (preg_match('#/(?:TrimBox|MediaBox)\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]#', $contenu, $boite)) {
            $format = [
                abs((float) $boite[3] - (float) $boite[1]) * 2.54 / 72,
                abs((float) $boite[4] - (float) $boite[2]) * 2.54 / 72,
            ];
        }

        if (null !== $format && $largeurCm > 0 && $hauteurCm > 0) {
            // Un PDF peut être livré à l'échelle 1/10 pour les grands formats.
            $echelle = max($format) * 5 < max($largeurCm, $hauteurCm) ? 10 : 1;
            $controles[] = [
                'libelle' => 'Format de page',
                'valeur' => sprintf('%s × %s cm%s', self::cm($format[0] * $echelle), self::cm($format[1] * $echelle), 10 === $echelle ? ' (fichier à l\'échelle 1/10)' : ''),
                'etat' => self::A_VERIFIER,
            ];
            $controles[] = self::controleProportions($format[0], $format[1], $largeurCm, $hauteurCm);
        } elseif (null !== $format) {
            $controles[] = ['libelle' => 'Format de page', 'valeur' => sprintf('%s × %s cm', self::cm($format[0]), self::cm($format[1])), 'etat' => self::A_VERIFIER];
        }

        $cmjn = str_contains($contenu, '/DeviceCMYK');
        $rvb = str_contains($contenu, '/DeviceRGB') || str_contains($contenu, '/sRGB');
        $controles[] = [
            'libelle' => 'Mode colorimétrique',
            'valeur' => match (true) {
                $cmjn && !$rvb => 'CMJN',
                $cmjn => 'CMJN avec des éléments RVB',
                $rvb => 'RVB : les couleurs vives peuvent ternir à l\'impression',
                default => 'Non déclaré dans le fichier',
            },
            'etat' => $cmjn && !$rvb ? self::OK : ($cmjn || $rvb ? self::ATTENTION : self::A_VERIFIER),
        ];

        $polices = preg_match_all('#/Type\s*/Font\b#', $contenu);
        $incorporees = preg_match_all('#/FontFile[23]?\b#', $contenu);
        $controles[] = [
            'libelle' => 'Polices',
            'valeur' => match (true) {
                0 === $polices => 'Vectorisées',
                $incorporees > 0 => 'Incorporées',
                default => 'Non incorporées : demander le fichier vectorisé',
            },
            'etat' => 0 === $polices || $incorporees > 0 ? self::OK : self::ERREUR,
        ];
        $controles[] = ['libelle' => 'Résolution des images', 'valeur' => 'Images du PDF : à vérifier dans le logiciel', 'etat' => self::A_VERIFIER];

        return $controles;
    }

    private static function cm(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 1, ',', ' '), '0'), ',');
    }
}
