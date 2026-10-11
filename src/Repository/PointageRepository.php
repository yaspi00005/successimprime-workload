<?php

namespace App\Repository;

use App\Entity\Pointage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pointage>
 */
class PointageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pointage::class);
    }

    public function dernier(User $utilisateur): ?Pointage
    {
        return $this->findOneBy(['utilisateur' => $utilisateur], ['arriveeLe' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Présences dont l'arrivée tombe dans [début, fin[, par ordre d'arrivée.
     *
     * @return list<Pointage>
     */
    public function entre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $utilisateur = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.arriveeLe >= :debut')->andWhere('p.arriveeLe < :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy('p.arriveeLe', 'ASC');
        if ($utilisateur !== null) {
            $qb->andWhere('p.utilisateur = :u')->setParameter('u', $utilisateur);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<Pointage>
     */
    public function avecPhotosAvant(\DateTimeImmutable $limite): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.arriveeLe < :limite')->setParameter('limite', $limite)
            ->andWhere('p.photoArrivee IS NOT NULL OR p.photoDepart IS NOT NULL')
            ->getQuery()->getResult();
    }
}
