<?php

namespace App\Service\Messagerie;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ControlePrePresse;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Entity\User;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\EspaceClient\MessagesClient;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messages par commande (maquettes MS01 à MS05, docs/messagerie-clients.md) :
 * une conversation par commande, par devis ou par « question générale ».
 *
 * Côté client (site en ligne) : ouvrir une demande, écrire, confirmer la
 * clôture proposée, rouvrir pendant 7 jours, noter. Côté atelier (ERP
 * local) : répondre (le chronomètre s'arrête, le délai est noté et le
 * retard imputé à la personne responsable), notes internes avec @mention,
 * proposer la clôture, transférer, ajouter un collègue, relancer.
 */
final class Conversations
{
    /** Comptes du personnel à qui un retard peut être imputé : caisse, commercial, trésorière (pas la PAO ni les livreurs). */
    public static function imputable(User $u, ?User $tresoriere = null): bool
    {
        $roles = $u->getRoles();
        if (\in_array('ROLE_ADMIN', $roles, true) || !$u->isActif()) {
            return false;
        }
        if (null !== $tresoriere && $tresoriere === $u) {
            return true;
        }

        return \in_array('ROLE_PAIEMENT_ENCAISSER', $roles, true)
            || (\in_array('ROLE_COMMANDE', $roles, true) && !\in_array('ROLE_PREPRESSE', $roles, true));
    }

    /** Mots d'un message qui parlent de paiement ou de facture (sujet deviné quand le client n'a pas choisi). */
    private const MOTS_PAIEMENT = '/\b(factur\w*|paiement\w*|pay[eé]\w*|payer|re[cç]u|acompte|reste [àa] payer|orange money|virement|avoir|solde)\b/iu';

