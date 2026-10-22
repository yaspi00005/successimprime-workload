<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fichier d'un message de l'équipe (docs/messagerie-interne.md, MI03 et
 * MI04) :
 *  - « fichier » ou « photo » envoyés : rangés sous var/uploads/chat (jamais
 *    publics), servis seulement aux personnes qui lisent la conversation ;
 *  - « lien » : un fichier déjà sur le serveur de l'atelier, dans le dossier
 *    du client (PSD de 210 Mo…). Rien n'est copié : le lien garde le chemin
 *    relatif au dossier du client, l'ERP local le sert après contrôle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'messages_chat_fichier')]
#[ORM\Index(name: 'idx_message_chat_fichier_conversation', columns: ['conversation_id', 'id'])]
#[ORM\HasLifecycleCallbacks]
class PieceJointeMessage
{
    public const FICHIER = 'fichier';
    public const PHOTO = 'photo';
    public const LIEN = 'lien';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    /** Recopie de la conversation du message : liste et recherche des fichiers partagés. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    #[ORM\Column(length: 10)]
    private string $genre = self::FICHIER;

    /** Nom affiché (celui de l'envoi, ou du fichier sur le serveur). */
    #[ORM\Column(length: 255)]
    private string $nom = '';

    /** Nom sous var/uploads/chat (choisi par le serveur), null pour un lien. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $stockage = null;

    /** Vignette d'une photo (var/uploads/chat). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $miniature = null;

    #[ORM\Column(length: 10)]
    private string $extension = '';

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private string|int $octets = 0;

    /** Lien : client dont le dossier contient le fichier ; fichier rangé aussi dans ce dossier. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Clients $client = null;

    /** Lien : chemin relatif au dossier du client (« 02 Fichiers reçus/bache.psd »). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $cheminServeur = null;

    /** Lien : date du fichier à l'envoi (repère un fichier changé depuis). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $empreinte = null;

    /** Copie rangée dans le dossier du client (chemin relatif à son dossier). */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rangeDans = null;

    /** Commande du groupe ou citée dans le message : filtre « commande » des fichiers partagés. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $creeLe = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        $this->creeLe ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;
        if (null !== $message?->getConversation()) {
            $this->conversation = $message->getConversation();
        }

        return $this;
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

    public function getGenre(): string
    {
        return $this->genre;
    }

    public function setGenre(string $genre): static
    {
        $this->genre = \in_array($genre, [self::FICHIER, self::PHOTO, self::LIEN], true) ? $genre : self::FICHIER;

        return $this;
    }

    public function estPhoto(): bool
    {
        return self::PHOTO === $this->genre;
    }

    public function estLien(): bool
    {
        return self::LIEN === $this->genre;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr(trim($nom), 0, 255);

        return $this;
    }

    public function getStockage(): ?string
    {
        return $this->stockage;
    }

    public function setStockage(?string $stockage): static
    {
        $this->stockage = $stockage;

        return $this;
    }

    public function getMiniature(): ?string
    {
        return $this->miniature;
    }

    public function setMiniature(?string $miniature): static
    {
        $this->miniature = $miniature;

        return $this;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function setExtension(string $extension): static
    {
        $this->extension = mb_substr(strtolower($extension), 0, 10);

        return $this;
    }

    public function getOctets(): int
    {
        return (int) $this->octets;
    }

    public function setOctets(int $octets): static
    {
        $this->octets = max(0, $octets);

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getCheminServeur(): ?string
    {
        return $this->cheminServeur;
    }

    public function setCheminServeur(?string $cheminServeur): static
    {
        $this->cheminServeur = $cheminServeur;

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function setModifieLe(?\DateTimeImmutable $modifieLe): static
    {
        $this->modifieLe = $modifieLe;

        return $this;
    }

    public function getEmpreinte(): ?string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(?string $empreinte): static
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function getRangeDans(): ?string
    {
        return $this->rangeDans;
    }

    public function setRangeDans(?string $rangeDans): static
    {
        $this->rangeDans = $rangeDans;

        return $this;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getCreeLe(): ?\DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $le): static
    {
        $this->creeLe = $le;

        return $this;
    }

    /** « PDF », « PSD », « JPG » : l'étiquette de couleur des listes. */
    public function getEtiquette(): string
    {
        return strtoupper('' !== $this->extension ? ('jpeg' === $this->extension ? 'jpg' : $this->extension) : 'fichier');
    }

    /** Fichier de production (tab « Fichiers de production » de MI04) : un lien vers le serveur, ou un format d'impression. */
    public function estProduction(): bool
    {
        return $this->estLien() || \in_array($this->extension, ['psd', 'ai', 'eps', 'tif', 'tiff'], true);
    }
}
