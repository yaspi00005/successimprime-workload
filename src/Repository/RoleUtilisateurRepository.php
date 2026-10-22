<?php

namespace App\Repository;

use App\Entity\RoleUtilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoleUtilisateur>
 */
class RoleUtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoleUtilisateur::class);
    }

    /**
     * @return list<RoleUtilisateur>
     */
    public function dansLOrdre(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.ordre', 'ASC')
            ->addOrderBy('r.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de personnes actives par role (id du role => nombre).
     *
     * @return array<int, int>
     */
    public function nombresDePersonnes(): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(u.profil) AS role, COUNT(u.id) AS nombre')
            ->from(\App\Entity\User::class, 'u')
            ->where('u.actif = true')
            ->andWhere('u.profil IS NOT NULL')
            ->groupBy('u.profil')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $l): array => [(int) $l['role'], (int) $l['nombre']], $lignes), 1, 0);
    }
}