    /** Un montant écrit dans un message (« 16 000 F », « 80 000 FCFA ») : masqué pour qui ne voit pas les montants. */
    private const MONTANT = '/\d(?:[\d \x{00A0}\x{202F}.,]*\d)?[ \x{00A0}\x{202F}]?(?:f\s?cfa|xof|francs?|frs?|f)(?![\p{L}\p{N}])/iu';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReglesReponse $regles,
        private readonly AgentsMessagerie $agents,
        private readonly NotificationService $notifications,
        private readonly ActionsCaisse $actions,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    public function regles(): ReglesReponse
    {
        return $this->regles;
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Conversations d'un client, la plus récente d'abord.
     *
     * @return list<ConversationClient>
     */
    public function duClient(Clients $client, ?bool $cloturees = null, string $recherche = ''): array
    {
        $qb = $this->em->createQueryBuilder()->select('c')->from(ConversationClient::class, 'c')
            ->leftJoin('c.commande', 'co')->leftJoin('c.devis', 'd')
            ->where('c.client = :client')->setParameter('client', $client)
            ->orderBy('c.dernierMessageLe', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults(200);
        if (null !== $cloturees) {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $cloturees ? ConversationClient::CLOTUREE : ConversationClient::OUVERTE);
        }
        $recherche = trim(mb_substr($recherche, 0, 60));
        if ('' !== $recherche) {
            $qb->andWhere('c.objet LIKE :q OR co.numero LIKE :q OR co.objet LIKE :q OR d.numero LIKE :q OR EXISTS (SELECT m.id FROM '.MessageClient::class.' m WHERE m.conversation = c AND m.sens != :evenement AND m.texte LIKE :q)')
                ->setParameter('q', '%'.addcslashes($recherche, '%_').'%')->setParameter('evenement', MessageClient::EVENEMENT);
        }

        $liste = $qb->getQuery()->getResult();

        return self::visiblesParLeClient($liste, $this->derniers($liste));
    }

    /**
     * Une conversation ouverte par l'atelier (Conversations::demarrer) reste cachée au client tant qu'elle n'a
     * aucun message visible (hors événements « X a ajouté Y ») : ni dans sa liste, ni dans ses compteurs.
     *
     * @param list<ConversationClient>         $conversations
     * @param array<int, MessageClient>        $derniers      Conversations::derniers()
     *
     * @return list<ConversationClient>
     */
    public static function visiblesParLeClient(array $conversations, array $derniers): array
    {
        return array_values(array_filter($conversations, static fn (ConversationClient $c): bool => isset($derniers[$c->getId()])));
    }

    /** Le client voit-il cette conversation (au moins un message hors événements) ? */
    public function visibleParLeClient(ConversationClient $c): bool
    {
        return null !== $c->getId() && [] !== self::visiblesParLeClient([$c], $this->derniers([$c]));
    }

    /** @return array{ouvertes: int, cloturees: int} */
    public function compter(Clients $client): array
    {
        $lignes = $this->em->createQuery('SELECT c.statut, COUNT(c.id) AS n FROM '.ConversationClient::class.' c WHERE c.client = :client'
            .' AND EXISTS (SELECT m.id FROM '.MessageClient::class.' m WHERE m.conversation = c AND m.sens != :e) GROUP BY c.statut')
            ->setParameter('client', $client)->setParameter('e', MessageClient::EVENEMENT)->getArrayResult();
        $n = array_column($lignes, 'n', 'statut');

        return ['ouvertes' => (int) ($n[ConversationClient::OUVERTE] ?? 0), 'cloturees' => (int) ($n[ConversationClient::CLOTUREE] ?? 0)];
    }

    public function duClientParId(Clients $client, string $publicId): ?ConversationClient
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $publicId)) {
            return null;
        }
        $c = $this->em->getRepository(ConversationClient::class)->findOneBy(['publicId' => $publicId]);

        return $c instanceof ConversationClient && $c->getClient() === $client && $this->visibleParLeClient($c) ? $c : null;
    }

    public function parId(string $publicId): ?ConversationClient
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $publicId)) {
            return null;
        }
        $c = $this->em->getRepository(ConversationClient::class)->findOneBy(['publicId' => $publicId]);

        return $c instanceof ConversationClient ? $c : null;
    }

    /**
     * Messages d'une conversation, le plus ancien d'abord.
     *
     * @return list<MessageClient>
     */
    public function messages(ConversationClient $c, int $max = 300): array
    {
        return array_reverse($this->em->getRepository(MessageClient::class)->findBy(['conversation' => $c], ['creeLe' => 'DESC', 'id' => 'DESC'], $max));
    }

    /** Un message de cette conversation par son identifiant public (notes vocales). */
    public function messageParId(ConversationClient $c, string $publicId): ?MessageClient
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $publicId)) {
            return null;
        }
        $m = $this->em->getRepository(MessageClient::class)->findOneBy(['publicId' => $publicId, 'conversation' => $c]);

        return $m instanceof MessageClient ? $m : null;
    }

    /**
     * Dernières notes vocales d'une conversation, la plus récente d'abord
     * (fiche de la commande) : messages et notes internes, pour l'atelier.
     *
     * @return list<array{genre: string, element: MessageClient|NoteConversation, le: \DateTimeImmutable}>
     */
    public function notesVocales(ConversationClient $c, int $max = 5): array
    {
        $liste = [];
        foreach ($this->em->createQuery('SELECT m FROM '.MessageClient::class.' m WHERE m.conversation = :c AND m.vocaleNom IS NOT NULL ORDER BY m.creeLe DESC')->setParameter('c', $c)->setMaxResults($max)->getResult() as $m) {
            $liste[] = ['genre' => 'message', 'element' => $m, 'le' => $m->getCreeLe()];
        }
        foreach ($this->em->createQuery('SELECT n FROM '.NoteConversation::class.' n WHERE n.conversation = :c AND n.vocaleNom IS NOT NULL ORDER BY n.creeLe DESC')->setParameter('c', $c)->setMaxResults($max)->getResult() as $n) {
            $liste[] = ['genre' => 'note', 'element' => $n, 'le' => $n->getCreeLe()];
        }
        usort($liste, static fn (array $a, array $b): int => $b['le'] <=> $a['le']);

        return \array_slice($liste, 0, $max);
    }

    /** @return list<NoteConversation> */
    public function notes(ConversationClient $c): array
    {
        return $this->em->getRepository(NoteConversation::class)->findBy(['conversation' => $c], ['creeLe' => 'ASC', 'id' => 'ASC'], 200);
    }

    /**
     * Derniers messages (hors événements) de plusieurs conversations.
     *
     * @param list<ConversationClient> $conversations
     *
     * @return array<int, MessageClient> par id de conversation
     */
    public function derniers(array $conversations): array
    {
        if ([] === $conversations) {
            return [];
        }
        $ids = $this->em->createQuery('SELECT MAX(m.id) FROM '.MessageClient::class.' m WHERE m.conversation IN (:c) AND m.sens != :e GROUP BY m.conversation')
            ->setParameter('c', $conversations)->setParameter('e', MessageClient::EVENEMENT)->getSingleColumnResult();
        $par = [];
        foreach ([] !== $ids ? $this->em->getRepository(MessageClient::class)->findBy(['id' => $ids]) : [] as $m) {
            $par[$m->getConversation()->getId()] = $m;
        }

        return $par;
    }

    /** Le client a-t-il des messages de l'atelier qu'il n'a pas encore vus ? */
    public static function nonLuParClient(ConversationClient $c, ?MessageClient $dernier): bool
    {
        return null !== $dernier && $dernier->estDeLAtelier() && (null === $c->getLuClientLe() || $c->getLuClientLe() < $dernier->getCreeLe());
    }

    /** Nombre de conversations du client avec une réponse non lue (pastille du menu Messages). */
    public function nonLuesClient(Clients $client): int
    {
        $ouvertes = $this->duClient($client, false);
        $derniers = $this->derniers($ouvertes);

        return \count(array_filter($ouvertes, static fn (ConversationClient $c): bool => self::nonLuParClient($c, $derniers[$c->getId()] ?? null)));
    }

    /** La conversation d'une commande ou d'un devis : l'ouverte, sinon la plus récente. */
    public function pourDocument(Commandes|Devis $document): ?ConversationClient
    {
        $champ = $document instanceof Commandes ? 'commande' : 'devis';
        $liste = $this->em->getRepository(ConversationClient::class)->findBy([$champ => $document], ['id' => 'DESC'], 10);
        foreach ($liste as $c) {
            if ($c->estOuverte()) {
                return $c;
            }
        }

        return $liste[0] ?? null;
    }

    /** Masque les montants d'un texte (« 16 000 F » → « … ») pour un demandeur ou une lecture seule. */
    public static function sansMontants(string $texte): string
    {
        return (string) preg_replace(self::MONTANT, '…', $texte);
    }

    public static function devinerSujet(string $texte, ?Commandes $commande, ?Devis $devis): string
    {
        if (1 === preg_match(self::MOTS_PAIEMENT, $texte)) {
            return ConversationClient::SUJET_PAIEMENT;
        }

        return null !== $commande || null !== $devis ? ConversationClient::SUJET_COMMANDE : ConversationClient::SUJET_AUTRE;
    }

    // ------------------------------------------------------------------
    // Côté client (espace en ligne)
    // ------------------------------------------------------------------

    /**
     * « + Nouvelle demande » : la conversation de la commande ou du devis
     * choisi (rouverte si elle a été clôturée il y a moins de 7 jours, sinon
     * une nouvelle demande liée), ou une question générale ; puis le message.
     *
     * @throws \DomainException
     */
    public function ouvrir(CompteClient $compte, ?Commandes $commande, ?Devis $devis, ?string $objet, ?string $sujet, string $texte, \DateTimeImmutable $maintenant): ConversationClient
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans fiche client.');
        $texte = MessagesClient::verifier($texte);
        if ((null !== $commande && $commande->getClients() !== $client) || (null !== $devis && $devis->getClients() !== $client)) {
            throw new \DomainException('Choisissez une de vos commandes.');
        }
        $sujet = null !== $sujet && \array_key_exists($sujet, ConversationClient::SUJETS) ? $sujet : self::devinerSujet($texte, $commande, $devis);
        $existante = null !== $commande || null !== $devis ? $this->pourDocument($commande ?? $devis) : null;
        $precedente = null;
        if (null !== $existante && $existante->estOuverte()) {
            $conversation = $existante;
        } elseif (null !== $existante && $existante->rouvrable($maintenant, $this->regles->reouvertureJours())) {
            $conversation = $existante->rouvrir($maintenant);
        } else {
            $precedente = $existante;
            $conversation = ConversationClient::nouvelle($client, $commande, $devis, $objet, $sujet, $maintenant)->setPrecedente($precedente);
            $this->em->persist($conversation);
        }
        $this->ecrireDans($compte, $conversation, $texte, $maintenant);

        return $conversation;
    }

    /**
     * Le client écrit dans sa conversation.
     *
     * @throws \DomainException
     */
    /**
     * @param array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null $vocale note vocale déjà vérifiée (NotesVocales)
     */
    public function ecrireClient(CompteClient $compte, ConversationClient $c, string $texte, \DateTimeImmutable $maintenant, ?array $vocale = null): MessageClient
    {
        if ($c->getClient() !== $compte->getClient()) {
            throw new \DomainException('Conversation introuvable.');
        }
        $texte = MessagesClient::verifier($texte);
        if ($c->estCloturee()) {
            if (!$c->rouvrable($maintenant, $this->regles->reouvertureJours())) {
                throw new \DomainException('Cette demande est clôturée depuis plus de '.$this->regles->reouvertureJours().' jours : ouvrez une nouvelle demande, elle lui sera liée.');
            }
            $c->rouvrir($maintenant);
        }

        return $this->ecrireDans($compte, $c, $texte, $maintenant, $vocale);
    }

    /** @param array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null $vocale */
    private function ecrireDans(CompteClient $compte, ConversationClient $c, string $texte, \DateTimeImmutable $maintenant, ?array $vocale = null): MessageClient
    {
        $m = MessageClient::nouveau($c->getClient(), MessageClient::CLIENT, $texte, $compte->getNomAffiche(), $c->getCommande(), $maintenant);
        $m->setConversation($c);
        if (null !== $vocale) {
            $m->joindreNoteVocale($vocale);
        }
        // Le client a répondu : la clôture proposée n'a plus lieu d'être.
        $c->refuserCloture()->setDernierMessageLe($maintenant)->setLuClientLe($maintenant);
        $this->em->persist($m);
        if (!$this->enLigne) {
            // Au local (démonstration, essais) : comme s'il venait d'arriver du site.
            $this->recevoirDuClient($m, $maintenant);
        }
        $this->em->flush();

        return $m;
    }

    /**
     * « Oui, c'est résolu » ou « J'ai encore une question ».
     *
     * @throws \DomainException
     */
    public function repondreCloture(CompteClient $compte, ConversationClient $c, bool $resolu, \DateTimeImmutable $maintenant): void
    {
        if ($c->getClient() !== $compte->getClient() || !$c->clotureEnAttente()) {
            throw new \DomainException('Il n\'y a pas de clôture à confirmer.');
        }
        $resolu ? $c->cloturer($compte->getNomAffiche(), $maintenant) : $c->refuserCloture();
        $c->setLuClientLe($maintenant);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function rouvrir(CompteClient $compte, ConversationClient $c, \DateTimeImmutable $maintenant): void
    {
        if ($c->getClient() !== $compte->getClient() || !$c->rouvrable($maintenant, $this->regles->reouvertureJours())) {
            throw new \DomainException('Cette demande ne peut plus être rouverte : ouvrez une nouvelle demande, elle lui sera liée.');
        }
        $c->rouvrir($maintenant);
        $this->em->flush();
    }

    /** @throws \DomainException */
    public function noter(CompteClient $compte, ConversationClient $c, int $etoiles, \DateTimeImmutable $maintenant): void
    {
        if ($c->getClient() !== $compte->getClient() || !$c->estCloturee() || null !== $c->getAvis() || $etoiles < 1 || $etoiles > 5) {
            throw new \DomainException('Avis impossible.');
        }
        $c->noter($etoiles, $maintenant);
        $this->em->flush();
    }

    /** Le client ouvre la conversation : « Lu » sous les réponses (seulement s'il y a du neuf, pour ne pas tout renvoyer). */
    public function marquerLueParClient(ConversationClient $c, ?MessageClient $dernier, \DateTimeImmutable $maintenant): void
    {
        if (self::nonLuParClient($c, $dernier)) {
            $c->setLuClientLe($maintenant);
            $this->em->flush();
        }
    }

    // ------------------------------------------------------------------
    // Côté atelier (ERP local)
    // ------------------------------------------------------------------

    /**
     * Un message du client arrive au local (synchronisation) : le
     * chronomètre part, la personne responsable est choisie et prévenue.
     */
    public function recevoirDuClient(MessageClient $m, \DateTimeImmutable $maintenant): void
    {
        $c = $m->getConversation();
        if (null === $c) {
            $c = $this->conversationPour($m->getClient(), $m->getCommande(), $m->getCreeLe());
            $m->setConversation($c);
        }
        if ($c->estCloturee()) {
            $c->rouvrir($m->getCreeLe());
        }
        $c->refuserCloture();
        if (null === $c->getDernierMessageLe() || $c->getDernierMessageLe() < $m->getCreeLe()) {
            $c->setDernierMessageLe($m->getCreeLe());
        }
        if (null === $c->getAttenteDepuis() || $c->getAttenteDepuis() > $m->getCreeLe()) {
            $c->setAttenteDepuis($m->getCreeLe());
        }
        if (null === $c->getAssigneA()) {
            $c->setAssigneA($this->responsablePour($c, $maintenant));
        }
        $texte = \sprintf('Message de %s (%s) : « %s »', $m->getAuteur() ?? 'un client', $c->getTitre(), mb_strimwidth((string) preg_replace('/\s+/u', ' ', $m->getTexte()), 0, 100, '…'));
        $this->em->flush();
        $parametres = ['publicId' => (string) $c->getPublicId()];
        if (null !== $c->getAssigneA()) {
            $this->notifications->notifierUtilisateur($c->getAssigneA(), $texte, 'app_messages_clients_voir', $parametres);
        } else {
            $this->notifications->notifierRoles(['ROLE_PAIEMENT_ENCAISSER'], $texte.' (non assignée)', 'app_messages_clients_voir', $parametres);
        }
        foreach ($this->participants($c) as $u) {
            if ($u !== $c->getAssigneA()) {
                $this->notifications->notifierUtilisateur($u, $texte, 'app_messages_clients_voir', $parametres);
            }
        }
    }

    /**
     * Au local : un message du formulaire Contact du site (ou une demande de
     * rappel) devient une conversation attribuée (maquette EC08) : même
     * attribution, même chronomètre et même retard imputé que les messages
     * de l'espace client. Elle reste au local (origine « site ») : le
     * numéro tapé sur le site n'est pas vérifié, le client ne la voit pas.
     */
    public function ouvrirPourDemandeSite(Clients $client, ?Commandes $commande, string $objet, string $texte, string $auteur, \DateTimeImmutable $le, \DateTimeImmutable $maintenant): ConversationClient
    {
        $c = ConversationClient::nouvelle($client, $commande, null, mb_substr($objet, 0, ConversationClient::OBJET_MAX), null !== $commande ? ConversationClient::SUJET_COMMANDE : ConversationClient::SUJET_AUTRE, $le)
            ->setOrigine(ConversationClient::ORIGINE_SITE);
        $this->em->persist($c);
        $m = MessageClient::nouveau($client, MessageClient::CLIENT, mb_substr(trim($texte), 0, 2000) ?: $objet, mb_substr($auteur, 0, 80), $commande, $le);
        $m->setConversation($c);
        $this->em->persist($m);
        $this->recevoirDuClient($m, $maintenant);

        return $c;
    }

    /** Conversation ouverte d'un client pour cette commande (ou sans commande), créée au besoin. */
    private function conversationPour(Clients $client, ?Commandes $commande, \DateTimeImmutable $le): ConversationClient
    {
        $c = $this->em->getRepository(ConversationClient::class)->findOneBy(['client' => $client, 'commande' => $commande, 'devis' => null], ['id' => 'DESC']);
        if (!$c instanceof ConversationClient) {
            $c = ConversationClient::nouvelle($client, $commande, null, null, null !== $commande ? ConversationClient::SUJET_COMMANDE : ConversationClient::SUJET_AUTRE, $le);
            $this->em->persist($c);
        }

        return $c;
    }

    /**
     * Qui doit répondre (règles MS04) : paiement ou facture → la trésorière
     * choisie ; sinon l'agent assigné à la commande ou au devis ; une
     * question générale sans agent reste « non assignée » (la première
     * personne de caisse qui répond la prend).
     */
    public function responsablePour(ConversationClient $c, \DateTimeImmutable $maintenant): ?User
    {
        $tresoriere = $this->regles->tresoriere();
        if (ConversationClient::SUJET_PAIEMENT === $c->getSujet()) {
            if (null !== $tresoriere) {
                return $tresoriere;
            }
            $caisse = $this->actions->enPoste($maintenant)['personnes'];
            if ([] !== $caisse) {
                return $caisse[0];
            }
        }
        $agent = $c->getCommande()?->getAgents() ?? $c->getDevis()?->getAgents();

        return null !== $agent && self::imputable($agent, $tresoriere) ? $agent : null;
    }

    /**
     * L'atelier répond : le chronomètre s'arrête, le délai est noté sur la
     * réponse (« répondu en 4 min ») et un retard est imputé à la personne
     * responsable (−5 points par réponse en retard).
     *
     * @param array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null $vocale note vocale déjà vérifiée (NotesVocales)
     *
     * @throws \DomainException
     */
    public function repondre(ConversationClient $c, User $par, string $texte, ?ControlePrePresse $bat, ?Factures $facture, \DateTimeImmutable $maintenant, ?array $vocale = null): MessageClient
    {
        if ($this->enLigne) {
            throw new \DomainException('Les réponses partent de l\'ERP de l\'imprimerie.');
        }
        $texte = MessagesClient::verifier($texte);
        if (null !== $bat && (null === $c->getCommande() || $bat->getCommandeDetail()?->getCommande() !== $c->getCommande())) {
            $bat = null;
        }
        if (null !== $facture && ($facture->getCommande()?->getClients() ?? null) !== $c->getClient()) {
            $facture = null;
        }
        $agent = $this->agents->pour($par, $maintenant);
        $m = MessageClient::nouveau($c->getClient(), MessageClient::ATELIER, $texte, $par->getEmploye()?->getNomPublic() ?? MessageClient::NOM_ATELIER, $c->getCommande(), $maintenant);
        $m->setConversation($c)->setAgent($agent)->setBat($bat)->setFacture($facture)->setEcritPar($par);
        if (null !== $vocale) {
            $m->joindreNoteVocale($vocale);
        }
        $tresoriere = $this->regles->tresoriere();
        if (null === $c->getAssigneA() && self::imputable($par, $tresoriere)) {
            // Non assignée : la personne de caisse qui répond la prend.
            $c->setAssigneA($par);
        }
        if (null !== $c->getAttenteDepuis()) {
            $premiere = null === $c->getPremiereReponseLe();
            $mesure = $this->regles->mesurer($c->getAttenteDepuis(), $maintenant, $premiere);
            $responsable = $c->getAssigneA();
            $m->noterDelai($mesure['attente'], $mesure['cible'], null !== $responsable && self::imputable($responsable, $tresoriere) ? $responsable : null, $premiere, $this->regles->penalite());
            $c->setPremiereReponseLe($c->getPremiereReponseLe() ?? $maintenant);
        }
        $c->setAttenteDepuis(null)->setDernierMessageLe($maintenant)->setLuAtelierLe($maintenant);
        if ($c->estCloturee()) {
            $c->rouvrir($maintenant);
        }
        $this->em->persist($m);
        foreach ($this->em->getRepository(MessageClient::class)->findBy(['conversation' => $c, 'sens' => MessageClient::CLIENT, 'traiteLe' => null]) as $aTraiter) {
            $aTraiter->traiter($maintenant);
        }
        $this->em->flush();

        return $m;
    }

    /**
     * L'atelier ouvre la conversation d'une commande ou d'un devis (DT02,
     * DT03, « Écrire au client ») : l'ouverte si elle existe, sinon une
     * nouvelle (liée à la précédente), assignée à qui l'ouvre s'il est
     * imputable, sinon à l'agent du document. Pas de message : le premier
     * part avec « Envoyer », sans chronomètre (le client n'attend rien).
     *
     * @throws \DomainException
     */
    public function demarrer(Commandes|Devis $document, User $par, \DateTimeImmutable $maintenant): ConversationClient
    {
        if ($this->enLigne) {
            throw new \DomainException('Les réponses partent de l\'ERP de l\'imprimerie.');
        }
        $existante = $this->pourDocument($document);
        if (null !== $existante && $existante->estOuverte()) {
            return $existante;
        }
        $client = $document->getClients() ?? throw new \DomainException('Document sans client.');
        $commande = $document instanceof Commandes ? $document : null;
        $devis = $document instanceof Devis ? $document : null;
        $c = ConversationClient::nouvelle($client, $commande, $devis, null, ConversationClient::SUJET_COMMANDE, $maintenant)->setPrecedente($existante);
        $tresoriere = $this->regles->tresoriere();
        $agent = $document->getAgents();
        $c->setAssigneA(self::imputable($par, $tresoriere) ? $par : (null !== $agent && self::imputable($agent, $tresoriere) ? $agent : null));
        if ($c->getAssigneA() !== $par) {
            $c->ajouterParticipant($par);
        }
        $c->setDernierMessageLe($maintenant)->setLuAtelierLe($maintenant);
        $this->em->persist($c);
        $this->em->flush();

        return $c;
    }

    /**
     * Note interne : invisible du client, sans effet sur le chronomètre.
     * « @Kadiatou » prévient la personne nommée (prénom ou identifiant).
     *
     * @param array{nom: string, type: string, taille: int, duree: int, empreinte: string}|null $vocale note vocale déjà vérifiée (NotesVocales)
     *
     * @throws \DomainException
     */
    public function noteInterne(ConversationClient $c, User $par, string $texte, \DateTimeImmutable $maintenant, ?array $vocale = null): NoteConversation
    {
        $texte = trim($texte);
        if ('' === $texte) {
            throw new \DomainException('Écrivez la note.');
        }
        $mentionnes = $this->mentions($texte, $par);
        $note = new NoteConversation($c, $par, $texte, array_map(static fn (User $u): int => (int) $u->getId(), $mentionnes), $maintenant);
        if (null !== $vocale) {
            $note->joindreNoteVocale($vocale);
        }
        $this->em->persist($note);
        foreach ($mentionnes as $u) {
            $this->notifications->notifierUtilisateur($u, \sprintf('%s vous cite dans la conversation %s : « %s »', self::nom($par), $c->getTitre(), mb_strimwidth($texte, 0, 100, '…')), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
        }
        $this->em->flush();

        return $note;
    }

    /**
     * Comptes cités dans une note (« @Kadiatou », « @demo.kadiatou »).
     *
     * @return list<User>
     */
    public function mentions(string $texte, ?User $sauf = null): array
    {
        if (!preg_match_all('/@([\p{L}][\p{L}\p{N}._\-]{1,40})/u', $texte, $m)) {
            return [];
        }
        $noms = array_map(static fn (string $n): string => mb_strtolower($n), $m[1]);
        $trouves = [];
        foreach ($this->em->getRepository(User::class)->findBy(['actif' => true]) as $u) {
            $prenom = mb_strtolower((string) $u->getEmploye()?->getPrenom());
            if ($u !== $sauf && (\in_array(mb_strtolower((string) $u->getUsername()), $noms, true) || ('' !== $prenom && \in_array($prenom, $noms, true)))) {
                $trouves[$u->getId()] = $u;
            }
        }

        return array_values($trouves);
    }

    /** « Clôturer la demande » : proposée au client, qui confirme. */
    public function proposerCloture(ConversationClient $c, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$c->estOuverte()) {
            return;
        }
        $c->proposerCloture(self::nom($par), $maintenant);
        $this->em->flush();
    }

    /** Transférer à un collègue : il devient responsable (le retard en cours lui revient). */
    public function transferer(ConversationClient $c, User $vers, User $par, \DateTimeImmutable $maintenant): void
    {
        $c->setAssigneA($vers);
        $this->em->persist(new NoteConversation($c, $par, \sprintf('%s a transféré la demande à %s.', self::nom($par), self::nom($vers)), [], $maintenant));
        if ($vers !== $par) {
            $this->notifications->notifierUtilisateur($vers, \sprintf('%s vous transfère la conversation %s (%s).', self::nom($par), $c->getTitre(), $c->getClient()?->getNomComplet()), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
        }
        $this->em->flush();
    }

    /** Ajouter un collègue : il voit la conversation et peut répondre ; le client le voit dans le fil. */
    public function ajouterParticipant(ConversationClient $c, User $qui, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$c->ajouterParticipant($qui)) {
            return;
        }
        $agent = $this->agents->pour($qui, $maintenant);
        $texte = \sprintf('%s a ajouté %s%s à la conversation', self::prenom($par), $agent->getNom(), null !== $agent->getMetier() ? ' ('.$agent->getMetier().')' : '');
        $e = MessageClient::nouveau($c->getClient(), MessageClient::EVENEMENT, $texte, null, $c->getCommande(), $maintenant);
        $e->setConversation($c)->setAgent($agent);
        $this->em->persist($e);
        $this->notifications->notifierUtilisateur($qui, \sprintf('%s vous ajoute à la conversation %s (%s).', self::nom($par), $c->getTitre(), $c->getClient()?->getNomComplet()), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
        $this->em->flush();
    }

    /** Relancer (supervision) : la personne responsable est prévenue. */
    public function relancer(ConversationClient $c, User $par, \DateTimeImmutable $maintenant): bool
    {
        $qui = $c->getAssigneA();
        $c->setRelanceeLe($maintenant);
        if (null !== $qui) {
            $this->notifications->notifierUtilisateur($qui, \sprintf('Relance de %s : %s attend votre réponse (%s).', self::nom($par), $c->getClient()?->getNomComplet(), $c->getTitre()), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
        } else {
            $this->notifications->notifierRoles(['ROLE_PAIEMENT_ENCAISSER'], \sprintf('Relance : %s attend une réponse (%s, non assignée).', $c->getClient()?->getNomComplet(), $c->getTitre()), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
        }
        $this->em->flush();

        return null !== $qui;
    }

    /** L'agent ouvre la conversation : « Lu » sous les messages du client (seulement s'il y a du neuf). */
    public function marquerLueParAtelier(ConversationClient $c, \DateTimeImmutable $maintenant): void
    {
        if ($this->enLigne) {
            return;
        }
        $dernier = $this->derniers([$c])[$c->getId()] ?? null;
        if (null !== $dernier && $dernier->estDuClient() && (null === $c->getLuAtelierLe() || $c->getLuAtelierLe() < $dernier->getCreeLe())) {
            $c->setLuAtelierLe($maintenant);
            $this->em->flush();
        }
    }

    /** @return list<User> */
    public function participants(ConversationClient $c): array
    {
        $ids = $c->getParticipants();

        return [] === $ids ? [] : $this->em->getRepository(User::class)->findBy(['id' => $ids]);
    }

    // ------------------------------------------------------------------
    // Tâche planifiée (ERP local)
    // ------------------------------------------------------------------

    /**
     * Range les anciens messages dans leur conversation, prévient la
     * direction au-delà de l'escalade, clôture les demandes restées sans
     * réponse du client.
     *
     * @return array{rangees: int, escaladees: int, cloturees: int}
     */
    public function actualiser(\DateTimeImmutable $maintenant): array
    {
        if ($this->enLigne) {
            return ['rangees' => 0, 'escaladees' => 0, 'cloturees' => 0];
        }
        $bilan = ['rangees' => $this->rattacherOrphelins($maintenant), 'escaladees' => 0, 'cloturees' => 0];

        $escalade = $this->regles->escalade();
        if ($escalade > 0) {
            foreach ($this->enAttente() as $c) {
                if (null === $c->getEscaladeeLe() && $this->regles->heures()->ecoule($c->getAttenteDepuis(), $maintenant) >= $escalade) {
                    $c->setEscaladeeLe($maintenant);
                    $this->notifications->notifierRoles(['ROLE_ADMIN'], \sprintf('%s attend une réponse depuis %s (%s, %s).', $c->getClient()?->getNomComplet(), ReglesReponse::duree($this->regles->heures()->ecoule($c->getAttenteDepuis(), $maintenant)), $c->getTitre(), null !== $c->getAssigneA() ? 'assignée à '.self::nom($c->getAssigneA()) : 'non assignée'), 'app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]);
                    ++$bilan['escaladees'];
                }
            }
        }

        $heures = $this->regles->clotureAutoHeures();
        if ($heures > 0) {
            $limite = $maintenant->modify('-'.$heures.' hours');
            /** @var list<ConversationClient> $aClore */
            $aClore = $this->em->createQuery('SELECT c FROM '.ConversationClient::class.' c WHERE c.statut = :o AND c.attenteDepuis IS NULL AND c.dernierMessageLe < :limite')
                ->setParameter('o', ConversationClient::OUVERTE)->setParameter('limite', $limite)->getResult();
            $derniers = $this->derniers($aClore);
            foreach ($aClore as $c) {
                $d = $derniers[$c->getId()] ?? null;
                if (null !== $d && $d->estDeLAtelier() && $d->getCreeLe() < $limite) {
                    $c->cloturer(ConversationClient::CLOTURE_AUTO, $maintenant);
                    ++$bilan['cloturees'];
                }
            }
        }
        $this->em->flush();

        return $bilan;
    }

    /** @return list<ConversationClient> */
    public function enAttente(): array
    {
        return $this->em->createQuery('SELECT c, cl FROM '.ConversationClient::class.' c JOIN c.client cl WHERE c.statut = :o AND c.attenteDepuis IS NOT NULL ORDER BY c.attenteDepuis ASC')
            ->setParameter('o', ConversationClient::OUVERTE)->getResult();
    }

    /**
     * Messages écrits avant les conversations : rangés par client et par
     * commande. Une conversation dont le dernier mot est à l'atelier depuis
     * plus que la clôture automatique est rangée clôturée.
     */
    public function rattacherOrphelins(\DateTimeImmutable $maintenant): int
    {
        /** @var list<MessageClient> $orphelins */
        $orphelins = $this->em->createQuery('SELECT m FROM '.MessageClient::class.' m WHERE m.conversation IS NULL ORDER BY m.creeLe ASC, m.id ASC')->setMaxResults(2000)->getResult();
        $touchees = [];
        foreach ($orphelins as $m) {
            $c = $this->conversationPour($m->getClient(), $m->getCommande(), $m->getCreeLe());
            $m->setConversation($c);
            if (null === $c->getDernierMessageLe() || $c->getDernierMessageLe() < $m->getCreeLe()) {
                $c->setDernierMessageLe($m->getCreeLe());
            }
            if ($m->estDuClient() && null === $m->getTraiteLe()) {
                $c->setAttenteDepuis($c->getAttenteDepuis() ?? $m->getCreeLe());
            } elseif ($m->estDeLAtelier()) {
                $c->setAttenteDepuis(null)->setPremiereReponseLe($c->getPremiereReponseLe() ?? $m->getCreeLe());
                if (null !== $m->getEcritPar()) {
                    $m->setAgent($this->agents->pour($m->getEcritPar(), $maintenant));
                }
            }
            $touchees[spl_object_id($c)] = $c;
            $this->em->flush();
        }
        foreach ($touchees as $c) {
            if (null === $c->getAssigneA()) {
                $c->setAssigneA($this->responsablePour($c, $maintenant));
            }
        }
        $this->em->flush();

        return \count($orphelins);
    }

    public static function nom(?User $u): string
    {
        if (null === $u) {
            return 'personne';
        }
        $e = $u->getEmploye();

        return null !== $e && '' !== trim($e->getNomComplet()) ? $e->getNomComplet() : (string) $u->getUsername();
    }

    public static function prenom(?User $u): string
    {
        $p = trim((string) $u?->getEmploye()?->getPrenom());

        return '' !== $p ? $p : self::nom($u);
    }
}
