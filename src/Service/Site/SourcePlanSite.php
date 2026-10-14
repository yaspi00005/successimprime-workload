<?php

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Pages à contenu variable du site vitrine (réalisations, conseils…)
 * à ajouter au plan du site (sitemap.xml).
 */
#[AutoconfigureTag('app.site.plan')]
interface SourcePlanSite
{
    /**
     * @return iterable<array{route: string, parametres?: array<string, mixed>, modifieLe?: ?\DateTimeInterface}>
     */
    public function pagesDuPlan(): iterable;
}
