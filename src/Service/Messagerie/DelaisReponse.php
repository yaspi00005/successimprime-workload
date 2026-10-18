<?php

namespace App\Service\Messagerie;

use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Délais de réponse aux clients (maquettes MS03 et MS04) : chronomètre
 * d'une conversation, supervision de la semaine par personne responsable,
 * retards en cours, et bilan d'une personne pour la prime (note
 * « Réponses aux messages » : 100, moins la pénalité par réponse en retard).
 */
final class DelaisReponse
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglesReponse $regles,
    ) {
    }

    /**
     * Chronomètre d'une conversation qui attend : attente en minutes
     * ouvrées, délai cible, retard, à qui il est imputé.
     *
     * @return array{attente: int, cible: int, retard: int, reste: int, impute: ?User, premiere: bool}|null
     */
    public function chrono(ConversationClient $c, \DateTimeImmutable $maintenant): ?array
    {
        if (!$c->estEnAttente()) {
            return null;
        }
        $premiere = null === $c->getPremiereReponseLe();
        $m = $this->regles->mesurer($c->getAttenteDepuis(), $maintenant, $premiere);
        $impute = $c->getAssigneA();

        return $m + ['reste' => max(0, $m['cible'] - $m['attente']), 'impute' => null !== $impute && Conversations::imputable($impute, $this->regles->tresoriere()) ? $impute : null, 'premiere' => $premiere];
    }

    /** Dernier délai de réponse de la conversation (« Répondu en 4 min »), ou null. */
    public function dernierDelai(ConversationClient $c): ?int
    {
        $m = $this->em->createQuery('SELECT m FROM '.MessageClient::class.' m WHERE m.conversation = :c AND m.sens = :a AND m.delaiMinutes IS NOT NULL ORDER BY m.creeLe DESC, m.id DESC')
            ->setParameter('c', $c)->setParameter('a', MessageClient::ATELIER)->setMaxResults(1)->getOneOrNullResult();

        return $m instanceof MessageClient ? $m->getDelaiMinutes() : null;
    }

    /**
     * Supervision d'une période (MS04).
     *
     * @return array{
     *   conversations: int, ouvertes: int, mediane: ?int, cible: int, enRetard: int, pctRetard: ?int, satisfaction: ?float, avis: int,
     *   personnes: list<array{personne: User, conversations: int, mediane: ?int, enRetard: int, retardCumule: int, points: int}>
     * }
     */
    public function supervision(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<ConversationClient> $actives */
        $actives = $this->em->createQuery('SELECT DISTINCT c FROM '.ConversationClient::class.' c WHERE c.creeLe < :fin AND (c.dernierMessageLe >= :debut OR c.creeLe >= :debut)')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)->getResult();
        /** @var list<MessageClient> $reponses */
        $reponses = $this->em->createQuery('SELECT m, c FROM '.MessageClient::class.' m JOIN m.conversation c WHERE m.sens = :a AND m.delaiMinutes IS NOT NULL AND m.creeLe >= :debut AND m.creeLe < :fin')
            ->setParameter('a', MessageClient::ATELIER)->setParameter('debut', $debut)->setParameter('fin', $fin)->getResult();
        $avis = $this->em->createQuery('SELECT AVG(c.avis) AS moyenne, COUNT(c.id) AS n FROM '.ConversationClient::class.' c WHERE c.avis IS NOT NULL AND c.avisLe >= :debut AND c.avisLe < :fin')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)->getSingleResult();

        $premieres = [];
        $enRetard = 0;
        $convRetard = [];
        $parPersonne = [];
        foreach ($reponses as $m) {
            if ($m->isPremiere()) {
                $premieres[] = (int) $m->getDelaiMinutes();
            }
            if ($m->estEnRetard()) {
                ++$enRetard;
                $convRetard[$m->getConversation()->getId()] = true;
            }
            $p = $m->getImputeA();
            if (null === $p) {
                continue;
            }
            $id = (int) $p->getId();
            $parPersonne[$id] ??= ['personne' => $p, 'conv' => [], 'premieres' => [], 'enRetard' => 0, 'retardCumule' => 0, 'points' => 0];
            $parPersonne[$id]['conv'][$m->getConversation()->getId()] = true;
            if ($m->isPremiere()) {
                $parPersonne[$id]['premieres'][] = (int) $m->getDelaiMinutes();
            }
            if ($m->estEnRetard()) {
                ++$parPersonne[$id]['enRetard'];
                $parPersonne[$id]['retardCumule'] += (int) $m->getRetardMinutes();
            }
            $parPersonne[$id]['points'] -= $m->getPenalite();
        }
        foreach ($actives as $c) {
            $p = $c->getAssigneA();
            if (null !== $p && isset($parPersonne[$p->getId()])) {
                $parPersonne[$p->getId()]['conv'][$c->getId()] = true;
            }
        }
        $personnes = array_map(static fn (array $p): array => [
            'personne' => $p['personne'], 'conversations' => \count($p['conv']), 'mediane' => self::mediane($p['premieres']),
            'enRetard' => $p['enRetard'], 'retardCumule' => $p['retardCumule'], 'points' => $p['points'],
        ], array_values($parPersonne));
        usort($personnes, static fn (array $a, array $b): int => $b['conversations'] <=> $a['conversations']);

        $n = \count($actives);

        return [
            'conversations' => $n,
            'ouvertes' => \count(array_filter($actives, static fn (ConversationClient $c): bool => $c->estOuverte())),
            'mediane' => self::mediane($premieres),
            'cible' => $this->regles->premiere(),
            'enRetard' => $enRetard,
            'pctRetard' => $n > 0 ? (int) round(\count($convRetard) * 100 / $n) : null,
            'satisfaction' => (int) $avis['n'] > 0 ? round((float) $avis['moyenne'], 1) : null,
            'avis' => (int) $avis['n'],
            'personnes' => $personnes,
        ];
    }

    /**
     * Conversations en retard en ce moment, la plus en retard d'abord.
     *
     * @param list<ConversationClient> $enAttente
     *
     * @return list<array{conversation: ConversationClient, chrono: array<string, mixed>}>
     */
    public function retardsEnCours(array $enAttente, \DateTimeImmutable $maintenant): array
    {
        $liste = [];
        foreach ($enAttente as $c) {
            $chrono = $this->chrono($c, $maintenant);
            if (null !== $chrono && $chrono['retard'] > 0) {
                $liste[] = ['conversation' => $c, 'chrono' => $chrono];
            }
        }
        usort($liste, static fn (array $a, array $b): int => $b['chrono']['retard'] <=> $a['chrono']['retard']);

        return $liste;
    }

    /**
     * Bilan d'une personne sur une période (critère de prime « Réponses aux
     * messages ») : réponses dont elle était responsable, en retard, points retirés.
     *
     * @return array{reponses: int, enRetard: int, penalite: int, note: ?int}
     */
    public function bilan(User $personne, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $r = $this->em->createQuery('SELECT COUNT(m.id) AS reponses, SUM(CASE WHEN m.retardMinutes > 0 THEN 1 ELSE 0 END) AS enRetard, SUM(m.penalite) AS penalite FROM '.MessageClient::class.' m WHERE m.imputeA = :p AND m.delaiMinutes IS NOT NULL AND m.creeLe >= :debut AND m.creeLe < :fin')
            ->setParameter('p', $personne)->setParameter('debut', $debut)->setParameter('fin', $fin)->getSingleResult();
        $reponses = (int) $r['reponses'];
        $penalite = (int) $r['penalite'];

        return ['reponses' => $reponses, 'enRetard' => (int) $r['enRetard'], 'penalite' => $penalite, 'note' => $reponses > 0 ? max(0, 100 - $penalite) : null];
    }

    /** @param list<int> $valeurs */
    public static function mediane(array $valeurs): ?int
    {
        if ([] === $valeurs) {
            return null;
        }
        sort($valeurs);
        $n = \count($valeurs);
        $milieu = intdiv($n, 2);

        return $n % 2 ? $valeurs[$milieu] : (int) round(($valeurs[$milieu - 1] + $valeurs[$milieu]) / 2);
    }
}
