<?php

namespace App\Repository;

use App\Entity\AvisClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvisClient>
 */
class AvisClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvisClient::class);
    }

    /**
     * Avis montrés sur le site : publiés et avec l'accord du client.
     *
     * @return list<AvisClient>
     */
    public function visibles(int $max = 6): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.publie = true')->andWhere('a.accordClient = true')->andWhere("a.texte <> ''")
            ->orderBy('a.dateAvis', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($max)
            ->getQuery()->getResult();
    }

    /**
     * @return list<AvisClient>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['dateAvis' => 'DESC', 'id' => 'DESC']);
    }
}
