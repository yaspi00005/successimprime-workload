<?php

namespace App\Repository;

use App\Entity\ActionCaisse;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionCaisse>
 */
class ActionCaisseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionCaisse::class);
    }

    /**
     * Lignes ouvertes déjà visibles (une promesse n'apparaît qu'au jour dit).
     *
     * @return list<ActionCaisse>
     */
    public function ouvertes(\DateTimeImmutable $maintenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.etat = :etat')->setParameter('etat', ActionCaisse::A_FAIRE)
            ->andWhere('a.creeLe <= :maintenant')->setParameter('maintenant', $maintenant)
            ->orderBy('a.echeance', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Lignes à faire d'une personne, plus celles qui n'ont personne.
     *
     * @return list<ActionCaisse>
     */
    public function aFairePour(User $utilisateur, \DateTimeImmutable $maintenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.etat = :etat')->setParameter('etat', ActionCaisse::A_FAIRE)
            ->andWhere('a.creeLe <= :maintenant')->setParameter('maintenant', $maintenant)
            ->andWhere('a.assigneA = :moi OR a.assigneA IS NULL')->setParameter('moi', $utilisateur)
            ->orderBy('a.echeance', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Lignes faites par une personne, ou toutes, entre deux dates.
     *
     * @return list<ActionCaisse>
     */
    public function faitesEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $par = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.etat = :etat')->setParameter('etat', ActionCaisse::FAITE)
            ->andWhere('a.faiteLe >= :debut AND a.faiteLe < :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy('a.faiteLe', 'DESC');
        if ($par !== null) {
            $qb->andWhere('a.faitePar = :par')->setParameter('par', $par);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Toutes les lignes d'un mois (créées dans le mois, ou faites dans le
     * mois) pour le journal.
     *
     * @return list<ActionCaisse>
     */
    public function duMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->createQueryBuilder('a')
            ->where('(a.creeLe >= :debut AND a.creeLe < :fin) OR (a.faiteLe >= :debut AND a.faiteLe < :fin)')
            ->andWhere('a.creeLe < :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->orderBy('a.creeLe', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Causes ayant une ligne ouverte, et date de la dernière ligne par cause.
     *
     * @param list<string> $causes
     *
     * @return array<string, array{ouverte: bool, derniere: \DateTimeImmutable}>
     */
    public function etatDesCauses(array $causes): array
    {
        if ($causes === []) {
            return [];
        }
        $etat = [];
        foreach ($this->createQueryBuilder('a')->select('a.cause, a.etat, a.creeLe')->where('a.cause IN (:c)')->setParameter('c', $causes)->getQuery()->getArrayResult() as $l) {
            $avant = $etat[$l['cause']] ?? ['ouverte' => false, 'derniere' => $l['creeLe']];
            $etat[$l['cause']] = [
                'ouverte' => $avant['ouverte'] || $l['etat'] === ActionCaisse::A_FAIRE,
                'derniere' => max($avant['derniere'], $l['creeLe']),
            ];
        }

        return $etat;
    }
}
