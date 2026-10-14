<?php

namespace App\Repository;

use App\Entity\EvaluationPrime;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationPrime>
 */
class EvaluationPrimeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationPrime::class);
    }

    /**
     * Versions d'un agent pour un mois, de la plus récente à la plus ancienne.
     *
     * @return list<EvaluationPrime>
     */
    public function versions(User $agent, \DateTimeImmutable $mois): array
    {
        return $this->findBy(['agent' => $agent, 'mois' => $mois->modify('first day of this month')->setTime(0, 0)], ['version' => 'DESC']);
    }

    /**
     * Dernière version de chaque agent pour un mois, par id d'agent.
     *
     * @return array<int, EvaluationPrime>
     */
    public function dernieresDuMois(\DateTimeImmutable $mois): array
    {
        $dernieres = [];
        foreach ($this->findBy(['mois' => $mois->modify('first day of this month')->setTime(0, 0)], ['version' => 'ASC']) as $e) {
            $dernieres[(int) $e->getAgent()->getId()] = $e;
        }

        return $dernieres;
    }

    /**
     * Évaluations validées d'un agent (version en vigueur de chaque mois),
     * des plus récentes aux plus anciennes.
     *
     * @return list<EvaluationPrime>
     */
    public function valideesDe(User $agent, int $limite = 12): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.agent = :a')->andWhere('e.statut = :v')
            ->setParameter('a', $agent)->setParameter('v', EvaluationPrime::VALIDEE)
            ->orderBy('e.mois', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * Évaluations validées dont la prime est validée, pas encore toute
     * reportée sur un bulletin (voir Primes::reporterEnPaie).
     *
     * @return list<EvaluationPrime>
     */
    public function primesAReporter(?User $agent = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.statut = :v')->andWhere('e.primeValideeLe IS NOT NULL')
            ->setParameter('v', EvaluationPrime::VALIDEE)
            ->orderBy('e.mois', 'ASC');
        if (null !== $agent) {
            $qb->andWhere('e.agent = :a')->setParameter('a', $agent);
        }

        return $qb->getQuery()->getResult();
    }
}
