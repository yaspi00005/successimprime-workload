<?php

namespace App\Repository;

use App\Entity\AppareilAutorise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppareilAutorise>
 */
class AppareilAutoriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppareilAutorise::class);
    }

    public function parEmpreinte(string $empreinte): ?AppareilAutorise
    {
        return $this->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Appareils pour l'écran des réglages : sans les invitations, les
     * plus récemment utilisés d'abord.
     *
     * @return list<AppareilAutorise>
     */
    public function pourLaListe(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.utilisateur', 'u')->addSelect('u')
            ->where('a.etat != :attente')
            ->setParameter('attente', AppareilAutorise::ETAT_EN_ATTENTE)
            ->orderBy('a.derniereConnexion', 'DESC')
            ->addOrderBy('a.creeLe', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
    }

    /** Une tentative refusée déjà notée aujourd'hui pour ce navigateur et cette adresse. */
    public function refusDuJour(string $ip, string $navigateur, \DateTimeImmutable $depuis): ?AppareilAutorise
    {
        return $this->createQueryBuilder('a')
            ->where('a.etat IN (:etats)')
            ->andWhere('a.ip = :ip')
            ->andWhere('a.navigateur = :nav')
            ->andWhere('a.creeLe >= :depuis')
            ->setParameter('etats', [AppareilAutorise::ETAT_REFUSE, AppareilAutorise::ETAT_BLOQUE])
            ->setParameter('ip', $ip)
            ->setParameter('nav', mb_substr($navigateur, 0, 255))
            ->setParameter('depuis', $depuis)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function estBloque(string $ip): bool
    {
        return null !== $this->findOneBy(['etat' => AppareilAutorise::ETAT_BLOQUE, 'ip' => $ip]);
    }
}
