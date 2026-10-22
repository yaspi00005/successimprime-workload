<?php

namespace App\Repository;

use App\Entity\ColisProduction;
use App\Entity\Commandes;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ColisProduction>
 */
class ColisProductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ColisProduction::class);
    }

    /**
     * Les colis en cours d'une commande (non remplacés), 1/n d'abord.
     *
     * @return list<ColisProduction>
     */
    public function actifs(Commandes $commande): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.commande = :c AND c.annuleLe IS NULL')->setParameter('c', $commande)
            ->orderBy('c.rang', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Les colis en cours de plusieurs commandes, rangés par id de commande.
     *
     * @param list<int> $commandes
     *
     * @return array<int, list<ColisProduction>>
     */
    public function actifsDe(array $commandes): array
    {
        if ($commandes === []) {
            return [];
        }
        $parCommande = [];
        foreach ($this->createQueryBuilder('c')
            ->where('c.commande IN (:c) AND c.annuleLe IS NULL')->setParameter('c', $commandes)
            ->orderBy('c.rang', 'ASC')
            ->getQuery()->getResult() as $colis) {
            $parCommande[(int) $colis->getCommande()->getId()][] = $colis;
        }

        return $parCommande;
    }

    /** Dernière série d'étiquettes imprimée pour la commande (0 : jamais). */
    public function derniereSerie(Commandes $commande): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('MAX(c.serie)')->where('c.commande = :c')->setParameter('c', $commande)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param array{jeton: string}|array{code: string} $saisie */
    public function trouver(array $saisie): ?ColisProduction
    {
        return isset($saisie['jeton'])
            ? $this->findOneBy(['jeton' => $saisie['jeton']])
            : $this->findOneBy(['code' => $saisie['code']]);
    }
}
