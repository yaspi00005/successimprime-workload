<?php

namespace App\Repository;

use App\Entity\MembreEquipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembreEquipe>
 */
class MembreEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembreEquipe::class);
    }

    /**
     * Personnes montrées sur le site : visibles et avec leur accord.
     *
     * @return list<MembreEquipe>
     */
    public function visibles(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.photo', 'p')->addSelect('p')
            ->where('m.visible = true')->andWhere('m.accordPersonne = true')->andWhere("m.nom <> ''")
            ->orderBy('m.ordre', 'ASC')->addOrderBy('m.nom', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return list<MembreEquipe>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }
}
