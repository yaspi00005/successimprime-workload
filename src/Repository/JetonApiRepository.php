<?php

namespace App\Repository;

use App\Entity\JetonApi;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JetonApi>
 */
class JetonApiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JetonApi::class);
    }

    public function parTexte(string $texte): ?JetonApi
    {
        if (!JetonApi::formeValide($texte)) {
            return null;
        }

        return $this->createQueryBuilder('j')
            ->addSelect('u')->innerJoin('j.utilisateur', 'u')
            ->where('j.empreinte = :e')->setParameter('e', JetonApi::empreinte($texte))
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Jetons pour l'écran des réglages, les actifs d'abord.
     *
     * @return list<JetonApi>
     */
    public function pourLaListe(): array
    {
        return $this->createQueryBuilder('j')
            ->addSelect('u')->innerJoin('j.utilisateur', 'u')
            ->addSelect('CASE WHEN j.revoqueLe IS NULL THEN 0 ELSE 1 END AS HIDDEN revoque')
            ->orderBy('revoque', 'ASC')->addOrderBy('j.creeLe', 'DESC')
            ->setMaxResults(200)
            ->getQuery()->getResult();
    }
}
