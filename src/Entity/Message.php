<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages_chat')]
#[ORM\HasLifecycleCallbacks]
class Message
{
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

    /**
     * Nom du fichier tel que stocké sur le disque (uploads/chat).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pieceJointeFichier = null;

    /**
     * Nom du fichier tel qu'envoyé par l'utilisateur, pour l'affichage
     * et le téléchargement.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pieceJointeNomOriginal = null;

    private const EXTENSIONS_IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

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

    public function setContenu(string $contenu): static
    {
        $this->contenu = trim($contenu);

        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }

    public function getPieceJointeFichier(): ?string
    {
        return $this->pieceJointeFichier;
    }

    public function getPieceJointeNomOriginal(): ?string
    {
        return $this->pieceJointeNomOriginal;
    }

    public function definirPieceJointe(string $fichier, string $nomOriginal): static
    {
        $this->pieceJointeFichier = $fichier;
        $this->pieceJointeNomOriginal = $nomOriginal;

        return $this;
    }

    public function aUnePieceJointe(): bool
    {
        return $this->pieceJointeFichier !== null;
    }

    public function pieceJointeEstUneImage(): bool
    {
        if ($this->pieceJointeFichier === null) {
            return false;
        }

        $extension = strtolower(pathinfo($this->pieceJointeFichier, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS_IMAGE, true);
    }
}
