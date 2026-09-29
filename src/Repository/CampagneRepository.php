<?php

namespace App\Repository;

use App\Entity\Campagne;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Campagne>
 */
class CampagneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campagne::class);
    }

    /**
     * @return array<int, Campagne>
     */
    public function findToutes(): array
    {
        return $this->createQueryBuilder('campagne')
            ->leftJoin('campagne.modeleMessage', 'modele')
            ->addSelect('modele')
            ->orderBy('campagne.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, Campagne>
     */
    public function findEnCours(): array
    {
        return $this->createQueryBuilder('campagne')
            ->andWhere('campagne.statut = :statut')
            ->setParameter('statut', Campagne::STATUT_EN_COURS)
            ->getQuery()
            ->getResult();
    }
}
