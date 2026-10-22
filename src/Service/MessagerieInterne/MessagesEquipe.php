<?php

namespace App\Service\MessagerieInterne;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\PieceJointeMessage;
use App\Entity\ReactionMessage;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Security\MessagerieInterneVoter;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Messages de l'équipe (docs/messagerie-interne.md, MI01 et MI05) : envoi
 * (texte, @mentions, commandes citées, réponse, fichiers, note vocale),
 * réactions, épingles, lecture et non-lus par fil, présence.
 *
 * Prévenir : une @mention, et un message privé non lu, créent une
 * Notification (cloche et téléphone par EnvoiPush). Un message privé suivi
 * d'autres avant lecture met à jour la même notification au lieu d'en
 * ajouter une par message.
 *
 * Jamais de journal du contenu : rien de ce qui est écrit ici ne part dans
 * le journal d'activité (AuditSubscriber ne suit pas ces entités).
 */
class MessagesEquipe
{
    public const ROUTE = 'app_messagerie_interne_voir';

    /** Présence : « en ligne » si la messagerie a été ouverte dans les 2 dernières minutes. */
    private const PRESENCE = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly CartesCommande $cartes,
        private readonly NotificationService $notifications,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<int>                $mentionsChoisies ids choisis dans l'autocomplétion
     * @param list<PieceJointeMessage> $pieces           fichiers déjà rangés (pas encore persistés)
     *
     * @throws \DomainException
     */
    public function envoyer(Conversation $c, User $auteur, string $texte, ?Message $reponseA = null, array $mentionsChoisies = [], array $pieces = [], ?array $vocale = null): Message
    {
        if (!MessagerieInterneVoter::peutEcrire($auteur, $c)) {
            throw new \DomainException($c->estFerme() ? 'Ce groupe est fermé : il se lit seulement.' : 'Vous ne pouvez pas écrire ici.');
        }
        $texte = trim($texte);
        if (mb_strlen($texte) > Message::LONGUEUR_MAX) {
            throw new \DomainException(\sprintf('Message trop long : %d caractères au plus.', Message::LONGUEUR_MAX));
        }
        if ('' === $texte && [] === $pieces && null === $vocale) {
            throw new \DomainException('Le message est vide.');
        }
        if (null !== $reponseA && $reponseA->getConversation()?->getId() !== $c->getId()) {
            $reponseA = null;
        }

        $message = (new Message())->setConversation($c)->setAuteur($auteur)->setContenu($texte)->setReponseA($reponseA);
        if (null !== $vocale) {
            $message->joindreNoteVocale($vocale);
            if ('' === $texte) {
                $message->setContenu(Message::libelleVocale($vocale['duree']));
            }
        }
        $message->setMentions($this->mentions($c, $auteur, $texte, $mentionsChoisies));
        $commandes = '' === $texte ? [] : $this->cartes->trouver($texte);
        $message->setCommandes(array_map(static fn ($cmd): int => (int) $cmd->getId(), $commandes));
        $commandeDesFichiers = $c->getCommandeSuivie() ?? ($commandes[0] ?? null);
        foreach ($pieces as $piece) {
            $message->ajouterPiece($piece->setConversation($c)->setCommande($piece->getCommande() ?? $commandeDesFichiers));
            $this->em->persist($piece);
        }
        $c->getMessages()->add($message);
        $c->setDateDernierMessage(new \DateTimeImmutable());
        $this->em->persist($message);
        $this->em->flush();

        $c->getParticipantPour($auteur)?->marquerLue((int) $message->getId());
        $this->prevenir($c, $message, $auteur);
        $this->em->flush();

        return $message;
    }

