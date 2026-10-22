<?php

namespace App\Twig;

use App\Service\Atelier\AvancementOrdre;
use Twig\Attribute\AsTwigFilter;

/** Libellés de l'atelier de production (docs/atelier-production.md). */
final class AtelierProductionExtension
{
    /** « 3 h 20 », « 45 min », « 2 jours ». */
    #[AsTwigFilter('atelier_duree')]
    public function duree(int $minutes): string
    {
        return AvancementOrdre::duree($minutes);
    }
}
