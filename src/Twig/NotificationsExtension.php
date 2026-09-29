<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Alimente la cloche de notifications du gabarit de base avec les
 * notifications génériques de l'ERP (nouvelle commande/devis,
 * paiement reçu, étape de production, stock bas...).
 */
class NotificationsExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mes_notifications', [$this, 'mesNotifications']),
            new TwigFunction('nombre_notifications_generales', [$this, 'nombreNotificationsGenerales']),
        ];
    }

    /**
     * @return Notification[]
     */
    public function mesNotifications(): array
    {
        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User) {
            return [];
        }

        return $this->notificationRepository->findNonLuesPourUtilisateur($utilisateur);
    }

    /**
     * Nombre réel de notifications non lues (utilisé pour le badge,
     * indépendamment de la liste affichée dans le menu déroulant qui
     * elle reste limitée aux plus récentes).
     */
    public function nombreNotificationsGenerales(): int
    {
        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User) {
            return 0;
        }

        return $this->notificationRepository->countNonLuesPourUtilisateur($utilisateur);
    }
}
