<?php

namespace App\Repository;

use App\Entity\Partenaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Partenaire>
 */
class PartenaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partenaire::class);
    }

    /**
     * Partenaires montrés sur le site : visibles et avec leur accord.
     *
     * @return list<Partenaire>
     */
    public function visibles(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.logo', 'l')->addSelect('l')
            ->where('p.visible = true')->andWhere('p.accordObtenu = true')->andWhere("p.nom <> ''")
            ->orderBy('p.ordre', 'ASC')->addOrderBy('p.nom', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return list<Partenaire>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }
}
