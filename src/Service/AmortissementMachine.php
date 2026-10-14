<?php

namespace App\Service;

use App\Entity\Machines;

/**
 * Méthode d'amortissement des machines (tuile Machines,
 * docs/immobilisations-primes.md) : à l'usage (m², mètres de film, pages
 * A4, cartes ou transferts × coût par unité) ou linéaire (prix d'achat
 * étalé sur la durée en mois). Une machine à l'usage sans capacité prévue
 * reste en linéaire.
 */
final class AmortissementMachine
{
    public const USAGE = 'usage';
    public const LINEAIRE = 'lineaire';

    /** La machine est-elle amortie à l'usage avec la méthode choisie ? */
    public static function aLUsage(Machines $machine, string $methode): bool
    {
        return $methode === self::USAGE && $machine->coutUsageUnitaire() !== null;
    }

    /**
     * Amortissement déjà consommé, plafonné au montant à amortir.
     *
     * @return array{charge: float, methode: string}
     */
    public static function cumul(Machines $machine, string $methode, \DateTimeInterface $maintenant): array
    {
        if (self::aLUsage($machine, $methode)) {
            return ['charge' => min($machine->usage() * $machine->coutUsageUnitaire(), (float) $machine->baseAmortissable()), 'methode' => self::USAGE];
        }

        $mensuelle = $machine->getChargeAmortissementMensuelle();
        $depart = $machine->getDateMiseService() ?? $machine->getDateAchat();
        if ($mensuelle === null || $depart === null || $depart > $maintenant) {
            return ['charge' => 0.0, 'methode' => self::LINEAIRE];
        }
        $ecart = $depart->diff($maintenant);
        $mois = max(0, min($ecart->y * 12 + $ecart->m, (int) $machine->getDureeAmortissementMois()));

        return ['charge' => $mensuelle * $mois, 'methode' => self::LINEAIRE];
    }

    /**
     * Amortissement d'un travail à l'usage (F), ou null hors méthode à
     * l'usage. La quantité sert aux machines comptées à la pièce (cartes,
     * transferts).
     */
    public static function pourTravail(Machines $machine, string $methode, float $surfaceM2, float $quantite = 0): ?int
    {
        return self::aLUsage($machine, $methode) ? (int) round($machine->usagePour($surfaceM2, $quantite) * $machine->coutUsageUnitaire()) : null;
    }
}
