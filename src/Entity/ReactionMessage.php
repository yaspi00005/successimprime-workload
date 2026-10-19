<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Réaction emoji à un message de l'équipe (👍 2), une par personne et par emoji. */
#[ORM\Entity]
#[ORM\Table(name: 'messages_chat_reaction')]
#[ORM\UniqueConstraint(name: 'uniq_reaction_message_utilisateur_emoji', columns: ['message_id', 'utilisateur_id', 'emoji'])]
class ReactionMessage
{
    /** Emojis proposés (les seuls acceptés). */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '🙏', '✅', '👀', '🔥'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $utilisateur = null;

    #[ORM\Column(length: 16)]
    private string $emoji = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct(Message $message, User $utilisateur, string $emoji)
    {
        $this->message = $message;
        $this->utilisateur = $utilisateur;
        $this->emoji = $emoji;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
