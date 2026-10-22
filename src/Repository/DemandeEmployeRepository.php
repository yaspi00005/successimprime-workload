<?php

namespace App\Repository;

use App\Entity\DemandeEmploye;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeEmploye>
 */
class DemandeEmployeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeEmploye::class);
    }

    /**
     * Demandes qui ont reçu une réponse dans la période.
     *
     * @return list<DemandeEmploye>
     */
    public function traiteesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.etat <> :attente AND d.decideLe >= :debut AND d.decideLe < :fin')
            ->setParameter('attente', DemandeEmploye::EN_ATTENTE)->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy('d.decideLe', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Absences et congés acceptés qui touchent la période.
     *
     * @return list<DemandeEmploye>
     */
    public function absencesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.type IN (:types) AND d.etat = :acceptee')
            ->andWhere('d.du < :fin AND COALESCE(d.au, d.du) >= :debut')
            ->setParameter('types', [DemandeEmploye::ABSENCE, DemandeEmploye::CONGE])->setParameter('acceptee', DemandeEmploye::ACCEPTEE)
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy('d.du', 'ASC')
            ->getQuery()->getResult();
    }
}
