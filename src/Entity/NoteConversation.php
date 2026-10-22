<?php

namespace App\Entity;

use App\Entity\Trait\NoteVocaleTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note interne dans une conversation avec un client (maquette MS03) :
 * invisible du client, jamais synchronisée (table absente de
 * CatalogueSynchro), elle n'arrête pas le chronomètre de réponse. Une
 * @mention (« @Kadiatou ») prévient le collègue nommé. Elle peut être une
 * note vocale (NoteVocaleTrait), elle aussi réservée à l'équipe.
 */
#[ORM\Entity]
#[ORM\Table(name: 'note_conversation')]
#[ORM\Index(name: 'idx_note_conversation', columns: ['conversation_id', 'cree_le'])]
class NoteConversation
{
    /** Note vocale interne : reste au local comme la note. */
    use NoteVocaleTrait;

    public const TEXTE_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ConversationClient $conversation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    /** Comptes mentionnés (identifiants). @var list<int> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $mentions = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** @param list<int> $mentions */
    public function __construct(ConversationClient $conversation, ?User $auteur, string $texte, array $mentions, \DateTimeImmutable $le)
    {
        $this->conversation = $conversation;
        $this->auteur = $auteur;
        $this->texte = mb_substr(trim($texte), 0, self::TEXTE_MAX);
        $this->mentions = [] !== $mentions ? array_values($mentions) : null;
        $this->creeLe = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): ?ConversationClient { return $this->conversation; }
    public function getAuteur(): ?User { return $this->auteur; }
    public function getTexte(): string { return $this->texte; }
    /** @return list<int> */
    public function getMentions(): array { return $this->mentions ?? []; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
}
