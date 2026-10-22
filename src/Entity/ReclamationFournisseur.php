<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réclamation au fournisseur créée à la réception (maquette SU05,
 * docs/achats.md) : la marchandise facturée mais pas arrivée en bon état
 * (un rouleau écrasé, un rouleau manquant), à remplacer ou à déduire.
 *
 * Tant qu'elle est ouverte, le paiement du solde de la commande est
 * bloqué. « Remplacée » fait entrer la marchandise de remplacement en
 * stock ; « déduite » retire son montant de la facture à payer ; « close »
 * la ferme sans suite.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_reclamation_fournisseur_statut', columns: ['statut'])]
class ReclamationFournisseur
{
    public const OUVERTE = 'ouverte';
    public const REMPLACEE = 'remplacee';
    public const DEDUITE = 'deduite';
    public const CLOSE = 'close';

    /** Statut => [libellé, classe du badge]. */
    public const STATUTS = [
        self::OUVERTE => ['Ouverte', 'retard'],
        self::REMPLACEE => ['Remplacée', 'ok'],
        self::DEDUITE => ['Déduite', 'ok'],
        self::CLOSE => ['Close', 'brouillon'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reclamations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Achats $achat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AchatDetail $ligne = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Articles $article = null;

    /** En unité d'achat (rouleaux). */
    #[ORM\Column]
    private int $quantite = 0;

    /** Prix d'achat de la quantité réclamée, à remplacer ou à déduire. */
    #[ORM\Column(options: ['default' => 0])]
    private int $montant = 0;

    /** Photo de la casse : nom du fichier sous var/uploads/reclamations. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::OUVERTE;

    /** Message préparé pour le fournisseur (WhatsApp, lien wa.me). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $messageEnvoyeLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $clotureeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $clotureePar = null;

    public function __construct(?User $par = null, ?\DateTimeImmutable $le = null)
    {
        $this->creePar = $par;
        $this->creeLe = $le ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAchat(): ?Achats
    {
        return $this->achat;
    }

    public function setAchat(?Achats $achat): static
    {
        $this->achat = $achat;

        return $this;
    }

    public function getLigne(): ?AchatDetail
    {
        return $this->ligne;
    }

    public function setLigne(?AchatDetail $ligne): static
    {
        $this->ligne = $ligne;
        $this->article = $ligne?->getArticle() ?? $this->article;

        return $this;
    }

    public function getArticle(): ?Articles
    {
        return $this->article;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(0, $quantite);

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = max(0, $montant);

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getLibelleStatut(): string
    {
        return self::STATUTS[$this->statut][0] ?? $this->statut;
    }

    public function getClasseStatut(): string
    {
        return self::STATUTS[$this->statut][1] ?? '';
    }

    public function estOuverte(): bool
    {
        return $this->statut === self::OUVERTE;
    }

    /**
     * Ferme la réclamation : remplacée, déduite ou close.
     */
    public function cloturer(string $statut, ?User $par, \DateTimeImmutable $le): static
    {
        if (!isset(self::STATUTS[$statut]) || $statut === self::OUVERTE) {
            throw new \InvalidArgumentException('Statut de réclamation inconnu.');
        }

        $this->statut = $statut;
        $this->clotureePar = $par;
        $this->clotureeLe = $le;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getMessageEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->messageEnvoyeLe;
    }

    public function setMessageEnvoyeLe(?\DateTimeImmutable $le): static
    {
        $this->messageEnvoyeLe = $le;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function getClotureeLe(): ?\DateTimeImmutable
    {
        return $this->clotureeLe;
    }

    public function getClotureePar(): ?User
    {
        return $this->clotureePar;
    }
}
