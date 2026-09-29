<?php

namespace App\Entity;

use App\Repository\ReclamationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Demande de remboursement d'un agent (achat effectué sur ses fonds
 * propres) : brouillon -> soumise -> validée/refusée par un admin ->
 * payée par la caisse, qui enregistre alors le décaissement
 * correspondant dans MouvementTresorerie.
 */
#[ORM\Entity(repositoryClass: ReclamationRepository::class)]
#[ORM\Table(name: 'reclamations')]
#[ORM\Index(name: 'idx_reclamation_statut', columns: ['statut'])]
#[ORM\HasLifecycleCallbacks]
class Reclamation
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_VALIDEE = 'validee';
    public const STATUT_REFUSEE = 'refusee';
    public const STATUT_PAYEE = 'payee';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_VALIDEE,
        self::STATUT_REFUSEE,
        self::STATUT_PAYEE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_EN_ATTENTE => 'En attente',
        self::STATUT_VALIDEE => 'Validée — en attente de paiement',
        self::STATUT_REFUSEE => 'Refusée',
        self::STATUT_PAYEE => 'Payée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $reference = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $agent = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Veuillez indiquer le motif de la demande.')]
    private string $motif = '';

    #[ORM\Column]
    #[Assert\NotNull(message: 'Veuillez indiquer le montant.')]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro.')]
    private int $montant = 0;

    /**
     * Nom du fichier stocké (public/uploads/reclamations/...) — le
     * justificatif est facultatif ("si possible").
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_EN_ATTENTE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifRefus = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateRefus = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $refusePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePaiement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $payePar = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL', unique: true)]
    private ?MouvementTresorerie $mouvementTresorerie = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = trim($reference);

        return $this;
    }

    public function getAgent(): ?User
    {
        return $this->agent;
    }

    public function setAgent(?User $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = trim($motif);

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(?int $montant): static
    {
        $this->montant = max(0, $montant ?? 0);

        return $this;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function setJustificatif(?string $justificatif): static
    {
        $this->justificatif = $justificatif;

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

    public function isEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function isValidee(): bool
    {
        return $this->statut === self::STATUT_VALIDEE;
    }

    public function isRefusee(): bool
    {
        return $this->statut === self::STATUT_REFUSEE;
    }

    public function isPayee(): bool
    {
        return $this->statut === self::STATUT_PAYEE;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function marquerCommeValidee(User $admin): static
    {
        if (!$this->isEnAttente()) {
            throw new \LogicException('Seule une réclamation en attente peut être validée.');
        }

        $this->statut = self::STATUT_VALIDEE;
        $this->dateValidation = new \DateTimeImmutable();
        $this->validePar = $admin;

        return $this;
    }

    public function getMotifRefus(): ?string
    {
        return $this->motifRefus;
    }

    public function getDateRefus(): ?\DateTimeImmutable
    {
        return $this->dateRefus;
    }

    public function getRefusePar(): ?User
    {
        return $this->refusePar;
    }

    public function marquerCommeRefusee(User $admin, string $motif): static
    {
        if (!$this->isEnAttente()) {
            throw new \LogicException('Seule une réclamation en attente peut être refusée.');
        }

        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException('Le motif de refus est obligatoire.');
        }

        $this->statut = self::STATUT_REFUSEE;
        $this->motifRefus = $motif;
        $this->dateRefus = new \DateTimeImmutable();
        $this->refusePar = $admin;

        return $this;
    }

    public function getDatePaiement(): ?\DateTimeImmutable
    {
        return $this->datePaiement;
    }

    public function getPayePar(): ?User
    {
        return $this->payePar;
    }

    public function getMouvementTresorerie(): ?MouvementTresorerie
    {
        return $this->mouvementTresorerie;
    }

    public function marquerCommePayee(User $caissier, MouvementTresorerie $mouvement): static
    {
        if (!$this->isValidee()) {
            throw new \LogicException('Seule une réclamation validée peut être payée.');
        }

        $this->statut = self::STATUT_PAYEE;
        $this->datePaiement = new \DateTimeImmutable();
        $this->payePar = $caissier;
        $this->mouvementTresorerie = $mouvement;

        return $this;
    }

    /**
     * True une fois que l'agent a vu la notification "reclamation
     * validee, passez a la caisse" (cloche de notification). N'a de
     * sens que lorsque le statut est validee.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $notificationLue = false;

    public function estNotificationNonLue(): bool
    {
        return $this->isValidee() && !$this->notificationLue;
    }

    public function marquerNotificationLue(): static
    {
        $this->notificationLue = true;

        return $this;
    }
}
