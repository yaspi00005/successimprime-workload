<?php

namespace App\Repository;

use App\Entity\ParametresPaiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParametresPaiement>
 */
class ParametresPaiementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParametresPaiement::class);
    }

    /*
     * Table à une seule ligne : la crée si elle n'existe pas encore
     * (valeurs par défaut de l'entité), sans jamais la persister ici
     * -- c'est à l'appelant de flush si besoin.
     */
    public function recuperer(): ParametresPaiement
    {
        return $this->findOneBy([]) ?? new ParametresPaiement();
    }
}
