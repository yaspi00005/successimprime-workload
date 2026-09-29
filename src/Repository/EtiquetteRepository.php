<?php

namespace App\Repository;

use App\Entity\Etiquette;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etiquette>
 */
class EtiquetteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etiquette::class);
    }

    public function trouverProchaineSequence(
    string $prefixe = 'ETQ'
): int {
    $connexion = $this->getEntityManager()->getConnection();

    $sql = <<<'SQL'
        SELECT MAX(
            CAST(
                SUBSTRING_INDEX(numero, '-', -1)
                AS UNSIGNED
            )
        ) AS derniere_sequence
        FROM etiquette
        WHERE numero LIKE :prefixe
    SQL;

    $derniereSequence = $connexion->fetchOne(
        $sql,
        [
            'prefixe' => strtoupper($prefixe) . '-%',
        ]
    );

    return ((int) $derniereSequence) + 1;
}

    //    /**
    //     * @return Etiquette[] Returns an array of Etiquette objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Etiquette
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
