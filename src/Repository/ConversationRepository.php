<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Conversations de cet utilisateur (groupes et messages privés), les
     * plus récemment actives en premier.
     *
     * @return Conversation[]
     */
    public function findPourUtilisateur(User $utilisateur): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'p')
            ->andWhere('p.utilisateur = :utilisateur')
            ->setParameter('utilisateur', $utilisateur)
            ->orderBy('c.dateDernierMessage', 'DESC')
            ->addOrderBy('c.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Groupes dont la personne n'est pas membre (lecture par la Direction,
     * si le réglage le permet). Jamais de message privé.
     *
     * @return Conversation[]
     */
    public function findGroupesSansMembre(User $utilisateur): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.type <> :prive')
            ->andWhere('NOT EXISTS (SELECT p.id FROM App\Entity\ConversationParticipant p WHERE p.conversation = c AND p.utilisateur = :u)')
            ->setParameter('prive', Conversation::PRIVE)
            ->setParameter('u', $utilisateur)
            ->orderBy('c.dateDernierMessage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Conversation[] groupes ouverts qui ont des membres par rôle */
    public function findGroupesOuverts(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.type <> :prive')->andWhere('c.fermeLe IS NULL')
            ->setParameter('prive', Conversation::PRIVE)
            ->getQuery()->getResult();
    }

    /**
     * Groupes de projet ouverts, à fermeture automatique, liés à une
     * commande ou à un devis (filtrés sur ces commandes si données).
     *
     * @param list<int>|null $commandes
     *
     * @return Conversation[]
     */
    public function findProjetsAFermer(?array $commandes): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.devis', 'd')
            ->andWhere('c.type = :projet')->andWhere('c.fermeLe IS NULL')->andWhere('c.fermetureAuto = true')
            ->andWhere('c.commande IS NOT NULL OR d.commande IS NOT NULL')
            ->setParameter('projet', Conversation::PROJET);
        if (null !== $commandes) {
            if ([] === $commandes) {
                return [];
            }
            $qb->andWhere('IDENTITY(c.commande) IN (:ids) OR IDENTITY(d.commande) IN (:ids)')->setParameter('ids', $commandes);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Message privé existant entre ces deux utilisateurs, s'il y en a déjà un.
     */
    public function trouverConversationDirecte(User $a, User $b): ?Conversation
    {
        $conversations = $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'pa')
            ->innerJoin('c.participants', 'pb')
            ->andWhere('c.type = :prive')
            ->andWhere('pa.utilisateur = :a')
            ->andWhere('pb.utilisateur = :b')
            ->setParameter('prive', Conversation::PRIVE)
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->getResult();

        foreach ($conversations as $conversation) {
            if ($conversation->getParticipants()->count() === 2) {
                return $conversation;
            }
        }

        return null;
    }
}
