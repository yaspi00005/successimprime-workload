<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée les notifications internes (cloche du gabarit de base) : une
 * ligne Notification par destinataire. N'appelle pas flush() —
 * l'appelant (déjà en train de flusher la commande/le paiement/etc.)
 * s'en charge, pour que la notification fasse partie de la même
 * transaction que l'évènement qui la déclenche.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Notifie tous les utilisateurs actifs ayant au moins un des
     * rôles donnés.
     *
     * @param string[] $roles
     * @param array<string, mixed> $routeParametres
     */
    public function notifierRoles(
        array $roles,
        string $message,
        ?string $route = null,
        array $routeParametres = [],
        ?User $exclure = null,
    ): void {
        foreach ($this->utilisateursAyantUnRole($roles) as $utilisateur) {
            if ($exclure !== null && $utilisateur === $exclure) {
                continue;
            }

            $this->entityManager->persist(
                (new Notification())
                    ->setDestinataire($utilisateur)
                    ->setMessage($message)
                    ->setRoute($route)
                    ->setRouteParametres($routeParametres)
            );
        }
    }

    /**
     * Comme notifierRoles(), pour une alerte qui peut se répéter (panne de
     * synchronisation) : une personne qui a encore une notification non lue
     * de la même sorte (même route, message qui commence par $prefixe) la
     * voit remise à jour (texte et date) au lieu d'en recevoir une de plus ;
     * ses doublons plus anciens sont marqués lus. Les autres reçoivent une
     * nouvelle notification.
     *
     * Une notification remise à jour n'est pas « nouvelle » pour Doctrine :
     * elle ne part pas d'elle-même sur les téléphones (voir EnvoiPush::aEnvoyer).
     *
     * @param string[] $roles
     *
     * @return list<Notification> les notifications remises à jour
     */
    public function notifierRolesSansDoublon(array $roles, string $message, string $route, string $prefixe, \DateTimeImmutable $maintenant): array
    {
        if (!str_starts_with($message, $prefixe)) {
            throw new \InvalidArgumentException('Le message doit commencer par le préfixe de sa sorte.');
        }
        $existantes = [];
        foreach ($this->notificationsNonLuesParSorte($route, $prefixe) as $notification) {
            // Les plus récentes d'abord : la première de chaque personne est gardée.
            $existantes[$notification->getDestinataire()?->getId()][] = $notification;
        }

        $misesAJour = [];
        foreach ($this->utilisateursAyantUnRole($roles) as $utilisateur) {
            $siennes = $existantes[$utilisateur->getId()] ?? [];
            if ($siennes === []) {
                $this->notifierUtilisateur($utilisateur, $message, $route);
                continue;
            }
            $misesAJour[] = array_shift($siennes)->remettreAJour($message, $maintenant);
            foreach ($siennes as $doublon) {
                $doublon->marquerLue();
            }
        }

        return $misesAJour;
    }

    /**
     * Marque lues, pour tout le monde, les notifications non lues d'une
     * sorte (route et préfixe du message) devenues sans objet : la panne
     * de synchronisation est finie. N'appelle pas flush().
     *
     * @return int le nombre de notifications marquées lues
     */
    public function marquerLuesParSorte(string $route, string $prefixe): int
    {
        $n = 0;
        foreach ($this->notificationsNonLuesParSorte($route, $prefixe) as $notification) {
            $notification->marquerLue();
            ++$n;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $routeParametres
     */
    public function notifierUtilisateur(
        User $utilisateur,
        string $message,
        ?string $route = null,
        array $routeParametres = [],
    ): void {
        $this->entityManager->persist(
            (new Notification())
                ->setDestinataire($utilisateur)
                ->setMessage($message)
                ->setRoute($route)
                ->setRouteParametres($routeParametres)
        );
    }

    /** @return Notification[] */
    private function notificationsNonLuesParSorte(string $route, string $prefixe): array
    {
        $depot = $this->entityManager->getRepository(Notification::class);
        \assert($depot instanceof NotificationRepository);

        return $depot->findNonLuesParSorte($route, $prefixe);
    }

    /**
     * @param string[] $roles
     * @return User[]
     */
    private function utilisateursAyantUnRole(array $roles): array
    {
        $tousLesUtilisateurs = $this->userRepository->findBy(['actif' => true]);

        return array_values(array_filter(
            $tousLesUtilisateurs,
            static fn (User $utilisateur): bool => array_intersect($roles, $utilisateur->getRoles()) !== []
        ));
    }
}
