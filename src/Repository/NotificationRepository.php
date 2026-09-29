<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findNonLuesPourUtilisateur(User $utilisateur, int $limite = 10): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.lue = false')
            ->setParameter('destinataire', $utilisateur)
            ->orderBy('n.dateCreation', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function countNonLuesPourUtilisateur(User $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.lue = false')
            ->setParameter('destinataire', $utilisateur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Toutes les notifications (lues et non lues) d'un utilisateur,
     * les plus récentes en premier, pour la page "Toutes les
     * notifications".
     *
     * @return Notification[]
     */
    public function findToutesPourUtilisateur(User $utilisateur, int $limite = 100): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :destinataire')
            ->setParameter('destinataire', $utilisateur)
            ->orderBy('n.dateCreation', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
