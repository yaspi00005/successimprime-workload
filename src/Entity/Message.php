<?php

namespace App\Entity;

use App\Entity\Trait\NoteVocaleTrait;
use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Message de la messagerie de l'équipe (docs/messagerie-interne.md) :
 * texte avec @mentions et commandes citées (« CM-0182 » devient une
 * carte), fichiers et photos (PieceJointeMessage), note vocale, réponse
 * à un autre message, épingle, réactions.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages_chat')]
#[ORM\Index(name: 'idx_message_chat_conversation', columns: ['conversation_id', 'id'])]
#[ORM\HasLifecycleCallbacks]
class Message
{
    use NoteVocaleTrait;

    public const TEXTE = 'texte';
    public const SYSTEME = 'systeme';

    /** Longueur d'un message écrit. */
    public const LONGUEUR_MAX = 4000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $auteur = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $contenu = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEnvoi = null;

    /** « texte » (écrit, fichiers, note vocale) ou « systeme » (groupe créé, membre ajouté, groupe fermé). */
    #[ORM\Column(length: 10, options: ['default' => self::TEXTE])]
    private string $genre = self::TEXTE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $reponseA = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $epingleLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $epinglePar = null;

    /** @var list<int> personnes citées (@Oumar), prévenues par une notification */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $mentions = [];

    /** @var list<int> commandes citées (« CM-0182 »), montrées en carte */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $commandes = [];

    /** @var Collection<int, PieceJointeMessage> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: PieceJointeMessage::class, cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $pieces;

    /** @var Collection<int, ReactionMessage> */
    #[ORM\OneToMany(mappedBy: 'message', targetEntity: ReactionMessage::class)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $reactions;

    public function __construct()
    {
        $this->pieces = new ArrayCollection();
        $this->reactions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDateEnvoi(): void
    {
        $this->dateEnvoi ??= new \DateTimeImmutable();
    }

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

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function setAuteur(?User $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    /** Pour NoteVocaleTrait (libellé automatique d'une note vocale sans texte). */
    public function getTexte(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = mb_substr(trim($contenu), 0, self::LONGUEUR_MAX);

        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }

    public function setDateEnvoi(\DateTimeImmutable $le): static
    {
        $this->dateEnvoi = $le;

        return $this;
    }

    public function getGenre(): string
    {
        return $this->genre;
    }

    public function estSysteme(): bool
    {
        return self::SYSTEME === $this->genre;
    }

    public function setGenre(string $genre): static
    {
        $this->genre = self::SYSTEME === $genre ? self::SYSTEME : self::TEXTE;

        return $this;
    }

    public function getReponseA(): ?Message
    {
        return $this->reponseA;
    }

    public function setReponseA(?Message $reponseA): static
    {
        $this->reponseA = $reponseA;

        return $this;
    }

    public function estEpingle(): bool
    {
        return null !== $this->epingleLe;
    }

    public function getEpingleLe(): ?\DateTimeImmutable
    {
        return $this->epingleLe;
    }

    public function getEpinglePar(): ?User
    {
        return $this->epinglePar;
    }

    public function epingler(?User $par, ?\DateTimeImmutable $le): static
    {
        $this->epinglePar = null === $le ? null : $par;
        $this->epingleLe = $le;

        return $this;
    }

    /** @return list<int> */
    public function getMentions(): array
    {
        return array_values(array_unique(array_map('intval', $this->mentions)));
    }

    /** @param list<int> $ids */
    public function setMentions(array $ids): static
    {
        $this->mentions = array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    /** @return list<int> */
    public function getCommandes(): array
    {
        return array_values(array_unique(array_map('intval', $this->commandes)));
    }

    /** @param list<int> $ids */
    public function setCommandes(array $ids): static
    {
        $this->commandes = \array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 5);

        return $this;
    }

    /** @return Collection<int, PieceJointeMessage> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function ajouterPiece(PieceJointeMessage $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
            $piece->setMessage($this);
        }

        return $this;
    }

    /** @return Collection<int, ReactionMessage> */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    /**
     * Réactions regroupées : emoji => [nombre, noms, moi].
     *
     * @return array<string, array{nombre: int, noms: list<string>, moi: bool}>
     */
    public function reactionsPour(?User $moi): array
    {
        $groupes = [];
        foreach ($this->reactions as $r) {
            $e = $r->getEmoji();
            $groupes[$e] ??= ['nombre' => 0, 'noms' => [], 'moi' => false];
            ++$groupes[$e]['nombre'];
            $groupes[$e]['noms'][] = Conversation::nomDe($r->getUtilisateur());
            if (null !== $moi && $r->getUtilisateur()?->getId() === $moi->getId()) {
                $groupes[$e]['moi'] = true;
            }
        }

        return $groupes;
    }

    /** Aperçu d'une ligne (liste des fils, notification, citation). */
    public function apercu(int $longueur = 80): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $this->contenu));
        if ('' === $texte && $this->aUneNoteVocale()) {
            $texte = self::libelleVocale((int) $this->getVocaleDuree());
        }
        if ('' === $texte && !$this->pieces->isEmpty()) {
            $n = $this->pieces->count();
            $premiere = $this->pieces->first();
            $texte = 1 === $n && false !== $premiere ? ($premiere->estPhoto() ? 'Photo' : $premiere->getNom()) : $n.' fichiers';
        }

        return mb_strlen($texte) > $longueur ? rtrim(mb_substr($texte, 0, $longueur - 1)).'…' : $texte;
    }
}
