<?php

namespace App\Repository;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccesEspaceClient>
 */
class AccesEspaceClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccesEspaceClient::class);
    }

    public function parEmpreinte(string $empreinte): ?AccesEspaceClient
    {
        return '' === $empreinte ? null : $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Marque le code servi, en une seule requête : de deux connexions
     * simultanées avec le même code, une seule passe.
     */
    public function consommer(AccesEspaceClient $acces, \DateTimeImmutable $le): bool
    {
        $lignes = $this->getEntityManager()->createQuery(
            'UPDATE '.AccesEspaceClient::class.' a SET a.empreinteUtilisee = a.empreinte, a.utiliseLe = :le
             WHERE a.id = :id AND a.empreinte = :empreinte AND (a.empreinteUtilisee IS NULL OR a.empreinteUtilisee <> a.empreinte)'
        )
            ->setParameter('le', $le, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $acces->getId())
            ->setParameter('empreinte', $acces->getEmpreinte())
            ->execute();
        $acces->utiliser($le);

        return 1 === $lignes;
    }

    public function duClient(Clients $client): ?AccesEspaceClient
    {
        return $this->findOneBy(['client' => $client]);
    }
}
