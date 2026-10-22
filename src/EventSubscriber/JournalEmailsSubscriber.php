<?php

namespace App\EventSubscriber;

use App\Entity\EnvoiMessage;
use App\Service\Messagerie\JournalEnvois;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Historique des envois, côté e-mail : un seul point pour tous les e-mails
 * de l'application (codes de connexion, notifications, rappels, campagnes,
 * rapport de la semaine), qu'ils passent par le Mailer ou directement par
 * le transport. Une ligne par destinataire, au moment où le transport
 * envoie (ou échoue).
 *
 * La sorte du message vient de l'en-tête JournalEnvois::ENTETE_TYPE posé
 * par l'appelant ; il est retiré avant l'envoi. Sans messagerie réglée
 * (MAILER_DSN null://, développement), la ligne dit « Non envoyé ».
 */
final class JournalEmailsSubscriber implements EventSubscriberInterface
{
    /** @var \WeakMap<RawMessage, array{type: string, transport: string}> */
    private \WeakMap $enCours;

    public function __construct(private readonly JournalEnvois $journal)
    {
        $this->enCours = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Après les autres écouteurs (rendu des gabarits, enveloppe) : le message est celui qui part.
            MessageEvent::class => ['avantEnvoi', -1024],
            SentMessageEvent::class => 'envoye',
            FailedMessageEvent::class => 'echec',
        ];
    }

    public function avantEnvoi(MessageEvent $event): void
    {
        // Le Mailer annonce d'abord le message mis en file (copie) : seul l'envoi par le transport compte.
        if ($event->isQueued()) {
            return;
        }
        $message = $event->getMessage();
        $this->enCours[$message] = ['type' => self::retirerType($message), 'transport' => $event->getTransport()];
    }

    public function envoye(SentMessageEvent $event): void
    {
        $envoye = $event->getMessage();
        $message = $envoye->getOriginalMessage();
        $info = $this->enCours[$message] ?? ['type' => self::retirerType($message), 'transport' => ''];
        unset($this->enCours[$message]);
        $simule = str_starts_with($info['transport'], 'null://');

        foreach ($envoye->getEnvelope()->getRecipients() as $adresse) {
            $this->journal->noter(
                EnvoiMessage::EMAIL,
                $info['type'],
                $adresse->getAddress(),
                $simule ? EnvoiMessage::NON_ENVOYE : EnvoiMessage::ENVOYE,
                $simule ? 'Messagerie e-mail non réglée (MAILER_DSN) : rien n\'est parti.' : null,
                1,
                self::sujet($message),
            );
        }
    }

    public function echec(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();
        $info = $this->enCours[$message] ?? ['type' => self::retirerType($message), 'transport' => ''];
        unset($this->enCours[$message]);
        $erreur = $event->getError()->getMessage();

        foreach (self::destinataires($message) as $adresse) {
            $this->journal->noter(EnvoiMessage::EMAIL, $info['type'], $adresse, EnvoiMessage::ECHEC, '' !== $erreur ? $erreur : 'Envoi refusé.', 0, self::sujet($message));
        }
    }

    /** Lit la sorte posée par l'appelant et retire l'en-tête du message qui part. */
    private static function retirerType(RawMessage $message): string
    {
        if (!$message instanceof Message || !$message->getHeaders()->has(JournalEnvois::ENTETE_TYPE)) {
            return 'autre';
        }
        $type = trim($message->getHeaders()->get(JournalEnvois::ENTETE_TYPE)?->getBodyAsString() ?? '');
        $message->getHeaders()->remove(JournalEnvois::ENTETE_TYPE);

        return isset(EnvoiMessage::TYPES[$type]) ? $type : 'autre';
    }

    private static function sujet(RawMessage $message): ?string
    {
        return $message instanceof Email ? $message->getSubject() : null;
    }

    /** @return list<string> */
    private static function destinataires(RawMessage $message): array
    {
        if (!$message instanceof Email) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (Address $a): string => $a->getAddress(), [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()])));
    }
}
