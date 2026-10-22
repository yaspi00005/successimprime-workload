<?php

namespace App\Repository;

use App\Entity\Conception;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conception>
 */
class ConceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conception::class);
    }

    /**
     * Travaux du tableau : tout ce qui n'est pas validé, plus ce qui l'a été
     * depuis $valideDepuis.
     *
     * @return list<Conception>
     */
    public function pourTableau(\DateTimeImmutable $valideDepuis): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('ctl', 'd', 'cmd', 'cli')
            ->join('c.controle', 'ctl')
            ->join('ctl.commandeDetail', 'd')
            ->join('d.commande', 'cmd')
            ->leftJoin('cmd.clients', 'cli')
            ->where('ctl.statut NOT IN (:finis) AND c.valideLe IS NULL')
            ->orWhere('c.valideLe >= :depuis')
            ->orWhere('ctl.statut IN (:finis) AND c.valideLe IS NULL AND ctl.valideLe >= :depuis')
            ->setParameter('finis', ['valide', 'envoye_production'])
            ->setParameter('depuis', $valideDepuis)
            ->orderBy('c.echeanceBat', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Travaux d'un graphiste dont le premier BAT ou la validation tombe dans la période.
     *
     * @return list<Conception>
     */
    public function duGraphisteEntre(User $graphiste, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.graphiste = :g')
            ->andWhere('(c.premierBatLe >= :du AND c.premierBatLe < :au) OR (c.valideLe >= :du AND c.valideLe < :au)')
            ->setParameter('g', $graphiste)->setParameter('du', $du)->setParameter('au', $au)
            ->getQuery()->getResult();
    }
}