    /**
     * Personnes citées : celles choisies dans l'autocomplétion, et les
     * « @Prénom » qui désignent un seul membre. Seulement des membres (les
     * autres ne lisent pas le fil), jamais l'auteur.
     *
     * @param list<int> $choisies
     *
     * @return list<int>
     */
    public function mentions(Conversation $c, User $auteur, string $texte, array $choisies): array
    {
        $membres = [];
        foreach ($c->getMembres() as $m) {
            if ($m->getId() !== $auteur->getId()) {
                $membres[(int) $m->getId()] = $m;
            }
        }
        $ids = array_values(array_intersect(array_map('intval', $choisies), array_keys($membres)));
        // Une sélection non écrite dans le texte ne compte pas.
        $ids = array_values(array_filter($ids, fn (int $id): bool => $this->ecritDans($texte, $membres[$id])));
        preg_match_all('/(?<![\p{L}\p{N}@])@([\p{L}][\p{L}\p{N}._-]{1,40})/u', $texte, $m);
        foreach ($m[1] as $jeton) {
            $trouves = array_keys(array_filter($membres, static fn (User $u): bool => \in_array(self::cle($jeton), self::clesDe($u), true)));
            if (1 === \count($trouves)) {
                $ids[] = $trouves[0];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Ce que l'autocomplétion écrit pour une personne : « @Oumar ». */
    public static function pseudo(User $u): string
    {
        $prenom = trim((string) $u->getEmploye()?->getPrenom());

        return '@'.('' !== $prenom ? preg_replace('/\s+/u', '', $prenom) : $u->getUsername());
    }

    /** @return list<string> façons de citer la personne (prénom, prénom.nom, identifiant), sans accent ni majuscule */
    public static function clesDe(User $u): array
    {
        $prenom = (string) $u->getEmploye()?->getPrenom();
        $nom = (string) $u->getEmploye()?->getNom();

        return array_values(array_filter(array_unique([
            self::cle($prenom),
            self::cle($prenom.'.'.$nom),
            self::cle($prenom.$nom),
            self::cle((string) $u->getUsername()),
        ])));
    }

    public static function cle(string $texte): string
    {
        $t = mb_strtolower(trim($texte));
        $t = strtr($t, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/[^a-z0-9.]/', '', $t);
    }

    private function ecritDans(string $texte, User $u): bool
    {
        preg_match_all('/@([\p{L}][\p{L}\p{N}._-]{1,40})/u', $texte, $m);
        foreach ($m[1] as $jeton) {
            if (\in_array(self::cle($jeton), self::clesDe($u), true)) {
                return true;
            }
        }

        return false;
    }

    /** Notifications : les personnes citées ; l'autre personne d'un message privé. */
    private function prevenir(Conversation $c, Message $m, User $auteur): void
    {
        $de = Conversation::nomDe($auteur);
        $apercu = $m->apercu(90);
        $deja = [];
        foreach ($m->getMentions() as $id) {
            $u = $this->em->find(User::class, $id);
            if ($u instanceof User && $u->isActif()) {
                $lieu = $c->estPrive() ? 'un message privé' : '#'.$c->getNom();
                $this->notifications->notifierUtilisateur($u, \sprintf('%s vous a cité dans %s : %s', $de, $lieu, $apercu), self::ROUTE, ['jeton' => $c->getJeton()]);
                $deja[] = $id;
            }
        }
        if (!$c->estPrive()) {
            return;
        }
        $autre = $c->getAutreUtilisateurPour($auteur);
        if (null === $autre || !$autre->isActif() || \in_array($autre->getId(), $deja, true)) {
            return;
        }
        $texte = \sprintf('Message privé de %s : %s', $de, $apercu);
        foreach ($this->em->getRepository(Notification::class)->findBy(['destinataire' => $autre, 'route' => self::ROUTE, 'lue' => false]) as $n) {
            if (($n->getRouteParametres()['jeton'] ?? null) === $c->getJeton()) {
                // Pas une notification par message : la même, remise à jour, tant qu'elle n'est pas lue.
                $n->remettreAJour($texte, new \DateTimeImmutable());

                return;
            }
        }
        $this->notifications->notifierUtilisateur($autre, $texte, self::ROUTE, ['jeton' => $c->getJeton()]);
    }

    /** Ajoute ou retire la réaction de la personne. Rend vrai si elle est posée. */
    public function reagir(Message $m, User $moi, string $emoji): bool
    {
        if (!\in_array($emoji, ReactionMessage::EMOJIS, true)) {
            throw new \DomainException('Réaction inconnue.');
        }
        foreach ($m->getReactions() as $r) {
            if ($r->getUtilisateur()?->getId() === $moi->getId() && $r->getEmoji() === $emoji) {
                $m->getReactions()->removeElement($r);
                $this->em->remove($r);
                $this->em->flush();

                return false;
            }
        }
        $r = new ReactionMessage($m, $moi, $emoji);
        $m->getReactions()->add($r);
        $this->em->persist($r);
        $this->em->flush();

        return true;
    }

    public function epingler(Message $m, User $moi): bool
    {
        $m->epingler($moi, $m->estEpingle() ? null : new \DateTimeImmutable());
        $this->em->flush();

        return $m->estEpingle();
    }

    /** Marque le fil lu jusqu'au dernier message, et ses notifications lues. */
    public function marquerLu(Conversation $c, User $moi): void
    {
        $p = $c->getParticipantPour($moi);
        if (null === $p) {
            return;
        }
        $dernier = (int) $this->em->createQuery('SELECT MAX(m.id) FROM '.Message::class.' m WHERE m.conversation = :c')->setParameter('c', $c)->getSingleScalarResult();
        $change = $dernier > $p->getDernierLuId();
        $p->marquerLue($dernier);
        foreach ($this->em->getRepository(Notification::class)->findBy(['destinataire' => $moi, 'route' => self::ROUTE, 'lue' => false]) as $n) {
            if (($n->getRouteParametres()['jeton'] ?? null) === $c->getJeton()) {
                $n->marquerLue();
                $change = true;
            }
        }
        if ($change) {
            $this->em->flush();
        }
    }

    /**
     * Non-lus par fil de la personne : id de la conversation => nombre de
     * messages des autres après son dernier lu.
     *
     * @return array<int, int>
     */
    public function nonLus(User $moi): array
    {
        $lignes = $this->em->createQuery(
            'SELECT IDENTITY(m.conversation) AS c, COUNT(m.id) AS n FROM '.Message::class.' m, '.ConversationParticipant::class.' p
             WHERE p.conversation = m.conversation AND p.utilisateur = :moi AND m.id > p.dernierLuId AND m.auteur <> :moi AND m.genre = :texte
             GROUP BY m.conversation'
        )->setParameters(['moi' => $moi, 'texte' => Message::TEXTE])->getArrayResult();
        $nonLus = [];
        foreach ($lignes as $l) {
            $nonLus[(int) $l['c']] = (int) $l['n'];
        }

        return $nonLus;
    }

    /** Nombre de fils avec un message non lu (pastille du menu, onglet « Équipe · 6 »). */
    public function filsNonLus(User $moi): int
    {
        return \count($this->nonLus($moi));
    }

    /**
     * Fils de la colonne de gauche : groupes puis messages privés, avec le
     * dernier message et les non-lus. Avec $tous (Direction, réglage permis) :
     * aussi les groupes dont elle n'est pas membre, en lecture.
     *
     * @return array{groupes: list<array<string, mixed>>, prives: list<array<string, mixed>>, lecture: list<array<string, mixed>>}
     */
    public function fils(User $moi, bool $tous = false): array
    {
        $miens = $this->conversations->findPourUtilisateur($moi);
        $autres = $tous ? $this->conversations->findGroupesSansMembre($moi) : [];
        $derniers = $this->derniers([...$miens, ...$autres]);
        $nonLus = $this->nonLus($moi);
        $fils = ['groupes' => [], 'prives' => [], 'lecture' => []];
        foreach ($miens as $c) {
            $ligne = ['conversation' => $c, 'dernier' => $derniers[$c->getId()] ?? null, 'nonLus' => $nonLus[$c->getId()] ?? 0, 'autre' => $c->estPrive() ? $c->getAutreUtilisateurPour($moi) : null];
            $fils[$c->estPrive() ? 'prives' : 'groupes'][] = $ligne;
        }
        foreach ($autres as $c) {
            $fils['lecture'][] = ['conversation' => $c, 'dernier' => $derniers[$c->getId()] ?? null, 'nonLus' => 0, 'autre' => null];
        }
        // Groupes : les permanents par défaut d'abord (Général, Atelier…), puis les plus récents ; fermés à la fin.
        $ordre = array_flip(array_keys(Groupes::PAR_DEFAUT));
        usort($fils['groupes'], static function (array $a, array $b) use ($ordre): int {
            $ca = $a['conversation'];
            $cb = $b['conversation'];

            return [$ca->estFerme(), $ordre[$ca->getParDefaut()] ?? 99, -($ca->getDateDernierMessage()?->getTimestamp() ?? 0)]
                <=> [$cb->estFerme(), $ordre[$cb->getParDefaut()] ?? 99, -($cb->getDateDernierMessage()?->getTimestamp() ?? 0)];
        });

        return $fils;
    }

    /**
     * Dernier message (pas une ligne système) de chaque conversation.
     *
     * @param list<Conversation> $conversations
     *
     * @return array<int, Message>
     */
    public function derniers(array $conversations): array
    {
        if ([] === $conversations) {
            return [];
        }
        $messages = $this->em->createQuery(
            'SELECT m, a FROM '.Message::class.' m JOIN m.auteur a WHERE m.id IN (
                SELECT MAX(m2.id) FROM '.Message::class.' m2 WHERE m2.conversation IN (:cs) AND m2.genre = :texte GROUP BY m2.conversation)'
        )->setParameters(['cs' => $conversations, 'texte' => Message::TEXTE])->getResult();
        $derniers = [];
        foreach ($messages as $m) {
            $derniers[(int) $m->getConversation()->getId()] = $m;
        }

        return $derniers;
    }

    /**
     * Messages d'un fil, les plus anciens d'abord (les 150 derniers, ou ceux après $apres).
     *
     * @return list<Message>
     */
    public function messages(Conversation $c, int $apres = 0, int $max = 150): array
    {
        $qb = $this->em->createQueryBuilder()->select('m', 'a', 'p', 'r')->from(Message::class, 'm')
            ->join('m.auteur', 'a')->leftJoin('m.pieces', 'p')->leftJoin('m.reactions', 'r')
            ->where('m.conversation = :c')->setParameter('c', $c)->orderBy('m.id', 'DESC');
        if ($apres > 0) {
            $qb->andWhere('m.id > :apres')->setParameter('apres', $apres);
        }
        $ids = $this->em->createQueryBuilder()->select('m.id')->from(Message::class, 'm')->where('m.conversation = :c')->setParameter('c', $c)
            ->andWhere($apres > 0 ? 'm.id > :apres' : '1 = 1')->orderBy('m.id', 'DESC')->setMaxResults($max);
        if ($apres > 0) {
            $ids->setParameter('apres', $apres);
        }
        $liste = array_map('intval', array_column($ids->getQuery()->getArrayResult(), 'id'));
        if ([] === $liste) {
            return [];
        }
        $messages = $qb->andWhere('m.id IN (:ids)')->setParameter('ids', $liste)->getQuery()->getResult();

        return array_reverse($messages);
    }

    /** @return list<Message> messages épinglés du fil, les plus récents d'abord */
    public function epingles(Conversation $c): array
    {
        return $this->em->createQuery('SELECT m FROM '.Message::class.' m WHERE m.conversation = :c AND m.epingleLe IS NOT NULL ORDER BY m.epingleLe DESC')
            ->setParameter('c', $c)->setMaxResults(20)->getResult();
    }

    /** La personne a ouvert la messagerie (présence « en ligne »). */
    public function noterPresence(User $moi): void
    {
        $this->cache->delete('mi_presence_'.$moi->getId());
        $this->cache->get('mi_presence_'.$moi->getId(), static function (ItemInterface $item): int {
            $item->expiresAfter(600);

            return time();
        });
    }

    /** « en ligne », « vu à 10:05 », ou null. */
    public function presence(User $u): ?string
    {
        $le = $this->cache->get('mi_presence_'.$u->getId(), static function (ItemInterface $item): int {
            $item->expiresAfter(30);

            return 0;
        });
        if ($le <= 0) {
            return null;
        }

        return time() - $le <= self::PRESENCE ? 'en ligne' : 'vu à '.(new \DateTimeImmutable('@'.$le))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('H:i');
    }
}
