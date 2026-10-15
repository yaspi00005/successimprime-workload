<?php

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Pages à contenu variable du site vitrine (réalisations, conseils…)
 * à ajouter au plan du site (sitemap.xml). Une source peut aussi donner
 * la date de dernière modification ou les images d'une page déjà dans le
 * plan (la liste des réalisations, par exemple).
 */
#[AutoconfigureTag('app.site.plan')]
interface SourcePlanSite
{
    /**
     * images : chemins des images publiques de la page (« uploads/site/… »).
     *
     * @return iterable<array{route: string, parametres?: array<string, mixed>, modifieLe?: ?\DateTimeInterface, priorite?: string, images?: list<string>}>
     */
    public function pagesDuPlan(): iterable;
}
