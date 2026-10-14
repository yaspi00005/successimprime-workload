<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantMessage>
 */
class AssistantMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantMessage::class);
    }

    /** @return list<AssistantMessage> dans l'ordre de la conversation */
    public function deLaConversation(AssistantConversation $conversation): array
    {
        return $this->findBy(['conversation' => $conversation], ['id' => 'ASC']);
    }

    /** Une réponse de l'assistant de cette conversation, par son identifiant public. */
    public function reponse(AssistantConversation $conversation, string $publicId): ?AssistantMessage
    {
        foreach ($this->deLaConversation($conversation) as $m) {
            if (!$m->estDuClient() && (string) $m->getPublicId() === $publicId) {
                return $m;
            }
        }

        return null;
    }

    /** Dépense de l'API depuis cette heure (en FCFA, au taux du jour de chaque réponse). */
    public function depenseDepuis(\DateTimeImmutable $depuis): float
    {
        return (float) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.depenseFcfa), 0)')
            ->where('m.creeLe >= :depuis')->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Messages des conversations commencées depuis cette date, pour
     * l'analyse et la liste (dans l'ordre).
     *
     * @param list<int>|null $conversations limiter à ces conversations
     *
     * @return list<AssistantMessage>
     */
    public function depuis(\DateTimeImmutable $depuis, ?array $conversations = null): array
    {
        if ([] === $conversations) {
            return [];
        }
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.conversation', 'c')->addSelect('c')
            ->where('c.debutLe >= :depuis')->setParameter('depuis', $depuis)
            ->orderBy('m.id', 'ASC');
        if (null !== $conversations) {
            $qb->andWhere('c.id IN (:ids)')->setParameter('ids', $conversations);
        }

        return $qb->getQuery()->getResult();
    }
}
