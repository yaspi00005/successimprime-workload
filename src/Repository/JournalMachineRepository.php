<?php

namespace App\Repository;

use App\Entity\JournalMachine;
use App\Entity\Machines;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalMachine>
 */
class JournalMachineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalMachine::class);
    }

    /** @return list<JournalMachine> */
    public function duJour(Machines $machine, \DateTimeImmutable $jour): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.machine = :m AND j.le >= :du AND j.le < :au')
            ->setParameter('m', $machine)->setParameter('du', $jour->setTime(0, 0))->setParameter('au', $jour->setTime(0, 0)->modify('+1 day'))
            ->orderBy('j.le', 'ASC')
            ->getQuery()->getResult();
    }
}
