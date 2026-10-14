<?php

namespace App\Service\Immobilisations;

/**
 * Calculs d'amortissement du registre (M03, M04), sans base de données.
 *
 * - À l'usage (machines) : coût par unité = (achat − valeur résiduelle)
 *   ÷ capacité prévue ; cumul = usage cumulé × coût par unité.
 * - Linéaire (autres biens) : (achat − résiduelle) ÷ durée en mois, le
 *   mois de mise en service compte en entier.
 *
 * Le cumul est plafonné au montant à amortir : la valeur nette ne descend
 * jamais sous la valeur résiduelle, et la dotation s'arrête là. La
 * dotation d'un mois est l'écart entre le cumul de fin de mois et celui
 * du mois précédent.
 */
final class CalculAmortissement
{
    public const USAGE = 'usage';
    public const LINEAIRE = 'lineaire';

    public static function premierDuMois(\DateTimeInterface $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($date)->modify('first day of this month')->setTime(0, 0);
    }

    /** Coût par unité d'usage, ou null sans capacité prévue. */
    public static function coutUnitaire(int $base, ?float $capacite): ?float
    {
        return null !== $capacite && $capacite > 0 ? max(0, $base) / $capacite : null;
    }

    /**
     * Mois amortis à la fin de $mois : le mois de mise en service compte,
     * 0 avant.
     */
    public static function moisAmortis(?\DateTimeInterface $miseEnService, \DateTimeInterface $mois): int
    {
        if (null === $miseEnService) {
            return 0;
        }
        $rang = static fn (\DateTimeInterface $d): int => (int) $d->format('Y') * 12 + (int) $d->format('n');

        return max(0, $rang($mois) - $rang($miseEnService) + 1);
    }

    /** Cumul linéaire à la fin de $mois, plafonné au montant à amortir. */
    public static function cumulLineaire(int $base, int $dureeMois, ?\DateTimeInterface $miseEnService, \DateTimeInterface $mois): int
    {
        if ($dureeMois <= 0 || $base <= 0) {
            return 0;
        }
        $mois = min($dureeMois, self::moisAmortis($miseEnService, $mois));

        return (int) round($base * $mois / $dureeMois);
    }

    /** Cumul à l'usage : usage cumulé × coût par unité, plafonné au montant à amortir. */
    public static function cumulUsage(float $usage, ?float $coutUnitaire, int $base): int
    {
        if (null === $coutUnitaire || $base <= 0) {
            return 0;
        }

        return min($base, (int) round(max(0.0, $usage) * $coutUnitaire));
    }

    /** Dotation du mois : ce qui s'ajoute au cumul, jamais négative. */
    public static function dotation(int $cumulFin, int $cumulDebut): int
    {
        return max(0, $cumulFin - $cumulDebut);
    }

    public static function valeurNette(int $valeurAchat, int $cumul): int
    {
        return max(0, $valeurAchat - $cumul);
    }

    /** Plus-value (positive) ou moins-value (négative) d'un bien sorti. */
    public static function resultatCession(int $prix, int $valeurNette): int
    {
        return $prix - $valeurNette;
    }

    /** Méthode en clair : « Unités (m²) », « Linéaire 4 ans ». */
    public static function libelleMethode(string $methode, ?string $unite, int $dureeMois): string
    {
        if (self::USAGE === $methode) {
            return 'Unités ('.($unite ?? 'usage').')';
        }

        return 0 === $dureeMois % 12 ? 'Linéaire '.($dureeMois / 12).' an'.($dureeMois > 12 ? 's' : '') : 'Linéaire '.$dureeMois.' mois';
    }
}
