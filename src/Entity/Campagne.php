<?php

namespace App\Entity;

use App\Repository\CampagneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Campagne de communication (promo, vœux de fêtes...) envoyée en
 * masse à une liste de clients, via le canal du modèle de message
 * choisi. Créer une campagne la lance immédiatement : les envois
 * eux-mêmes sont étalés dans le temps par CampagneService, appelé
 * par la commande app:envoyer-campagnes (tâche planifiée), pour ne
 * pas dépasser les limites de débit des API SMS/WhatsApp/email.
 */
#[ORM\Entity(repositoryClass: CampagneRepository::class)]
#[ORM\Table(name: 'campagne')]
#[ORM\HasLifecycleCallbacks]
class Campagne
{
    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_TERMINEE = 'terminee';

    public const STATUTS_LABELS = [
        self::STATUT_EN_COURS => 'En cours',
        self::STATUT_TERMINEE => 'Terminée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom de la campagne est obligatoire.')]
    private string $nom = '';

    #[ORM\ManyToOne(targetEntity: ModeleMessage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le modèle de message est obligatoire.')]
    private ?ModeleMessage $modeleMessage = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_EN_COURS;

    /*
     * Nom d'expéditeur affiché au destinataire (SMS uniquement).
     * Laissé vide : le nom par défaut configuré dans .env.local est
     * utilisé (voir OrangeSmsService). Sans effet sur les campagnes
     * email/WhatsApp.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $senderName = null;

    #[ORM\Column]
    private int $nombreTotal = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    /**
     * @var Collection<int, CampagneDestinataire>
     */
    #[ORM\OneToMany(
        targetEntity: CampagneDestinataire::class,
        mappedBy: 'campagne',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $destinataires;

    public function __construct()
    {
        $this->destinataires = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        if ($this->dateCreation === null) {
            $this->dateCreation = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getModeleMessage(): ?ModeleMessage
    {
        return $this->modeleMessage;
    }

    public function setModeleMessage(?ModeleMessage $modeleMessage): static
    {
        $this->modeleMessage = $modeleMessage;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function setSenderName(?string $senderName): static
    {
        $senderName = $senderName !== null ? trim($senderName) : null;

        $this->senderName = $senderName !== '' ? $senderName : null;

        return $this;
    }

    public function getNombreTotal(): int
    {
        return $this->nombreTotal;
    }

    public function setNombreTotal(int $nombreTotal): static
    {
        $this->nombreTotal = max(0, $nombreTotal);

        return $this;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /**
     * @return Collection<int, CampagneDestinataire>
     */
    public function getDestinataires(): Collection
    {
        return $this->destinataires;
    }

    public function __toString(): string
    {
        return $this->nom !== '' ? $this->nom : 'Nouvelle campagne';
    }
}
