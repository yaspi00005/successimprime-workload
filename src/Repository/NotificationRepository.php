<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return Notification[]
     */
    public function findNonLuesPourUtilisateur(User $utilisateur, int $limite = 10): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.lue = false')
            ->setParameter('destinataire', $utilisateur)
            ->orderBy('n.dateCreation', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Notifications non lues d'une même sorte (même route, message qui
     * commence par le même préfixe), de toutes les personnes, les plus
     * récentes en premier. Exemple : les alertes de synchronisation.
     *
     * @return Notification[]
     */
    public function findNonLuesParSorte(string $route, string $prefixe): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.lue = false')
            ->andWhere('n.route = :route')
            ->andWhere('n.message LIKE :prefixe')
            ->setParameter('route', $route)
            ->setParameter('prefixe', addcslashes($prefixe, '%_\\').'%')
            ->orderBy('n.dateCreation', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countNonLuesPourUtilisateur(User $utilisateur): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :destinataire')
            ->andWhere('n.lue = false')
            ->setParameter('destinataire', $utilisateur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Toutes les notifications (lues et non lues) d'un utilisateur,
     * les plus récentes en premier, pour la page "Toutes les
     * notifications".
     *
     * @return Notification[]
     */
    public function findToutesPourUtilisateur(User $utilisateur, int $limite = 100): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :destinataire')
            ->setParameter('destinataire', $utilisateur)
            ->orderBy('n.dateCreation', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    // ------------------------------------------------------------------
    // Notifications de l'équipe à niveaux (docs/notifications-equipe.md)
    // ------------------------------------------------------------------

    /**
     * Notifications encore à montrer à cette personne : pas résolues, et pas
     * une info déjà lue. Les reportées sont comprises (le centre les range à part).
     *
     * @return Notification[]
     */
    public function actives(User $utilisateur, int $limite = 200): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :d')
            ->andWhere('n.resolueLe IS NULL')
            ->andWhere('n.lue = false OR n.niveau <> :info')
            ->setParameter('d', $utilisateur)
            ->setParameter('info', Notification::INFO)
            ->orderBy('n.dateCreation', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * Historique : résolues et infos lues, les plus récentes d'abord.
     *
     * @return Notification[]
     */
    public function historique(User $utilisateur, int $limite = 60): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :d')
            ->andWhere('n.resolueLe IS NOT NULL OR (n.lue = true AND n.niveau = :info)')
            ->setParameter('d', $utilisateur)
            ->setParameter('info', Notification::INFO)
            ->orderBy('n.dateCreation', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * Toutes les lignes d'une empreinte (toutes personnes, tous états).
     *
     * @return Notification[]
     */
    public function parEmpreinte(string $empreinte): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.empreinte = :e')
            ->setParameter('e', $empreinte)
            ->orderBy('n.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Notifications non résolues d'une sorte (toutes personnes).
     *
     * @return Notification[]
     */
    public function activesDuType(string $type): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type = :t')
            ->andWhere('n.resolueLe IS NULL')
            ->setParameter('t', $type)
            ->getQuery()->getResult();
    }

    /**
     * Urgentes et critiques non résolues (remontée et rappels).
     *
     * @return Notification[]
     */
    public function activesGraves(): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.type IS NOT NULL')
            ->andWhere('n.resolueLe IS NULL')
            ->andWhere('n.niveau IN (:graves)')
            ->setParameter('graves', [Notification::CRITIQUE, Notification::URGENT])
            ->orderBy('n.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Traitées aujourd'hui par cette personne (résumé du jour). */
    public function traiteesDepuis(User $utilisateur, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.destinataire = :d')
            ->andWhere('n.type IS NOT NULL')
            ->andWhere('n.resolueLe >= :depuis')
            ->andWhere('n.resolution <> :lue')
            ->setParameter('d', $utilisateur)
            ->setParameter('depuis', $depuis)
            ->setParameter('lue', Notification::RESOLUE_LUE)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Minutes entre l'arrivée et la résolution des notifications traitées
     * depuis cette date (temps moyen de traitement du résumé).
     *
     * @return list<int>
     */
    public function durees(User $utilisateur, \DateTimeImmutable $depuis): array
    {
        $lignes = $this->createQueryBuilder('n')
            ->select('n.dateCreation AS d', 'n.resolueLe AS r')
            ->andWhere('n.destinataire = :d')
            ->andWhere('n.type IS NOT NULL')
            ->andWhere('n.niveau <> :info')
            ->andWhere('n.resolueLe >= :depuis')
            ->setParameter('d', $utilisateur)
            ->setParameter('info', Notification::INFO)
            ->setParameter('depuis', $depuis)
            ->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $l): int => max(0, intdiv($l['r']->getTimestamp() - $l['d']->getTimestamp(), 60)), $lignes));
    }

    /**
     * Anciennes notifications (sans sorte) non lues qui mènent à la même page :
     * remplacées par une notification à niveaux, elles sont marquées lues.
     *
     * @param array<string, mixed> $parametres
     *
     * @return Notification[]
     */
    public function anciennesMemeLien(User $utilisateur, string $route, array $parametres): array
    {
        $lignes = $this->createQueryBuilder('n')
            ->andWhere('n.destinataire = :d')
            ->andWhere('n.type IS NULL')
            ->andWhere('n.lue = false')
            ->andWhere('n.route = :r')
            ->setParameter('d', $utilisateur)
            ->setParameter('r', $route)
            ->getQuery()->getResult();

        return array_values(array_filter($lignes, static fn (Notification $n): bool => $n->getRouteParametres() == $parametres));
    }
}
