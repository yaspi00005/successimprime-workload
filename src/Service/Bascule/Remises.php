<?php

namespace App\Service\Bascule;

/**
 * Calcul d'une ligne de commande ou de devis dans l'ancienne version de la
 * boutique (remise en pourcentage du brut) et dans la nouvelle (remise en F
 * par unité facturable, plafonnée au brut), et passage de l'une à l'autre
 * sans changer le total enregistré (docs/migration-de-base.md,
 * « Conversions de données »).
 *
 * Les formules reprennent exactement CommandesDetails et DevisDetails
 * (calculerFacteur, calculerMontantImpression, calculerMontantRemise) ;
 * RemisesTest le vérifie sur les entités elles-mêmes.
 */
final class Remises
{
    public const IDENTIQUE = 'identique';
    public const ARRONDI = 'arrondi';
    public const DEJA_DIFFERENTE = 'deja_differente';
    public const ECART = 'ecart';

    /** Plus grande valeur de la colonne remise (INT). */
    private const ENTIER_MAX = 2147483647;

    /** Nombre d'unités facturables (m², mètres, exemplaires…), le même dans les deux versions. */
    public static function facteur(string $modeCalcul, int $quantite, ?string $surface, ?string $longueur, int $nombreFaces): float
    {
        return match ($modeCalcul) {
            'forfait' => 1.0,
            'metre' => (float) ($longueur ?? 0) * $quantite,
            'metre_carre' => (float) ($surface ?? 0) * $quantite,
            'face' => (float) (max(1, $nombreFaces) * $quantite),
            default => (float) $quantite, // unite, heure, feuille, exemplaire, point
        };
    }

    /** Total brut : impression (prix × facteur, arrondi au franc) + finitions. */
    public static function brut(string $prixUnitaire, float $facteur, int $finitions): int
    {
        return (int) round((float) $prixUnitaire * max(0, $facteur)) + $finitions;
    }

    /** Total de la ligne dans l'ancienne version : brut − round(brut × p / 100). */
    public static function totalAncien(int $brut, float $pourcentage): int
    {
        return max(0, $brut - (int) round($brut * $pourcentage / 100));
    }

    /** Total de la ligne dans la nouvelle version : brut − min(brut, round(remise × facteur)). */
    public static function totalNouveau(int $brut, int $remiseParUnite, float $facteur): int
    {
        return max(0, $brut - min($brut, max(0, (int) round($remiseParUnite * $facteur))));
    }

    /**
     * Montant de la remise réellement retiré du brut, retrouvé depuis le
     * total enregistré (jamais modifié) :
     * - ligne cohérente (le brut recalculé redonne le total enregistré avec
     *   l'ancien calcul) ou p = 100 : brut − total ;
     * - sinon, la formule de l'ancienne mise à jour du 20/08/2026 :
     *   round(total × p / (100 − p)).
     */
    public static function montant(float $pourcentage, int $totalHt, int $brut): int
    {
        if ($pourcentage >= 100 || self::totalAncien($brut, $pourcentage) === $totalHt) {
            return max(0, $brut - $totalHt);
        }

        return max(0, (int) round($totalHt * $pourcentage / (100 - $pourcentage)));
    }

    /**
     * Remise par unité facturable, en francs entiers, qui redonne au mieux ce
     * montant : arrondie au franc ; arrondie au franc supérieur quand elle
     * couvre tout le brut (ligne offerte à 100 %), pour que le plafond
     * redonne exactement 0. Sans unité facturable (surface vide…) : 0.
     */
    public static function parUnite(int $montant, int $brut, float $facteur): int
    {
        if ($montant <= 0 || $facteur <= 0) {
            return 0;
        }
        if ($montant >= $brut) {
            $remise = (int) min(self::ENTIER_MAX, ceil($brut / $facteur));
            while ($remise < self::ENTIER_MAX && (int) round($remise * $facteur) < $brut) {
                ++$remise;
            }

            return $remise;
        }

        return (int) min(self::ENTIER_MAX, round($montant / $facteur));
    }

    /** Écart admis après conversion : 1 F par unité facturable (au moins 1 F), l'arrondi de la remise par unité. */
    public static function tolerance(float $facteur): float
    {
        return max(1.0, $facteur);
    }

    /**
     * identique, arrondi (à 1 F près par unité), deja_differente (au-delà,
     * mais l'ancien calcul ne redonnait déjà pas le total enregistré) ou
     * ecart (au-delà, sur une ligne cohérente avant : la conversion se trompe).
     */
    public static function verdict(int $totalEnregistre, int $totalRecalcule, float $facteur, bool $coherenteAvant): string
    {
        $ecart = abs($totalRecalcule - $totalEnregistre);

        return match (true) {
            0 === $ecart => self::IDENTIQUE,
            $ecart <= self::tolerance($facteur) => self::ARRONDI,
            !$coherenteAvant => self::DEJA_DIFFERENTE,
            default => self::ECART,
        };
    }
}
