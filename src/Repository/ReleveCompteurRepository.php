<?php

namespace App\Repository;

use App\Entity\Machines;
use App\Entity\ReleveCompteur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReleveCompteur>
 */
class ReleveCompteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReleveCompteur::class);
    }

    /**
     * Relevés d'une machine, du plus ancien au plus récent, par mois « AAAA-MM ».
     *
     * @return array<string, ReleveCompteur>
     */
    public function deMachine(Machines $machine): array
    {
        $parMois = [];
        foreach ($this->findBy(['machine' => $machine], ['mois' => 'ASC']) as $r) {
            $parMois[$r->getMois()->format('Y-m')] = $r;
        }

        return $parMois;
    }

    /**
     * Relevés de plusieurs machines, par id de machine puis par mois.
     *
     * @param list<Machines> $machines
     *
     * @return array<int, array<string, ReleveCompteur>>
     */
    public function deMachines(array $machines): array
    {
        if ([] === $machines) {
            return [];
        }
        $parMachine = [];
        foreach ($this->findBy(['machine' => $machines], ['mois' => 'ASC']) as $r) {
            $parMachine[(int) $r->getMachine()->getId()][$r->getMois()->format('Y-m')] = $r;
        }

        return $parMachine;
    }
}
