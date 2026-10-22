<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Service\EspaceClient\EspaceClient;
use App\Entity\Message;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\NotesVocales;
use App\Service\Messagerie\ReglesReponse;
use App\Service\Messagerie\ResumeConversation;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Messages par commande dans les gabarits (docs/messagerie-clients.md) :
 * résumé de la conversation d'une commande ou d'un devis pour les autres
 * écrans, durée en minutes ouvrées, montants masqués.
 */
final class MessagerieExtension
{
    public function __construct(
        private readonly ResumeConversation $resume,
        private readonly Conversations $conversations,
        private readonly NotesVocales $notesVocales,
    ) {
    }

    /** Le fichier de la note vocale est là (sinon : en cours de réception par la synchronisation). */
    #[AsTwigFunction('note_vocale_presente')]
    public function notePresente(MessageClient|NoteConversation|Message $element): bool
    {
        return null !== $this->notesVocales->chemin($element);
    }

    /**
     * {% for v in notes_vocales(conversation) %} : dernières notes vocales
     * (genre « message » ou « note » interne, element, le), pour l'atelier.
     *
     * @return list<array<string, mixed>>
     */
    #[AsTwigFunction('notes_vocales')]
    public function notesVocales(ConversationClient $c, int $max = 5): array
    {
        return $this->conversations->notesVocales($c, $max);
    }

    /**
     * {% set conv = conversation_resume(commande) %} : dernier message,
     * « Répondu en 12 min », attente, urlAdmin, urlClient (null sans conversation).
     *
     * @return array<string, mixed>|null
     */
    #[AsTwigFunction('conversation_resume')]
    public function resume(Commandes|Devis $document, bool $sansMontants = false): ?array
    {
        return $this->resume->pour($document, $sansMontants);
    }

    /** Lien « Ouvrir la conversation » de l'espace client (la conversation, ou une nouvelle demande réglée sur le document). */
    #[AsTwigFunction('conversation_url_client')]
    public function urlClient(Commandes|Devis $document): string
    {
        return $this->resume->urlClient($document);
    }

    /** « CMD-D00769 · T-shirts DTF » : le numéro et ce qui est commandé ; un devis ; sinon l'objet de la question. */
    #[AsTwigFilter('conversation_titre')]
    public function titre(ConversationClient $c): string
    {
        $commande = $c->getCommande();
        if (null !== $commande && '' === trim((string) $commande->getObjet()) && !str_starts_with(EspaceClient::titre($commande), 'Commande ')) {
            return $commande->getNumero().' · '.EspaceClient::titre($commande);
        }

        return $c->getTitre();
    }

    /** 72 → « 1 h 12 » */
    #[AsTwigFilter('duree_messagerie')]
    public function duree(?int $minutes): string
    {
        return ReglesReponse::duree((int) $minutes);
    }

    #[AsTwigFilter('sans_montants')]
    public function sansMontants(?string $texte): string
    {
        return Conversations::sansMontants((string) $texte);
    }
}
