<?php

namespace App\Repository;

use App\Entity\Immobilisation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Immobilisation>
 */
class ImmobilisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Immobilisation::class);
    }

    /**
     * Biens du registre, dans l'ordre des codes ; sans les biens sortis
     * sauf demande, et seulement ceux confiés à une personne si on la donne.
     *
     * @return list<Immobilisation>
     */
    public function registre(?string $categorie = null, bool $avecSortis = false, ?User $confieA = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('m', 'u')
            ->leftJoin('i.machine', 'm')
            ->leftJoin('i.confieA', 'u')
            ->orderBy('i.code', 'ASC');
        if (null !== $categorie) {
            $qb->andWhere('i.categorie = :categorie')->setParameter('categorie', $categorie);
        }
        if (!$avecSortis) {
            $qb->andWhere('i.sortieLe IS NULL');
        }
        if (null !== $confieA) {
            $qb->andWhere('i.confieA = :u')->setParameter('u', $confieA);
        }

        return $qb->getQuery()->getResult();
    }

    /** Prochain code libre : IMM-0001, IMM-0002… (jamais réutilisé). */
    public function prochainCode(): string
    {
        $codes = $this->createQueryBuilder('i')->select('i.code')->getQuery()->getSingleColumnResult();
        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^IMM-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return Immobilisation::codePour($max + 1);
    }
}
