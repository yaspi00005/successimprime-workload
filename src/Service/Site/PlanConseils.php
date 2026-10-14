<?php

namespace App\Service\Site;

use App\Repository\ArticleConseilRepository;

/**
 * Conseils visibles dans le plan du site (sitemap.xml), avec leur date
 * de dernière modification.
 */
final class PlanConseils implements SourcePlanSite
{
    public function __construct(private readonly ArticleConseilRepository $articles)
    {
    }

    public function pagesDuPlan(): iterable
    {
        foreach ($this->articles->visibles(new \DateTimeImmutable()) as $article) {
            yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => $article->getSlug()], 'modifieLe' => $article->getModifieLe()];
        }
    }
}
