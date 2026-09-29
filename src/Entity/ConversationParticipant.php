<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conversation_participants')]
#[ORM\UniqueConstraint(name: 'uniq_conversation_utilisateur', columns: ['conversation_id', 'utilisateur_id'])]
class ConversationParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $utilisateur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDerniereLecture = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getDateDerniereLecture(): ?\DateTimeImmutable
    {
        return $this->dateDerniereLecture;
    }

    public function marquerLue(): static
    {
        $this->dateDerniereLecture = new \DateTimeImmutable();

        return $this;
    }

    /**
     * True si la conversation a reçu un message depuis la dernière
     * lecture de cet utilisateur.
     */
    public function aDesMessagesNonLus(): bool
    {
        $dernierMessage = $this->conversation?->getDateDernierMessage();

        if ($dernierMessage === null) {
            return false;
        }

        return $this->dateDerniereLecture === null
            || $this->dateDerniereLecture < $dernierMessage;
    }
}
