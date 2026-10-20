<?php

namespace App\Service\Production;

/**
 * Règles du contrôle final (PD06, docs/production-colis.md), pures et
 * testées : nombre d'œillets d'une pièce, écart de taille toléré, passage
 * de la commande à « Prête ».
 */
final class ReglesControleFinal
{
    /** Espacement des œillets quand la finition ne le dit pas. */
    public const ESPACEMENT_OEILLETS_CM = 50;
    /** Écart de taille toléré : 1 % de la cote, et jamais moins de 1 cm. */
    public const TOLERANCE_POURCENT = 1.0;
    public const TOLERANCE_CM = 1.0;

    /**
     * Espacement des œillets lu dans le nom de la finition (« Œillets tous
     * les 50 cm » → 50) ; null si la finition n'est pas des œillets.
     */
    public static function espacementOeillets(?string $finition): ?int
    {
        $finition = (string) $finition;
        if (preg_match('/(œ|oe)illet/iu', $finition) !== 1) {
            return null;
        }
        if (preg_match('/(\d{1,3})\s*cm/iu', $finition, $m) === 1 && (int) $m[1] >= 5) {
            return (int) $m[1];
        }

        return self::ESPACEMENT_OEILLETS_CM;
    }

    /**
     * Œillets d'une pièce posés tout autour, un à chaque coin puis tous les
     * $espacement cm : 300 × 100 cm tous les 50 cm = 16.
     */
    public static function oeilletsParPiece(float $largeurCm, float $hauteurCm, int $espacementCm = self::ESPACEMENT_OEILLETS_CM): int
    {
        if ($largeurCm <= 0 || $hauteurCm <= 0 || $espacementCm <= 0) {
            return 0;
        }
        // Arrondi pour qu'une cote de 300,0000001 cm ne compte pas un œillet de plus.
        $cotes = static fn (float $c): int => (int) ceil(round($c / $espacementCm, 6));

        return 2 * ($cotes($largeurCm) + $cotes($hauteurCm));
    }

    /**
     * La taille mesurée s'écarte trop de la taille commandée : plus de 1 %
     * de la cote, avec au moins 1 cm de tolérance (une bâche de 3 m tolère
     * 3 cm, un kakémono de 85 cm 1 cm).
     */
    public static function tailleHorsTolerance(float $attenduCm, float $mesureCm): bool
    {
        if ($attenduCm <= 0) {
            return false;
        }
        $tolerance = max(self::TOLERANCE_CM, $attenduCm * self::TOLERANCE_POURCENT / 100);

        return abs($mesureCm - $attenduCm) > $tolerance + 1e-9;
    }

    /**
     * Règle de Yaya : la commande passe « Prête » quand son contrôle final
     * est validé ET que tous ses colis en cours sont scannés (au moins un).
     */
    public static function commandePrete(bool $controleValide, int $colisActifs, int $colisScannes): bool
    {
        return $controleValide && $colisActifs > 0 && $colisScannes >= $colisActifs;
    }
}
