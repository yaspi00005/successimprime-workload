<?php

namespace App\Twig;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Security\LivreurSeul;
use App\Service\NotificationsEquipe\NotificationsEquipe;
use App\Service\NotificationsEquipe\TypesNotification;
use App\Service\Push\WebPush;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Alimente la cloche de notifications du gabarit de base avec les
 * notifications génériques de l'ERP (nouvelle commande/devis,
 * paiement reçu, étape de production, stock bas...).
 */
class NotificationsExtension extends AbstractExtension implements ResetInterface
{
    public function reset(): void
    {
        $this->listes = null;
    }

    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly Security $security,
        private readonly WebPush $push,
        private readonly ?NotificationsEquipe $equipe = null,
    ) {
    }

    /** @var array<string, mixed>|null */
    private ?array $listes = null;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mes_notifications', [$this, 'mesNotifications']),
            new TwigFunction('nombre_notifications_generales', [$this, 'nombreNotificationsGenerales']),
            // Clé publique VAPID (vide tant que les clés ne sont pas dans .env.local).
            // Notifications de l'équipe à niveaux (docs/notifications-equipe.md) : listes rangées de la personne connectée.
            new TwigFunction('notifications_equipe', $this->notificationsEquipe(...)),
            new TwigFunction('titre_notification', $this->titre(...)),
            new TwigFunction('icone_notification', static fn (Notification $n): string => null !== $n->getType() ? TypesNotification::type($n->getType())['icone'] : 'fas fa-bell'),
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

    /**
     * Listes de la personne connectée (une fois par requête) : critiques,
     * urgentes, à faire, info, mentions, reportées, toutes, non_lues.
     *
     * @return array<string, mixed>
     */
    public function notificationsEquipe(): array
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof User || null === $this->equipe) {
            return ['critiques' => [], 'urgentes' => [], 'a_faire' => [], 'info' => [], 'mentions' => [], 'reportees' => [], 'toutes' => [], 'non_lues' => 0];
        }

        return $this->listes ??= $this->equipe->pour($utilisateur, new \DateTimeImmutable());
    }

    /** Titre vu par la personne connectée : le montant seulement si elle a le droit de le voir. */
    public function titre(Notification $n): string
    {
        $utilisateur = $this->security->getUser();

        return $n->titrePour($utilisateur instanceof User ? $utilisateur->getRoles() : []);
    }
}
