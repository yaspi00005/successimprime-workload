<?php

namespace App\Service\Site;

/**
 * Vraies photos du local (17/10/2026) dans le plan du site : la façade
 * sur l'accueil, les trois photos sur la page Contact. Google Images les
 * trouve ainsi avec leur page. Seulement les fichiers présents, dans leur
 * plus grande largeur. Pas « Qui sommes-nous ? » : cette page n'entre au
 * plan que publiée (PlanPages), une source ne doit pas l'y ajouter.
 */
final class PlanPhotosLocal implements SourcePlanSite
{
    public function __construct(private readonly PhotosSite $photos)
    {
    }

    public function pagesDuPlan(): iterable
    {
        $grande = static fn (array $photo): string => end($photo['fichiers'])['chemin'];
        $facade = $this->photos->photo(PhotosSite::FACADE);
        if (null !== $facade) {
            yield ['route' => 'app_home', 'images' => [$grande($facade)]];
        }
        $local = $this->photos->local();
        if ([] !== $local) {
            yield ['route' => 'app_site_contact', 'images' => array_map($grande, $local)];
        }
    }
}
