<?php

namespace App\Repository;

use App\Entity\JournalActivite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JournalActivite>
 */
final class JournalActiviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JournalActivite::class);
    }

    /**
     * Recherche paginée avec filtres (entité, action, entiteId, période).
     *
     * @return array{resultats: JournalActivite[], total: int}
     */
    public function rechercher(array $filtres, int $page = 1, int $parPage = 50): array
    {
        $qb = $this->createQueryBuilder('j')
            ->leftJoin('j.utilisateur', 'u')
            ->addSelect('u')
            ->orderBy('j.id', 'DESC');

        if (!empty($filtres['entite'])) {
            $qb
                ->andWhere('j.entite = :entite')
                ->setParameter('entite', $filtres['entite']);
        }

        if (!empty($filtres['action'])) {
            $qb
                ->andWhere('j.action = :action')
                ->setParameter('action', $filtres['action']);
        }

        if (!empty($filtres['entite_id'])) {
            $qb
                ->andWhere('j.entiteId = :entiteId')
                ->setParameter('entiteId', (int) $filtres['entite_id']);
        }

        if (!empty($filtres['date_debut'])) {
            try {
                $qb
                    ->andWhere('j.createdAt >= :dateDebut')
                    ->setParameter(
                        'dateDebut',
                        new \DateTimeImmutable($filtres['date_debut'] . ' 00:00:00')
                    );
            } catch (\Exception) {
                // Date invalide ignorée.
            }
        }

        if (!empty($filtres['date_fin'])) {
            try {
                $qb
                    ->andWhere('j.createdAt <= :dateFin')
                    ->setParameter(
                        'dateFin',
                        new \DateTimeImmutable($filtres['date_fin'] . ' 23:59:59')
                    );
            } catch (\Exception) {
                // Date invalide ignorée.
            }
        }

        $total = (int) (clone $qb)
            ->select('COUNT(j.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $resultats = $qb
            ->setFirstResult(max(0, ($page - 1) * $parPage))
            ->setMaxResults(max(1, $parPage))
            ->getQuery()
            ->getResult();

        return [
            'resultats' => $resultats,
            'total' => $total,
        ];
    }

    /**
     * Liste des entités déjà présentes dans le journal (pour le filtre).
     *
     * @return string[]
     */
    public function listerEntites(): array
    {
        $resultats = $this->createQueryBuilder('j')
            ->select('DISTINCT j.entite')
            ->orderBy('j.entite', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($resultats, 'entite');
    }
}
