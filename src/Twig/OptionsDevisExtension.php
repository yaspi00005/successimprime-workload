<?php

namespace App\Twig;

use App\Entity\DevisDetails;
use App\Service\Devis\OptionsDevis;
use Twig\Attribute\AsTwigFunction;

/** Quantités au choix d'une ligne de devis (maquette DB02), pour la fiche du devis. */
final class OptionsDevisExtension
{
    /** @return list<array{quantite: int, prixUnitaire: string, ht: int, ttc: int, actuelle: bool}> */
    #[AsTwigFunction('devis_options_ligne')]
    public function optionsLigne(DevisDetails $ligne): array
    {
        return OptionsDevis::options($ligne);
    }
}
