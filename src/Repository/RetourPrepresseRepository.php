<?php

namespace App\Repository;

use App\Entity\OrdreProduction;
use App\Entity\RetourPrepresse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetourPrepresse>
 */
class RetourPrepresseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetourPrepresse::class);
    }

    public function ouvertPour(OrdreProduction $ordre): ?RetourPrepresse
    {
        return $this->findOneBy(['ordre' => $ordre, 'corrigeLe' => null], ['creeLe' => 'DESC']);
    }

    public function dernierPour(OrdreProduction $ordre): ?RetourPrepresse
    {
        return $this->findOneBy(['ordre' => $ordre], ['creeLe' => 'DESC']);
    }

    /** @return list<RetourPrepresse> */
    public function ouverts(): array
    {
        return $this->createQueryBuilder('r')->addSelect('o', 'd', 'c')
            ->join('r.ordre', 'o')->join('o.commandeDetail', 'd')->join('d.commande', 'c')
            ->where('r.corrigeLe IS NULL')->andWhere('o.statut = :s')->setParameter('s', OrdreProduction::STATUT_A_PRODUIRE)
            ->orderBy('r.creeLe', 'ASC')->getQuery()->getResult();
    }
}
