<?php

namespace App\Twig;

use App\Entity\Reclamation;
use App\Entity\User;
use App\Repository\ReclamationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Alimente la cloche de notifications du gabarit de base : les
 * reclamations d'un agent qui viennent d'etre validees et pas encore
 * vues ("passez a la caisse pour le paiement").
 */
class ReclamationNotificationsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ReclamationRepository $reclamationRepository,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mes_reclamations_a_notifier', [$this, 'mesReclamationsANotifier']),
        ];
    }

    /**
     * @return Reclamation[]
     */
    public function mesReclamationsANotifier(): array
    {
        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User) {
            return [];
        }

        return $this->reclamationRepository->findNotificationsNonLuesPourAgent($utilisateur);
    }
}
