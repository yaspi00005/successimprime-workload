<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Security\LivreurSeul;
use App\Service\Push\WebPush;
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
        private readonly WebPush $push,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mes_notifications', [$this, 'mesNotifications']),
            new TwigFunction('nombre_notifications_generales', [$this, 'nombreNotificationsGenerales']),
            // Clé publique VAPID (vide tant que les clés ne sont pas dans .env.local).
            new TwigFunction('push_cle_publique', fn (): string => $this->push->estConfigure() ? $this->push->getClePublique() : ''),
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

        if (!LivreurSeul::est($utilisateur->getRoles())) {
            return $this->notificationRepository->findNonLuesPourUtilisateur($utilisateur);
        }

        // Le livreur seul : celles qui mènent à une page qu'il peut ouvrir.
        return \array_slice(LivreurSeul::notificationsVisibles($this->notificationRepository->findNonLuesPourUtilisateur($utilisateur, 500)), 0, 10);
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

        if (!LivreurSeul::est($utilisateur->getRoles())) {
            return $this->notificationRepository->countNonLuesPourUtilisateur($utilisateur);
        }

        return \count(LivreurSeul::notificationsVisibles($this->notificationRepository->findNonLuesPourUtilisateur($utilisateur, 500)));
    }
}
