<?php

namespace App\Repository;

use App\Entity\DecaissementRecurrent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecaissementRecurrent>
 */
class DecaissementRecurrentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecaissementRecurrent::class);
    }

    /**
     * Charges actives dont l'échéance est atteinte (ou dépassée).
     *
     * @return array<int, DecaissementRecurrent>
     */
    public function findActifsDus(\DateTimeImmutable $reference): array
    {
        return $this->createQueryBuilder('charge')
            ->andWhere('charge.actif = :actif')
            ->andWhere('charge.prochaineDateExecution <= :reference')
            ->setParameter('actif', true)
            ->setParameter('reference', $reference)
            ->orderBy('charge.prochaineDateExecution', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, DecaissementRecurrent>
     */
    public function findToutes(): array
    {
        return $this->createQueryBuilder('charge')
            ->leftJoin('charge.compteSource', 'compte')
            ->addSelect('compte')
            ->orderBy('charge.actif', 'DESC')
            ->addOrderBy('charge.prochaineDateExecution', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
