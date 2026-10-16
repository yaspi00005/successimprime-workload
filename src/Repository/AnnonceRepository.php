<?php

namespace App\Repository;

use App\Entity\Annonce;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Annonce>
 */
class AnnonceRepository extends ServiceEntityRepository
{
    /** Au plus autant d'annonces à la fois dans la bande. */
    public const VISIBLES_MAX = 5;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Annonce::class);
    }

    /**
     * Annonces à montrer aujourd'hui à cet endroit (Annonce::SITE ou
     * Annonce::ESPACE), dans l'ordre choisi.
     *
     * @return list<Annonce>
     */
    public function visibles(string $cible, \DateTimeImmutable $maintenant): array
    {
        $jour = $maintenant->setTime(0, 0);

        return $this->createQueryBuilder('a')
            ->where('a.publie = true')->andWhere("a.texte <> ''")
            ->andWhere('a.cible IN (:cibles)')->setParameter('cibles', [$cible, Annonce::LES_DEUX])
            ->andWhere('a.debut IS NULL OR a.debut <= :jour')
            ->andWhere('a.fin IS NULL OR a.fin >= :jour')
            ->setParameter('jour', $jour, 'date_immutable')
            ->orderBy('a.ordre', 'ASC')->addOrderBy('a.id', 'ASC')
            ->setMaxResults(self::VISIBLES_MAX)
            ->getQuery()->getResult();
    }

    /** @return list<Annonce> */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['publie' => 'DESC', 'ordre' => 'ASC', 'id' => 'DESC']);
    }
}
