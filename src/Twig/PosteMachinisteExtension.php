<?php

namespace App\Twig;

use App\Service\Atelier\TempsEstime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre du poste machiniste (docs/poste-machiniste.md) :
 * {{ 95|temps_estime }} → « 1 h 35 ».
 */
final class PosteMachinisteExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('temps_estime', static fn (int|float|null $minutes): string => TempsEstime::duree($minutes === null ? null : (int) round($minutes)))];
    }
}
