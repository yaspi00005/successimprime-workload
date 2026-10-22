<?php

namespace App\Service\Site;

use App\Repository\RealisationRepository;

/**
 * Réalisations publiées dans le plan du site : la page des réalisations
 * prend la date de la plus récente et la liste de leurs photos (Google
 * Images les trouve ainsi avec leur texte alternatif).
 */
final class PlanRealisations implements SourcePlanSite
{
    /** Photos par page au plus (limite de Google : 1 000). */
    public const IMAGES_MAX = 200;

    public function __construct(
        private readonly RealisationRepository $realisations,
        private readonly ImagesSite $images,
    ) {
    }

    public function pagesDuPlan(): iterable
    {
        $derniere = null;
        $photos = [];
        foreach ($this->realisations->publiees() as $realisation) {
            $date = $realisation->getDateRealisation();
            $derniere = null === $derniere ? $date : max($derniere, $date);
            foreach ($realisation->getImages() as $image) {
                if (\count($photos) < self::IMAGES_MAX && null !== $fichier = $this->images->fichier($image)) {
                    $photos[] = $fichier['chemin'];
                }
            }
        }
        if (null !== $derniere) {
            yield ['route' => 'app_site_realisations', 'modifieLe' => $derniere, 'images' => $photos];
        }
    }
}
