<?php

namespace App\Repository;

use App\Entity\Corbeille;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Entrées de la corbeille (docs/corbeille.md). Les lignes sont écrites par
 * App\Service\Corbeille\CaptureCorbeille ; les recherches de l'écran sont
 * dans App\Service\Corbeille\Corbeille.
 *
 * @extends ServiceEntityRepository<Corbeille>
 */
final class CorbeilleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Corbeille::class);
    }
}
