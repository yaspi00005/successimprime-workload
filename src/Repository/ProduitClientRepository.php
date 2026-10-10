<?php

namespace App\Repository;

use App\Entity\Clients;
use App\Entity\ProduitClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProduitClient>
 */
class ProduitClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProduitClient::class);
    }

    /**
     * @return list<ProduitClient>
     */
    public function actifsDuClient(Clients $client): array
    {
        return $this->findBy(['client' => $client, 'actif' => true], ['nom' => 'ASC']);
    }

    /**
     * Prochaine reference du client : PC-<3 lettres du nom>-<rang sur 2 chiffres>.
     */
    public function prochaineReference(Clients $client): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', (string) ($client->getNom() ?: $client->getPrenom())) ?: '') ?: 'CLI', 0, 3));
        $prefixe = sprintf('PC-%s-', str_pad($base, 3, 'X'));

        $existantes = $this->createQueryBuilder('p')
            ->select('p.reference')
            ->where('p.reference LIKE :prefixe')
            ->setParameter('prefixe', $prefixe.'%')
            ->getQuery()
            ->getSingleColumnResult();

        $rang = 1;

        foreach ($existantes as $reference) {
            $rang = max($rang, (int) substr($reference, \strlen($prefixe)) + 1);
        }

        return $prefixe.str_pad((string) $rang, 2, '0', \STR_PAD_LEFT);
    }
}
