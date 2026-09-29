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
     * Conversations de cet utilisateur, les plus récemment actives en
     * premier.
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
     * Conversation directe (à deux participants) existante entre ces
     * deux utilisateurs, s'il y en a déjà une.
     */
    public function trouverConversationDirecte(User $a, User $b): ?Conversation
    {
        $conversations = $this->createQueryBuilder('c')
            ->innerJoin('c.participants', 'pa')
            ->innerJoin('c.participants', 'pb')
            ->andWhere('pa.utilisateur = :a')
            ->andWhere('pb.utilisateur = :b')
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
