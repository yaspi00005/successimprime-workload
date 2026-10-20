<?php

namespace App\Repository;

use App\Entity\Commandes;
use App\Entity\ControleFinal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ControleFinal>
 */
class ControleFinalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ControleFinal::class);
    }

    public function deLaCommande(Commandes $commande): ?ControleFinal
    {
        return $this->findOneBy(['commande' => $commande]);
    }
}
