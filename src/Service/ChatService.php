<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversationRepository,
    ) {
    }

    /**
     * Récupère la conversation directe existante entre les deux
     * utilisateurs, ou en crée une nouvelle.
     */
    public function demarrerConversationDirecte(User $initiateur, User $destinataire): Conversation
    {
        if ($initiateur === $destinataire) {
            throw new \InvalidArgumentException('Impossible de démarrer une conversation avec soi-même.');
        }

        $conversation = $this->conversationRepository->trouverConversationDirecte($initiateur, $destinataire);

        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = new Conversation();
        $conversation
            ->ajouterParticipant($initiateur)
            ->ajouterParticipant($destinataire);

        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    public function envoyerMessage(
        Conversation $conversation,
        User $auteur,
        string $contenu,
        ?string $pieceJointeFichier = null,
        ?string $pieceJointeNomOriginal = null,
    ): Message {
        $contenu = trim($contenu);

        if ($contenu === '' && $pieceJointeFichier === null) {
            throw new \InvalidArgumentException('Le message ne peut pas être vide.');
        }

        if (!$conversation->aPourParticipant($auteur)) {
            throw new \LogicException('Vous ne participez pas à cette conversation.');
        }

        $message = (new Message())
            ->setConversation($conversation)
            ->setAuteur($auteur)
            ->setContenu($contenu);

        if ($pieceJointeFichier !== null && $pieceJointeNomOriginal !== null) {
            $message->definirPieceJointe($pieceJointeFichier, $pieceJointeNomOriginal);
        }

        $conversation->setDateDernierMessage(new \DateTimeImmutable());

        $conversation->getParticipantPour($auteur)?->marquerLue();

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }

    public function marquerConversationLue(Conversation $conversation, User $utilisateur): void
    {
        $participant = $conversation->getParticipantPour($utilisateur);

        if ($participant === null || !$participant->aDesMessagesNonLus()) {
            return;
        }

        $participant->marquerLue();
        $this->entityManager->flush();
    }

    /**
     * Nombre de conversations de cet utilisateur contenant au moins
     * un message non lu.
     */
    public function compterConversationsNonLues(User $utilisateur): int
    {
        $total = 0;

        foreach ($this->conversationRepository->findPourUtilisateur($utilisateur) as $conversation) {
            if ($conversation->getParticipantPour($utilisateur)?->aDesMessagesNonLus()) {
                $total++;
            }
        }

        return $total;
    }

    /**
     * Aperçu des conversations avec un message non lu, les plus
     * récemment actives en premier, pour la cloche de messagerie.
     *
     * @return Conversation[]
     */
    public function conversationsNonLuesApercu(User $utilisateur, int $limite = 5): array
    {
        $conversations = array_values(array_filter(
            $this->conversationRepository->findPourUtilisateur($utilisateur),
            static fn (Conversation $conversation): bool => $conversation->getParticipantPour($utilisateur)?->aDesMessagesNonLus() ?? false
        ));

        return array_slice($conversations, 0, $limite);
    }
}
