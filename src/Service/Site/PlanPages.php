<?php

namespace App\Service\Site;

use App\Entity\PageSite;
use App\Repository\PageSiteRepository;

/**
 * Pages de contenu du site (équipements, guide des fichiers, à propos,
 * engagements, FAQ) dans le plan du site : seulement publiées (sinon leur
 * adresse répond 404), avec leur date de modification et leur image.
 */
final class PlanPages implements SourcePlanSite
{
    public function __construct(
        private readonly PageSiteRepository $pages,
        private readonly ImagesSite $images,
    ) {
    }

    public function pagesDuPlan(): iterable
    {
        foreach (PageSite::PAGES as $slug => $definition) {
            $page = $this->pages->publiee($slug);
            if (null === $page) {
                continue;
            }
            $image = null === $page->getImage() ? null : $this->images->fichier($page->getImage());
            yield ['route' => $definition['route'], 'modifieLe' => $page->getModifieLe(), 'priorite' => '0.6', 'images' => null === $image ? [] : [$image['chemin']]];
        }
    }
}
