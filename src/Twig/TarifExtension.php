<?php

namespace App\Twig;

use App\Service\Catalogue\TarifClient;
use Twig\Attribute\AsTwigFunction;

/**
 * Remises des grilles de prix (docs/produits.md) pour les gabarits : la
 * saisie du comptoir les lit pour son aperçu (public/js/paliers-prix.js),
 * la fiche client et les prix spéciaux les montrent.
 */
final class TarifExtension
{
    public function __construct(private readonly TarifClient $tarif)
    {
    }

    /** @return array{B2C: float, PARTENAIRE: float, ASSOCIATION: float, B2B: float} */
    #[AsTwigFunction('remises_grilles')]
    public function remises(): array
    {
        return $this->tarif->remises();
    }
}
