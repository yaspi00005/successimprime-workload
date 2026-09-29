<?php

namespace App\Repository;

use App\Entity\Campagne;
use App\Entity\CampagneDestinataire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampagneDestinataire>
 */
class CampagneDestinataireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampagneDestinataire::class);
    }

    /**
     * Prochain lot de destinataires "en attente", tous campagnes
     * confondues, les plus anciens d'abord : utilisé par
     * CampagneService pour étaler les envois dans le temps sans
     * dépasser les limites de débit des API SMS/WhatsApp/email.
     *
     * @return array<int, CampagneDestinataire>
     */
    public function findEnAttente(int $limite): array
    {
        return $this->createQueryBuilder('destinataire')
            ->leftJoin('destinataire.campagne', 'campagne')
            ->addSelect('campagne')
            ->leftJoin('destinataire.client', 'client')
            ->addSelect('client')
            ->andWhere('destinataire.statut = :statut')
            ->setParameter('statut', CampagneDestinataire::STATUT_EN_ATTENTE)
            ->orderBy('destinataire.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function compteEnAttente(Campagne $campagne): int
    {
        return (int) $this->createQueryBuilder('destinataire')
            ->select('COUNT(destinataire.id)')
            ->andWhere('destinataire.campagne = :campagne')
            ->andWhere('destinataire.statut = :statut')
            ->setParameter('campagne', $campagne)
            ->setParameter('statut', CampagneDestinataire::STATUT_EN_ATTENTE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function compterParStatut(Campagne $campagne): array
    {
        $resultats = $this->createQueryBuilder('destinataire')
            ->select('destinataire.statut AS statut, COUNT(destinataire.id) AS total')
            ->andWhere('destinataire.campagne = :campagne')
            ->setParameter('campagne', $campagne)
            ->groupBy('destinataire.statut')
            ->getQuery()
            ->getResult();

        $compteurs = [
            CampagneDestinataire::STATUT_EN_ATTENTE => 0,
            CampagneDestinataire::STATUT_ENVOYE => 0,
            CampagneDestinataire::STATUT_ECHEC => 0,
            CampagneDestinataire::STATUT_IGNORE => 0,
        ];

        foreach ($resultats as $ligne) {
            $compteurs[$ligne['statut']] = (int) $ligne['total'];
        }

        return $compteurs;
    }

    /**
     * @return array<int, CampagneDestinataire>
     */
    public function findParCampagne(Campagne $campagne): array
    {
        return $this->createQueryBuilder('destinataire')
            ->leftJoin('destinataire.client', 'client')
            ->addSelect('client')
            ->andWhere('destinataire.campagne = :campagne')
            ->setParameter('campagne', $campagne)
            ->orderBy('destinataire.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
