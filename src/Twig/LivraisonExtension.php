<?php

namespace App\Twig;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\User;
use App\Security\LivreurSeul;
use App\Service\Livraisons\SuiviLivraisons;
use App\Service\Livraisons\Tournee;
use App\Service\SuiviMetiers\PointsLivreur;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Ponctualité d'une livraison remise (cartes de tournée), bons de
 * livraison d'une commande (bloc « Livraison »), et le livreur seul à qui
 * l'en-tête et le menu ne montrent que sa tournée.
 */
final class LivraisonExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly SuiviLivraisons $suivi,
    ) {
    }

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

    /** La personne connectée ne fait que livrer (LivreurSeul) : ni recherche, ni messagerie, ni « Aller à… ». */
    #[AsTwigFunction('livreur_seul')]
    public function livreurSeul(): bool
    {
        $moi = $this->security->getUser();

        return $moi instanceof User && LivreurSeul::est($moi->getRoles());
    }

    /**
     * Bons de livraison de la commande (sauf annulés), pour son bloc « Livraison ».
     *
     * @return list<BonLivraison>
     */
    #[AsTwigFunction('livraisons_de_la_commande')]
    public function livraisonsDeLaCommande(Commandes $commande): array
    {
        return $this->suivi->deLaCommande($commande);
    }
}
