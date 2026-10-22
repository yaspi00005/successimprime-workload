<?php

namespace App\Repository;

use App\Entity\Clients;
use App\Entity\PrixSpecial;
use App\Entity\ProduitConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrixSpecial>
 */
class PrixSpecialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrixSpecial::class);
    }

    /**
     * Prix appliqué au client pour cette configuration ce jour-là (le plus
     * récent s'il y en a plusieurs).
     */
    public function valablePour(Clients $client, ProduitConfiguration $configuration, \DateTimeInterface $jour): ?PrixSpecial
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.client = :client')->andWhere('ps.configuration = :config')
            ->andWhere('ps.statut = :actif')
            ->andWhere('ps.finLe IS NULL OR ps.finLe >= :jour')
            ->setParameter('client', $client)->setParameter('config', $configuration)
            ->setParameter('actif', PrixSpecial::ACTIF)->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('ps.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Un seul prix actif par client et configuration : celui qui vient
     * d'être accordé arrête les précédents (à enregistrer par l'appelant).
     */
    public function arreterPrecedents(PrixSpecial $nouveau): int
    {
        $n = 0;
        foreach ($this->findBy(['client' => $nouveau->getClient(), 'configuration' => $nouveau->getConfiguration(), 'statut' => PrixSpecial::ACTIF]) as $ancien) {
            if ($ancien !== $nouveau) {
                $ancien->setStatut(PrixSpecial::ARRETE);
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Prix valables du client, par identifiant de configuration.
     *
     * @return array<int, PrixSpecial>
     */
    public function valablesDuClient(Clients $client, \DateTimeInterface $jour): array
    {
        $parConfig = [];
        foreach ($this->createQueryBuilder('ps')
            ->where('ps.client = :client')->andWhere('ps.statut = :actif')
            ->andWhere('ps.finLe IS NULL OR ps.finLe >= :jour')
            ->setParameter('client', $client)->setParameter('actif', PrixSpecial::ACTIF)->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('ps.id', 'ASC')
            ->getQuery()->getResult() as $ps) {
            $parConfig[(int) $ps->getConfiguration()?->getId()] = $ps;
        }

        return $parConfig;
    }

    /**
     * Prix de l'écran « Prix spéciaux » : en attente d'abord, puis les
     * appliqués, puis les autres, les plus récents en tête.
     *
     * @return list<PrixSpecial>
     */
    public function pourEcran(int $limite = 200): array
    {
        return $this->createQueryBuilder('ps')
            ->select('ps', 'c', 'pc', 'p')
            ->innerJoin('ps.client', 'c')
            ->innerJoin('ps.configuration', 'pc')
            ->innerJoin('pc.produit', 'p')
            ->addSelect("CASE WHEN ps.statut = 'attente' THEN 0 WHEN ps.statut = 'actif' THEN 1 ELSE 2 END AS HIDDEN rang")
            ->orderBy('rang', 'ASC')->addOrderBy('ps.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    public function compterEnAttente(): int
    {
        return $this->count(['statut' => PrixSpecial::ATTENTE]);
    }
}
