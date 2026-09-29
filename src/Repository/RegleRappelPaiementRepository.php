<?php

namespace App\Repository;

use App\Entity\RegleRappelPaiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegleRappelPaiement>
 */
class RegleRappelPaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegleRappelPaiement::class);
    }

    /**
     * @return array<int, RegleRappelPaiement>
     */
    public function findActives(): array
    {
        return $this->createQueryBuilder('regle')
            ->andWhere('regle.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('regle.delaiJours', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, RegleRappelPaiement>
     */
    public function findToutes(): array
    {
        return $this->createQueryBuilder('regle')
            ->leftJoin('regle.modeleMessage', 'modele')
            ->addSelect('modele')
            ->orderBy('regle.actif', 'DESC')
            ->addOrderBy('regle.delaiJours', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
