<?php

namespace App\Repository;

use App\Entity\Reclamation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reclamation>
 */
class ReclamationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reclamation::class);
    }

    public function referenceExiste(string $reference): bool
    {
        return (bool) $this->createQueryBuilder('r')
            ->select('1')
            ->andWhere('r.reference = :reference')
            ->setParameter('reference', $reference)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Reclamation[]
     */
    public function findPourAgent(User $agent): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.agent = :agent')
            ->setParameter('agent', $agent)
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Reclamation[]
     */
    public function findToutes(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Reclamations validees de cet agent dont la notification "passez
     * a la caisse" n'a pas encore ete vue.
     *
     * @return Reclamation[]
     */
    public function findNotificationsNonLuesPourAgent(User $agent): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.agent = :agent')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.notificationLue = false')
            ->setParameter('agent', $agent)
            ->setParameter('statut', Reclamation::STATUT_VALIDEE)
            ->orderBy('r.dateValidation', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
