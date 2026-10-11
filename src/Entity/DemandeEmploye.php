<?php

namespace App\Entity;

use App\Repository\DemandeEmployeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une demande de l'espace employé (docs/espace-employe.md) : absence,
 * congé, réclamation d'une somme due ou remboursement de frais médicaux.
 * La RH accepte (en totalité ou en partie) ou refuse, avec un commentaire.
 */
#[ORM\Entity(repositoryClass: DemandeEmployeRepository::class)]
#[ORM\Table(name: 'demande_employe')]
#[ORM\Index(name: 'idx_demande_employe_etat', columns: ['etat'])]
class DemandeEmploye
{
    public const ABSENCE = 'absence';
    public const CONGE = 'conge';
    public const RECLAMATION = 'reclamation';
    public const FRAIS_MEDICAUX = 'frais_medicaux';

    public const TYPES = [
        self::ABSENCE => 'Absence',
        self::CONGE => 'Congé',
        self::RECLAMATION => 'Réclamation',
        self::FRAIS_MEDICAUX => 'Frais médicaux',
    ];

    /** Motifs d'une réclamation de somme due. */
    public const CATEGORIES = [
        'heures_sup' => 'Heures supplémentaires',
        'prime' => 'Prime',
        'frais_avances' => 'Frais avancés pour le travail',
        'reste_salaire' => 'Reste de salaire',
    ];

    public const EN_ATTENTE = 'en_attente';
    public const ACCEPTEE = 'acceptee';
    public const REFUSEE = 'refusee';
    public const PAYEE = 'payee';

    public const ETATS = [
        self::EN_ATTENTE => 'En attente',
        self::ACCEPTEE => 'Acceptée',
        self::REFUSEE => 'Refusée',
        self::PAYEE => 'Payée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $du;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $au = null;

    #[ORM\Column]
    private bool $demiJournee = false;

    /** Jours ouvrés couverts (absence, congé). */
    #[ORM\Column(nullable: true)]
    private ?float $jours = null;

    #[ORM\Column(length: 255)]
    private string $motif;

    #[ORM\Column(nullable: true)]
    private ?int $montant = null;

    #[ORM\Column(nullable: true)]
    private ?int $montantAccorde = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(length: 12)]
    private string $etat = self::EN_ATTENTE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $decidePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decideLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MouvementTresorerie $mouvement = null;

    public function __construct(User $utilisateur, string $type, \DateTimeImmutable $du, string $motif, \DateTimeImmutable $creeLe)
    {
        $this->utilisateur = $utilisateur;
        $this->type = isset(self::TYPES[$type]) ? $type : self::ABSENCE;
        $this->du = $du;
        $this->motif = mb_substr(trim($motif), 0, 255);
        $this->creeLe = $creeLe;
    }

    public function periode(?\DateTimeImmutable $au, bool $demiJournee, ?float $jours): static
    {
        $this->au = $au;
        $this->demiJournee = $demiJournee;
        $this->jours = $jours;

        return $this;
    }

    public function somme(?int $montant, ?string $categorie = null): static
    {
        $this->montant = $montant;
        $this->categorie = $categorie !== null && isset(self::CATEGORIES[$categorie]) ? $categorie : null;

        return $this;
    }

    public function decider(bool $accepter, ?int $montantAccorde, ?string $commentaire, User $par, \DateTimeImmutable $le): void
    {
        $this->etat = $accepter ? self::ACCEPTEE : self::REFUSEE;
        $this->montantAccorde = $accepter ? $montantAccorde : null;
        $this->commentaire = $commentaire !== null && trim($commentaire) !== '' ? mb_substr(trim($commentaire), 0, 255) : null;
        $this->decidePar = $par;
        $this->decideLe = $le;
    }

    public function payer(MouvementTresorerie $mouvement): void
    {
        $this->mouvement = $mouvement;
        $this->etat = self::PAYEE;
    }

    /** Une somme acceptée reste à payer. */
    public function estAPayer(): bool
    {
        return $this->etat === self::ACCEPTEE && ($this->montantAccorde ?? 0) > 0;
    }

    public function estUneSomme(): bool
    {
        return \in_array($this->type, [self::RECLAMATION, self::FRAIS_MEDICAUX], true);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type];
    }

    public function libelleEtat(): string
    {
        return $this->etat === self::ACCEPTEE && $this->estUneSomme() && $this->montantAccorde !== null && $this->montantAccorde < (int) $this->montant
            ? 'Acceptée en partie'
            : self::ETATS[$this->etat];
    }

    public function libelleCategorie(): ?string
    {
        return $this->categorie !== null ? self::CATEGORIES[$this->categorie] : null;
    }

    public function setJustificatif(?string $justificatif): static
    {
        $this->justificatif = $justificatif;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): User
    {
        return $this->utilisateur;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDu(): \DateTimeImmutable
    {
        return $this->du;
    }

    public function getAu(): ?\DateTimeImmutable
    {
        return $this->au;
    }

    public function isDemiJournee(): bool
    {
        return $this->demiJournee;
    }

    public function getJours(): ?float
    {
        return $this->jours;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function getMontantAccorde(): ?int
    {
        return $this->montantAccorde;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function getDecidePar(): ?User
    {
        return $this->decidePar;
    }

    public function getDecideLe(): ?\DateTimeImmutable
    {
        return $this->decideLe;
    }

    public function getMouvement(): ?MouvementTresorerie
    {
        return $this->mouvement;
    }
}
