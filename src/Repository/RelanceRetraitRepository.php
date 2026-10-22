<?php

namespace App\Repository;

use App\Entity\RelanceRetrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelanceRetrait>
 */
class RelanceRetraitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelanceRetrait::class);
    }

    /**
     * Dernière relance et nombre de relances par commande.
     *
     * @param list<int> $commandes
     *
     * @return array<int, array{derniere: RelanceRetrait, nombre: int}>
     */
    public function parCommande(array $commandes): array
    {
        if ($commandes === []) {
            return [];
        }

        $relances = $this->createQueryBuilder('r')
            ->where('r.commande IN (:commandes)')
            ->setParameter('commandes', $commandes)
            ->orderBy('r.envoyeeLe', 'ASC')
            ->getQuery()
            ->getResult();

        $parCommande = [];

        foreach ($relances as $relance) {
            $id = (int) $relance->getCommande()->getId();
            $parCommande[$id] = ['derniere' => $relance, 'nombre' => ($parCommande[$id]['nombre'] ?? 0) + 1];
        }

        return $parCommande;
    }
}
