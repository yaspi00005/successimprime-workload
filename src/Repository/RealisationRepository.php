<?php

namespace App\Repository;

use App\Entity\Realisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Realisation>
 */
class RealisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Realisation::class);
    }

    /**
     * Réalisations publiées, les plus récentes d'abord, avec leurs images.
     *
     * @return list<Realisation>
     */
    public function publiees(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.images', 'i')->addSelect('i')
            ->where('r.publie = true')
            ->orderBy('r.dateRealisation', 'DESC')->addOrderBy('r.id', 'DESC')->addOrderBy('i.ordre', 'ASC')->addOrderBy('i.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return list<Realisation>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['dateRealisation' => 'DESC', 'id' => 'DESC']);
    }
}
