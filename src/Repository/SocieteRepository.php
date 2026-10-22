<?php

namespace App\Repository;

use App\Entity\Societe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Societe>
 */
class SocieteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Societe::class);
    }

    /**
     * La société cochée « par défaut », sinon la première active, sinon
     * la première tout court.
     */
    public function parDefaut(): ?Societe
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.parDefaut', 'DESC')
            ->addOrderBy('s.actif', 'DESC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Choix « Société » de Réglages › Entreprises : les sociétés actives,
     * plus celle déjà choisie si elle est désactivée.
     *
     * @return list<Societe>
     */
    public function choix(?Societe $actuelle = null): array
    {
        return array_values(array_filter(
            $this->findBy([], ['parDefaut' => 'DESC', 'raisonSociale' => 'ASC']),
            static fn (Societe $s): bool => $s->isActif() || $s === $actuelle,
        ));
    }
}
