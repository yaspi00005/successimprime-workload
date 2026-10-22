<?php

namespace App\ApiResource\Objet;

/** Une façon de vendre le produit (support, format), avec son prix public. */
final class ConfigurationProduit
{
    public function __construct(
        public readonly int $id,
        public readonly string $libelle,
        /** unite, metre, metre_carre... */
        public readonly string $modeCalcul,
        /** Prix public en F CFA (au m² pour le calcul au m²). */
        public readonly int $prix,
        /** Prix des clients entreprise (B2B), en F CFA. */
        public readonly int $prixB2B,
        public readonly int $quantiteMinimale,
    ) {
    }
}
