<?php

namespace App\Repository;

use App\Entity\Clients;
use App\Entity\CompteClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompteClient>
 */
class CompteClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteClient::class);
    }

    public function parTelephone(string $telephone): ?CompteClient
    {
        $normalise = CompteClient::normaliserTelephone($telephone);

        return null === $normalise ? null : $this->findOneBy(['telephone' => $normalise]);
    }

    /**
     * La fiche client de ce numéro (téléphone principal), quel que soit
     * son format en base : 76123456, 22376123456 ou +22376123456.
     */
    public function clientDuTelephone(string $telephone): ?Clients
    {
        $normalise = CompteClient::normaliserTelephone($telephone);
        if (null === $normalise) {
            return null;
        }
        $local = substr($normalise, 3);

        return $this->getEntityManager()->getRepository(Clients::class)->createQueryBuilder('c')
            ->where('c.telephone IN (:formes)')
            ->setParameter('formes', [$local, $normalise, '+'.$normalise, '00'.$normalise])
            ->orderBy('c.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
