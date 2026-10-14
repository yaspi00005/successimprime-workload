<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\CompteClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssistantConversation>
 */
class AssistantConversationRepository extends ServiceEntityRepository
{
    /** Filtres de la liste (Clients › Assistant du site). */
    public const FILTRES = [
        '' => 'Toutes',
        'agent' => 'Passées à un conseiller',
        'pas_utile' => 'Avec un avis « pas utile »',
        'utile' => 'Avec un avis « utile »',
        'sans_reponse' => 'Sans réponse de l\'assistant',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantConversation::class);
    }

    /**
     * La conversation de ce jeton, dans ce contexte ; dans l'espace client,
     * seulement si elle est à ce compte.
     */
    public function parJeton(string $jetonClair, string $contexte, ?CompteClient $compte): ?AssistantConversation
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $jetonClair)) {
            return null;
        }
        $conversation = $this->findOneBy(['jeton' => AssistantConversation::empreinte($jetonClair), 'contexte' => $contexte]);
        if (null === $conversation) {
            return null;
        }
        if (AssistantConversation::ESPACE === $contexte && (null === $compte || $conversation->getCompte()?->getId() !== $compte->getId())) {
            return null;
        }

        return $conversation;
    }

    /**
     * Conversations commencées depuis cette date, les plus récentes d'abord.
     *
     * @return list<AssistantConversation>
     */
    public function liste(\DateTimeImmutable $depuis, string $contexte, string $filtre, string $recherche, int $nombre = 200): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->where('c.debutLe >= :depuis')->setParameter('depuis', $depuis)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($nombre);
        if (isset(AssistantConversation::CONTEXTES[$contexte])) {
            $qb->andWhere('c.contexte = :contexte')->setParameter('contexte', $contexte);
        }
        $existe = fn (string $condition): string => \sprintf('EXISTS (SELECT m.id FROM %s m WHERE m.conversation = c AND %s)', AssistantMessage::class, $condition);
        match ($filtre) {
            'agent' => $qb->andWhere('c.passageAgentLe IS NOT NULL'),
            'pas_utile' => $qb->andWhere($existe('m.note = -1')),
            'utile' => $qb->andWhere($existe('m.note = 1')),
            'sans_reponse' => $qb->andWhere($existe("m.role = 'assistant' AND m.issue NOT IN ('reponse', 'agent')")),
            default => null,
        };
        $recherche = trim($recherche);
        if ('' !== $recherche) {
            $qb->andWhere(\sprintf('EXISTS (SELECT r.id FROM %s r WHERE r.conversation = c AND r.texte LIKE :texte)', AssistantMessage::class))
                ->setParameter('texte', '%'.addcslashes(mb_substr($recherche, 0, 80), '%_\\').'%');
        }

        return $qb->getQuery()->getResult();
    }
}
