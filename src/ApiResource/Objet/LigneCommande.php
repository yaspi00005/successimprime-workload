<?php

namespace App\ApiResource\Objet;

final class LigneCommande
{
    public function __construct(
        public readonly string $designation,
        public readonly int $quantite,
        public readonly ?float $largeurCm,
        public readonly ?float $hauteurCm,
        /** en_attente, en_cours, termine... */
        public readonly string $statutProduction,
        /** Montant TTC de la ligne en F CFA. */
        public readonly int $totalTtc,
    ) {
    }
}
