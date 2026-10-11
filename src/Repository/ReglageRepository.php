<?php

namespace App\Repository;

use App\Entity\Reglage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reglage>
 */
class ReglageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reglage::class);
    }

    /**
     * @return array<string, Reglage> indexé par clé de tuile
     */
    public function parCle(): array
    {
        $reglages = [];

        foreach ($this->findAll() as $reglage) {
            $reglages[$reglage->getCle()] = $reglage;
        }

        return $reglages;
    }
}
