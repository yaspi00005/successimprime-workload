<?php

namespace App\Service\Site;

use App\Repository\ArticleConseilRepository;

/**
 * Conseils visibles dans le plan du site (sitemap.xml), avec leur date
 * de dernière modification et leur image ; la liste des conseils prend la
 * date du plus récent.
 */
final class PlanConseils implements SourcePlanSite
{
    public function __construct(
        private readonly ArticleConseilRepository $articles,
        private readonly ImagesSite $images,
    ) {
    }

    public function pagesDuPlan(): iterable
    {
        $derniere = null;
        foreach ($this->articles->visibles(new \DateTimeImmutable()) as $article) {
            $modifieLe = max($article->getPublieLe(), $article->getModifieLe());
            $derniere = null === $derniere ? $modifieLe : max($derniere, $modifieLe);
            $image = null === $article->getImage() ? null : $this->images->fichier($article->getImage());
            yield ['route' => 'app_site_conseil', 'parametres' => ['slug' => $article->getSlug()], 'modifieLe' => $modifieLe, 'images' => null === $image ? [] : [$image['chemin']]];
        }
        if (null !== $derniere) {
            yield ['route' => 'app_site_conseils', 'modifieLe' => $derniere];
        }
    }
}
