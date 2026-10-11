<?php

namespace App\Repository;

use App\Entity\BulletinPaie;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BulletinPaie> */
class BulletinPaieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulletinPaie::class);
    }

    /** @return list<BulletinPaie> */
    public function duMois(\DateTimeImmutable $mois): array
    {
        return $this->createQueryBuilder('b')->addSelect('u')->join('b.utilisateur', 'u')
            ->where('b.mois = :m')->setParameter('m', BulletinPaie::premierDuMois($mois))
            ->orderBy('u.username', 'ASC')->getQuery()->getResult();
    }

    /** Bulletins que l'employé voit : validés ou payés. @return list<BulletinPaie> */
    public function visiblesPour(User $utilisateur, int $limite = 12): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.utilisateur = :u')->andWhere('b.etat != :brouillon')
            ->setParameter('u', $utilisateur)->setParameter('brouillon', BulletinPaie::BROUILLON)
            ->orderBy('b.mois', 'DESC')->setMaxResults($limite)->getQuery()->getResult();
    }
}
