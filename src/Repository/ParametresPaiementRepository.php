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
    /** Ligne pas encore enregistrée, rendue à chaque appel de la requête. */
    private ?ParametresPaiement $nouvelle = null;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParametresPaiement::class);
    }

    /*
     * Table à une seule ligne : la crée si elle n'existe pas encore
     * (valeurs par défaut de l'entité), sans jamais la persister ici
     * -- c'est à l'appelant de flush si besoin. Tant qu'elle n'est pas
     * enregistrée, c'est toujours la même : deux taux changés ensemble
     * ne font pas deux lignes.
     */
    public function recuperer(): ParametresPaiement
    {
        return $this->findOneBy([]) ?? $this->nouvelle ??= new ParametresPaiement();
    }
}
