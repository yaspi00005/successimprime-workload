<?php

namespace App\Twig;

use App\Service\Atelier\TempsEstime;
use App\Service\Production\QuantiteLisible;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtres du poste machiniste (docs/poste-machiniste.md) :
 * {{ 95|temps_estime }} → « 1 h 35 » ; {{ 25.0|nombre_atelier(1) }} → « 25 »,
 * {{ 24.64|nombre_atelier(1) }} → « 24,6 » (impression en cours, PD04).
 */
final class PosteMachinisteExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('temps_estime', static fn (int|float|null $minutes): string => TempsEstime::duree($minutes === null ? null : (int) round($minutes))),
            new TwigFilter('nombre_atelier', static fn (int|float|null $valeur, int $decimales = 1): string => $valeur === null ? '—' : QuantiteLisible::nombre((float) $valeur, $decimales)),
        ];
    }
}
