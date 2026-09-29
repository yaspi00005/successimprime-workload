<?php

namespace App\Repository;

use App\Entity\OrdreProduction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrdreProduction>
 */
class OrdreProductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrdreProduction::class);
    }
    /**
 * Retourne les travaux actuellement présents dans l’atelier.
 *
 * @return array<int, OrdreProduction>
 */
/**
 * Retourne les travaux actifs, avec filtrage éventuel
 * par type d’impression de la configuration produit.
 *
 * @return array<int, OrdreProduction>
 */
public function rechercherTravauxAtelier(
    ?int $typeImpressionId = null
): array {
    $qb = $this->createQueryBuilder('ordre')
        ->innerJoin('ordre.commandeDetail', 'detail')
        ->leftJoin(
            'detail.produitConfiguration',
            'configuration'
        )
        ->leftJoin(
            'configuration.typeImpression',
            'typeImpression'
        )
        ->addSelect(
            'detail',
            'configuration',
            'typeImpression'
        )
        ->andWhere('ordre.statut IN (:statuts)')
        ->setParameter('statuts', [
            OrdreProduction::STATUT_A_PRODUIRE,
            OrdreProduction::STATUT_EN_COURS,
            OrdreProduction::STATUT_EN_PAUSE,
        ])
        ->orderBy(
            'CASE
                WHEN ordre.priorite = :prioriteUrgente THEN 0
                WHEN ordre.priorite = :prioriteHaute THEN 1
                WHEN ordre.priorite = :prioriteNormale THEN 2
                ELSE 3
            END',
            'ASC'
        )
        ->addOrderBy('ordre.creeLe', 'ASC')
        ->setParameter('prioriteUrgente', OrdreProduction::PRIORITE_URGENTE)
        ->setParameter('prioriteHaute', OrdreProduction::PRIORITE_HAUTE)
        ->setParameter('prioriteNormale', OrdreProduction::PRIORITE_NORMALE);

    if ($typeImpressionId !== null) {
        $qb
            ->andWhere(
                'typeImpression.id = :typeImpressionId'
            )
            ->setParameter(
                'typeImpressionId',
                $typeImpressionId
            );
    }

    return $qb
        ->getQuery()
        ->getResult();
}
/**
 * Compte les travaux actifs par type d’impression.
 *
 * @return array<int, int>
 */
/**
 * Compte les travaux actifs par type d’impression.
 *
 * @return array<int, int>
 */
public function compterTravauxParTypeImpression(): array
{
    $resultats = $this->createQueryBuilder('ordre')
        ->select(
            'typeImpression.id AS typeId',
            'COUNT(DISTINCT ordre.id) AS nombre'
        )
        ->innerJoin(
            'ordre.commandeDetail',
            'detail'
        )
        ->innerJoin(
            'detail.produitConfiguration',
            'configuration'
        )
        ->innerJoin(
            'configuration.typeImpression',
            'typeImpression'
        )
        ->andWhere('ordre.statut IN (:statuts)')
        ->setParameter('statuts', [
            OrdreProduction::STATUT_A_PRODUIRE,
            OrdreProduction::STATUT_EN_COURS,
            OrdreProduction::STATUT_EN_PAUSE,
        ])
        ->groupBy('typeImpression.id')
        ->getQuery()
        ->getArrayResult();

    $compteurs = [];

    foreach ($resultats as $resultat) {
        $compteurs[(int) $resultat['typeId']] =
            (int) $resultat['nombre'];
    }

    return $compteurs;
}

/**
 * Recherche les ordres de production terminés.
 *
 * @return OrdreProduction[]
 */
public function rechercherOrdresTermines(
    ?string $recherche = null,
    ?int $machineId = null,
    ?int $operateurId = null,
    ?string $dateDebut = null,
    ?string $dateFin = null
): array {
    $qb = $this->createQueryBuilder('op');

    $qb
        ->leftJoin('op.commandeDetail', 'cd')
        ->addSelect('cd')

        ->leftJoin('cd.commande', 'commande')
        ->addSelect('commande')

        ->leftJoin('cd.produit', 'produit')
        ->addSelect('produit')

        ->leftJoin('op.machine', 'machine')
        ->addSelect('machine')

        ->leftJoin('op.terminePar', 'terminePar')
        ->addSelect('terminePar')

        ->andWhere('op.statut = :statut')
        ->setParameter(
            'statut',
            OrdreProduction::STATUT_TERMINE
        );

    /*
     * Recherche texte.
     */
    if (
        $recherche !== null
        && trim($recherche) !== ''
    ) {
        $recherche = trim($recherche);

        $qb
            ->andWhere(
                $qb->expr()->orX(
                    'LOWER(op.numero) LIKE LOWER(:recherche)',
                    'LOWER(cd.designation) LIKE LOWER(:recherche)',
                    'LOWER(produit.nom) LIKE LOWER(:recherche)',
                    'LOWER(machine.nom) LIKE LOWER(:recherche)'
                )
            )
            ->setParameter(
                'recherche',
                '%' . $recherche . '%'
            );
    }

    /*
     * Filtre machine.
     */
    if ($machineId !== null) {
        $qb
            ->andWhere('machine.id = :machineId')
            ->setParameter(
                'machineId',
                $machineId
            );
    }

    /*
     * Filtre opérateur.
     */
    if ($operateurId !== null) {
        $qb
            ->andWhere(
                'terminePar.id = :operateurId'
            )
            ->setParameter(
                'operateurId',
                $operateurId
            );
    }

    /*
     * Date début.
     */
    if ($dateDebut !== null) {
        try {
            $debut = new \DateTimeImmutable(
                $dateDebut . ' 00:00:00'
            );

            $qb
                ->andWhere(
                    'op.termineLe >= :dateDebut'
                )
                ->setParameter(
                    'dateDebut',
                    $debut
                );
        } catch (\Exception) {
            // Filtre ignoré si date invalide.
        }
    }

    /*
     * Date fin.
     */
    if ($dateFin !== null) {
        try {
            $fin = new \DateTimeImmutable(
                $dateFin . ' 23:59:59'
            );

            $qb
                ->andWhere(
                    'op.termineLe <= :dateFin'
                )
                ->setParameter(
                    'dateFin',
                    $fin
                );
        } catch (\Exception) {
            // Filtre ignoré si date invalide.
        }
    }

    return $qb
        ->orderBy(
            'op.termineLe',
            'DESC'
        )
        ->addOrderBy(
            'op.id',
            'DESC'
        )
        ->getQuery()
        ->getResult();
}
    //    /**
    //     * @return OrdreProduction[] Returns an array of OrdreProduction objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('o.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?OrdreProduction
    //    {
    //        return $this->createQueryBuilder('o')
    //            ->andWhere('o.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
