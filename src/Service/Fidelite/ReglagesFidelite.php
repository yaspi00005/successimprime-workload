<?php

namespace App\Service\Fidelite;

/**
 * Valeurs générales du programme (tuile Réglages › Paramètres › Fidélité),
 * avec celles décidées par Yaya le 13/10/2026 par défaut.
 */
final class ReglagesFidelite
{
    public function __construct(
        public readonly bool $actif = true,
        public readonly float $seuilM2 = 5.0,
        public readonly float $pointsParM2 = 100.0,
        public readonly int $seuilMontant = 25000,
        public readonly float $tauxAutre = 2.0,
        public readonly int $valeurPoint = 1,
        public readonly int $multiplicateurJour = 2,
        public readonly int $multiplicateurDelai = 1,
        public readonly int $delaiHeures = 72,
        public readonly int $validiteMois = 12,
        public readonly int $alerteJours = 30,
    ) {
    }

    /** @param array<string, mixed> $v valeurs de la tuile */
    public static function depuis(array $v): self
    {
        return new self(
            (bool) ($v['actif'] ?? true),
            (float) ($v['seuil_m2'] ?? 5),
            (float) ($v['points_m2'] ?? 100),
            (int) ($v['seuil_montant'] ?? 25000),
            (float) ($v['taux_autre'] ?? 2),
            max(1, (int) ($v['valeur_point'] ?? 1)),
            (int) ($v['multiplicateur_jour'] ?? 2),
            (int) ($v['multiplicateur_delai'] ?? 1),
            (int) ($v['delai_heures'] ?? 72),
            max(1, (int) ($v['validite_mois'] ?? 12)),
            (int) ($v['alerte_jours'] ?? 30),
        );
    }
}
