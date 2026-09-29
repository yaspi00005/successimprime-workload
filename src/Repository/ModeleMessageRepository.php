<?php

namespace App\Repository;

use App\Entity\ModeleMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeleMessage>
 */
class ModeleMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeleMessage::class);
    }

    /**
     * @return array<int, ModeleMessage>
     */
    public function findTous(): array
    {
        return $this->createQueryBuilder('modele')
            ->orderBy('modele.actif', 'DESC')
            ->addOrderBy('modele.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, ModeleMessage>
     */
    public function findActifsParCanal(string $canal): array
    {
        return $this->createQueryBuilder('modele')
            ->andWhere('modele.actif = :actif')
            ->andWhere('modele.canal = :canal')
            ->setParameter('actif', true)
            ->setParameter('canal', $canal)
            ->orderBy('modele.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
