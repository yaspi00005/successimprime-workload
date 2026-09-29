<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
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
