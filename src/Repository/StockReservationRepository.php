<?php

namespace App\Repository;

use App\Entity\Articles;
use App\Entity\CommandesDetails;
use App\Entity\StockReservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StockReservationRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry
    ) {
        parent::__construct(
            $registry,
            StockReservation::class
        );
    }


    public function sommeReserveePourArticle(
        Articles $article
    ): float {
        $resultat = $this
            ->createQueryBuilder('r')
            ->select(
                'COALESCE(SUM(r.quantite), 0)'
            )
            ->andWhere(
                'r.article = :article'
            )
            ->andWhere(
                'r.statut = :statut'
            )
            ->setParameter(
                'article',
                $article
            )
            ->setParameter(
                'statut',
                StockReservation::STATUT_ACTIVE
            )
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $resultat;
    }


    public function trouverActivesPourDetail(
        CommandesDetails $detail
    ): array {
        return $this->findBy(
            [
                'commandeDetail' =>
                    $detail,

                'statut' =>
                    StockReservation::STATUT_ACTIVE,
            ],
            [
                'id' => 'ASC',
            ]
        );
    }


    public function sommeReserveePourDetailEtArticle(
        CommandesDetails $detail,
        Articles $article
    ): float {
        $resultat = $this
            ->createQueryBuilder('r')
            ->select(
                'COALESCE(SUM(r.quantite), 0)'
            )
            ->andWhere(
                'r.commandeDetail = :detail'
            )
            ->andWhere(
                'r.article = :article'
            )
            ->andWhere(
                'r.statut = :statut'
            )
            ->setParameter(
                'detail',
                $detail
            )
            ->setParameter(
                'article',
                $article
            )
            ->setParameter(
                'statut',
                StockReservation::STATUT_ACTIVE
            )
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $resultat;
    }
}