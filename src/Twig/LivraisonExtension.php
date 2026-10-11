<?php

namespace App\Twig;

use App\Entity\BonLivraison;
use App\Service\Livraisons\Tournee;
use App\Service\SuiviMetiers\PointsLivreur;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/** Ponctualité d'une livraison remise, pour les cartes de tournée. */
final class LivraisonExtension
{
    #[AsTwigFunction('livraison_a_l_heure')]
    public function aLHeure(BonLivraison $b): bool
    {
        return Tournee::aLHeure($b);
    }

    /** Minutes de retard après la fin du créneau. */
    #[AsTwigFunction('livraison_retard')]
    public function retard(BonLivraison $b): ?int
    {
        return PointsLivreur::retard($b);
    }

    /** 45 → « 45 min », 340 → « 5 h 40 ». */
    #[AsTwigFilter('minutes')]
    public function minutes(int|float $minutes): string
    {
        $m = (int) round($minutes);

        return $m < 60 ? $m.' min' : \sprintf('%d h %02d', intdiv($m, 60), $m % 60);
    }
}
