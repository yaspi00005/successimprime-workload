<?php

namespace App\Repository;

use App\Entity\Objectif;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Objectif>
 */
class ObjectifRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Objectif::class);
    }

    /**
     * Objectifs enregistrés d'un mois, par clé « indicateur:axe:cible ».
     *
     * @return array<string, Objectif>
     */
    public function duMois(\DateTimeImmutable $mois): array
    {
        $parCle = [];
        foreach ($this->findBy(['mois' => $mois->modify('first day of this month')->setTime(0, 0)]) as $objectif) {
            $parCle[$objectif->getCle()] = $objectif;
        }

        return $parCle;
    }

    /**
     * Objectifs enregistrés de plusieurs mois (graphique sur 12 mois,
     * prorata d'une semaine à cheval sur deux mois), par mois « AAAA-MM ».
     *
     * @return array<string, array<string, Objectif>>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, string $indicateur): array
    {
        /** @var list<Objectif> $objectifs */
        $objectifs = $this->createQueryBuilder('o')
            ->andWhere('o.mois >= :debut AND o.mois < :fin')
            ->andWhere('o.indicateur = :indicateur')
            ->setParameter('debut', $debut->modify('first day of this month')->setTime(0, 0))
            ->setParameter('fin', $fin)
            ->setParameter('indicateur', $indicateur)
            ->getQuery()
            ->getResult();

        $parMois = [];
        foreach ($objectifs as $o) {
            $parMois[$o->getMois()->format('Y-m')][$o->getCle()] = $o;
        }

        return $parMois;
    }
}
