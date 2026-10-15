<?php

namespace App\Repository;

use App\Entity\Clients;
use App\Entity\SuspensionEspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuspensionEspace>
 */
class SuspensionEspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuspensionEspace::class);
    }

    /**
     * Suspensions qui tiennent sur cette fiche (tout le compte et
     * personnes), la plus récente d'abord.
     *
     * @return list<SuspensionEspace>
     */
    public function actives(Clients $client): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.client = :client')->andWhere('s.leveeLe IS NULL')
            ->setParameter('client', $client)
            ->orderBy('s.suspenduLe', 'DESC')->addOrderBy('s.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Suspension qui tient pour ce numéro sur cette fiche (la sienne, ou
     * celle de tout le compte), ou null.
     */
    public function pour(Clients $client, string $telephone): ?SuspensionEspace
    {
        return $this->createQueryBuilder('s')
            ->where('s.client = :client')->andWhere('s.leveeLe IS NULL')
            ->andWhere('s.telephone IS NULL OR s.telephone = :telephone')
            ->setParameter('client', $client)->setParameter('telephone', $telephone)
            // Tout le compte d'abord : c'est elle qu'il faudra lever.
            ->orderBy('s.telephone', 'ASC')->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Les dernières suspensions de la fiche, levées comprises (historique).
     *
     * @return list<SuspensionEspace>
     */
    public function historique(Clients $client, int $nombre = 10): array
    {
        return $this->findBy(['client' => $client], ['suspenduLe' => 'DESC', 'id' => 'DESC'], $nombre);
    }
}
