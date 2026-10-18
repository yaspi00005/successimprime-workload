<?php

namespace App\Service\Messagerie;

use App\Entity\Commandes;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\MessageClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Résumé de la conversation d'une commande ou d'un devis, pour les autres
 * écrans (carte « Conversation » du détail de commande admin, « Dernier
 * message » du devis côté client) : dernier message, qui l'a écrit, délai
 * de la dernière réponse (« Répondu en 12 min »), attente en cours et
 * adresses de la conversation (admin et espace client).
 *
 * Twig : conversation_resume(commande_ou_devis) (MessagerieExtension).
 * Le texte n'est jamais coupé ici ; pour un client sans les montants,
 * passer $sansMontants = true.
 */
final class ResumeConversation
{
    public function __construct(
        private readonly Conversations $conversations,
        private readonly DelaisReponse $delais,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array{
     *   conversation: ConversationClient,
     *   statut: string,
     *   dernier: ?array{texte: string, auteur: string, deLAtelier: bool, le: \DateTimeImmutable, photo: ?string, initiales: string},
     *   delaiMinutes: ?int,
     *   delaiTexte: ?string,
     *   enAttente: bool,
     *   attenteMinutes: ?int,
     *   enRetard: bool,
     *   messages: int,
     *   urlAdmin: string,
     *   urlClient: string
     * }|null null quand il n'y a pas encore de conversation
     */
    public function pour(Commandes|Devis $document, bool $sansMontants = false, ?\DateTimeImmutable $maintenant = null): ?array
    {
        $c = $this->conversations->pourDocument($document);
        if (null === $c) {
            return null;
        }
        $maintenant ??= new \DateTimeImmutable();
        $dernier = $this->conversations->derniers([$c])[$c->getId()] ?? null;
        $delai = $this->delais->dernierDelai($c);
        $chrono = $this->delais->chrono($c, $maintenant);

        return [
            'conversation' => $c,
            'statut' => $c->estCloturee() ? 'Clôturée' : (null !== $chrono ? 'Réponse attendue' : 'Ouverte'),
            'dernier' => null !== $dernier ? self::message($dernier, $sansMontants) : null,
            'delaiMinutes' => $delai,
            'delaiTexte' => null !== $delai ? 'Répondu en '.ReglesReponse::duree($delai) : null,
            'enAttente' => null !== $chrono,
            'attenteMinutes' => $chrono['attente'] ?? null,
            'enRetard' => null !== $chrono && $chrono['retard'] > 0,
            'messages' => \count($this->conversations->messages($c)),
            'urlAdmin' => $this->urls->generate('app_messages_clients_voir', ['publicId' => (string) $c->getPublicId()]),
            'urlClient' => null !== $dernier ? $this->urls->generate('app_espace_message', ['id' => (string) $c->getPublicId()]) : $this->urlClient($document),
        ];
    }

    /**
     * Adresse pour écrire au sujet d'un document (bouton « Ouvrir la
     * conversation ») : la conversation si elle existe, sinon le formulaire
     * « Nouvelle demande » déjà réglé sur ce document.
     */
    public function urlClient(Commandes|Devis $document): string
    {
        $c = $this->conversations->pourDocument($document);
        if (null !== $c && $this->conversations->visibleParLeClient($c)) {
            return $this->urls->generate('app_espace_message', ['id' => (string) $c->getPublicId()]);
        }

        return $this->urls->generate('app_espace_messages', ['nouvelle' => 1, 'document' => ($document instanceof Devis ? 'd:' : 'c:').$document->getPublicId()]);
    }

    /** @return array{texte: string, auteur: string, deLAtelier: bool, le: \DateTimeImmutable, photo: ?string, initiales: string} */
    public static function message(MessageClient $m, bool $sansMontants): array
    {
        $s = $m->getSignature();
        $texte = $sansMontants ? Conversations::sansMontants($m->getTexte()) : $m->getTexte();

        return [
            'texte' => $texte,
            'auteur' => null !== $s ? $s['nom'] : ($m->getAuteur() ?? 'Le client'),
            'deLAtelier' => $m->estDeLAtelier(),
            'le' => $m->getCreeLe(),
            'photo' => $s['photo'] ?? null,
            'initiales' => $s['initiales'] ?? mb_strtoupper(mb_substr((string) $m->getAuteur(), 0, 1)),
        ];
    }
}
