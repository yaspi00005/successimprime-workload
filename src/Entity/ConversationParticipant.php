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

    /** Responsable du groupe : écrit dans un groupe d'annonces, ajoute et retire des membres. */
    #[ORM\Column(options: ['default' => false])]
    private bool $admin = false;

    /** Dernier message lu (non-lus par fil : les messages des autres après celui-ci). */
    #[ORM\Column(options: ['default' => 0])]
    private int $dernierLuId = 0;

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

    public function marquerLue(?int $jusquA = null): static
    {
        $this->dateDerniereLecture = new \DateTimeImmutable();
        if (null !== $jusquA && $jusquA > $this->dernierLuId) {
            $this->dernierLuId = $jusquA;
        }

        return $this;
    }

    public function getDernierLuId(): int
    {
        return $this->dernierLuId;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;

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
