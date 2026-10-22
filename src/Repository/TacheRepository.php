<?php

namespace App\Repository;

use App\Entity\Tache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Tache> */
class TacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tache::class);
    }

    /** @return list<Tache> */
    public function entre(\DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('r')->leftJoin('t.responsable', 'r')
            ->where('t.prevueLe >= :du')->andWhere('t.prevueLe <= :au')->andWhere('t.statut != :annulee')
            ->setParameter('du', $du->setTime(0, 0), 'date_immutable')->setParameter('au', $au->setTime(0, 0), 'date_immutable')
            ->setParameter('annulee', Tache::ANNULEE)
            ->orderBy('t.prevueLe', 'ASC')->addOrderBy('t.heure', 'ASC')->addOrderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Tâches pas faites dont la date est passée. @return list<Tache> */
    public function enRetard(\DateTimeImmutable $maintenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.prevueLe < :jour')->andWhere('t.statut IN (:ouverts)')
            ->setParameter('jour', $maintenant->setTime(0, 0), 'date_immutable')
            ->setParameter('ouverts', [Tache::A_FAIRE, Tache::EN_COURS])
            ->orderBy('t.prevueLe', 'ASC')
            ->getQuery()->getResult();
    }
}
