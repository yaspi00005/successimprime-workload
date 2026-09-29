<?php

namespace App\Entity;

use App\Repository\RapprochementBancaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: RapprochementBancaireRepository::class
)]
#[ORM\Table(name: 'rapprochement_bancaire')]
#[ORM\UniqueConstraint(
    name: 'uniq_rapprochement_reference',
    columns: ['reference']
)]
class RapprochementBancaire
{
    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_CLOTURE = 'cloture';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'compte_tresorerie_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    private ?CompteTresorerie $compteTresorerie = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column]
    private int $soldeOuvertureReleve = 0;

    #[ORM\Column]
    private int $soldeClotureReleve = 0;

    #[ORM\Column]
    private int $soldeComptable = 0;

    #[ORM\Column]
    private int $ecart = 0;

    #[ORM\Column(
        length: 20,
        options: ['default' => self::STATUT_BROUILLON]
    )]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(
        type: 'text',
        nullable: true
    )]
    private ?string $observation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'cree_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $creePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'valide_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $validePar = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(
        type: 'datetime_immutable',
        nullable: true
    )]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\Column(
        type: 'datetime_immutable',
        nullable: true
    )]
    private ?\DateTimeImmutable $dateCloture = null;

    /**
     * @var Collection<int, LigneRapprochementBancaire>
     */
    #[ORM\OneToMany(
        mappedBy: 'rapprochementBancaire',
        targetEntity: LigneRapprochementBancaire::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy([
        'dateReleve' => 'ASC',
        'id' => 'ASC',
    ])]
    private Collection $lignes;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->statut = self::STATUT_BROUILLON;
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompteTresorerie(): ?CompteTresorerie
    {
        return $this->compteTresorerie;
    }

    public function setCompteTresorerie(
        ?CompteTresorerie $compteTresorerie
    ): static {
        $this->compteTresorerie = $compteTresorerie;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = trim($reference);

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(
        \DateTimeImmutable $dateDebut
    ): static {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(
        \DateTimeImmutable $dateFin
    ): static {
        if (
            $this->dateDebut !== null
            && $dateFin < $this->dateDebut
        ) {
            throw new \InvalidArgumentException(
                'La date de fin doit être postérieure ou égale à la date de début.'
            );
        }

        $this->dateFin = $dateFin;

        return $this;
    }

    public function getSoldeOuvertureReleve(): int
    {
        return $this->soldeOuvertureReleve;
    }

    public function setSoldeOuvertureReleve(
        int $soldeOuvertureReleve
    ): static {
        $this->soldeOuvertureReleve = $soldeOuvertureReleve;

        return $this;
    }

    public function getSoldeClotureReleve(): int
    {
        return $this->soldeClotureReleve;
    }

    public function setSoldeClotureReleve(
        int $soldeClotureReleve
    ): static {
        $this->soldeClotureReleve = $soldeClotureReleve;
        $this->calculerEcart();

        return $this;
    }

    public function getSoldeComptable(): int
    {
        return $this->soldeComptable;
    }

    public function setSoldeComptable(
        int $soldeComptable
    ): static {
        $this->soldeComptable = $soldeComptable;
        $this->calculerEcart();

        return $this;
    }

    public function getEcart(): int
    {
        return $this->ecart;
    }

    public function setEcart(int $ecart): static
    {
        $this->ecart = $ecart;

        return $this;
    }

    public function calculerEcart(): static
    {
        $this->ecart =
            $this->soldeClotureReleve
            - $this->soldeComptable;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $statutsAutorises = [
            self::STATUT_BROUILLON,
            self::STATUT_VALIDE,
            self::STATUT_CLOTURE,
        ];

        if (!in_array($statut, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                'Le statut du rapprochement bancaire est invalide.'
            );
        }

        $this->statut = $statut;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(
        ?string $observation
    ): static {
        $observation = $observation !== null
            ? trim($observation)
            : null;

        $this->observation = $observation !== ''
            ? $observation
            : null;

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

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function setValidePar(?User $validePar): static
    {
        $this->validePar = $validePar;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(
        \DateTimeImmutable $dateCreation
    ): static {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function setDateValidation(
        ?\DateTimeImmutable $dateValidation
    ): static {
        $this->dateValidation = $dateValidation;

        return $this;
    }

    public function getDateCloture(): ?\DateTimeImmutable
    {
        return $this->dateCloture;
    }

    public function setDateCloture(
        ?\DateTimeImmutable $dateCloture
    ): static {
        $this->dateCloture = $dateCloture;

        return $this;
    }

    /**
     * @return Collection<int, LigneRapprochementBancaire>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(
        LigneRapprochementBancaire $ligne
    ): static {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setRapprochementBancaire($this);
        }

        return $this;
    }

    public function removeLigne(
        LigneRapprochementBancaire $ligne
    ): static {
        if (
            $this->lignes->removeElement($ligne)
            && $ligne->getRapprochementBancaire() === $this
        ) {
            $ligne->setRapprochementBancaire(null);
        }

        return $this;
    }

    public function getNombreLignes(): int
    {
        return $this->lignes->count();
    }

    public function getNombreLignesPointees(): int
    {
        return $this->lignes
            ->filter(
                static fn (
                    LigneRapprochementBancaire $ligne
                ): bool => $ligne->isPointee()
            )
            ->count();
    }

    public function getTotalMontantRelevePointe(): int
    {
        $total = 0;

        foreach ($this->lignes as $ligne) {
            if ($ligne->isPointee()) {
                $total += $ligne->getMontantReleve();
            }
        }

        return $total;
    }

    public function estBrouillon(): bool
    {
        return $this->statut === self::STATUT_BROUILLON;
    }

    public function estValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function estCloture(): bool
    {
        return $this->statut === self::STATUT_CLOTURE;
    }

    public function estEquilibre(): bool
    {
        return $this->ecart === 0;
    }

    public function peutEtreModifie(): bool
    {
        return !$this->estCloture();
    }

    public function valider(User $utilisateur): static
    {
        if ($this->dateDebut === null || $this->dateFin === null) {
            throw new \LogicException(
                'La période du rapprochement doit être renseignée.'
            );
        }

        $this->calculerEcart();
        $this->statut = self::STATUT_VALIDE;
        $this->validePar = $utilisateur;
        $this->dateValidation = new \DateTimeImmutable();

        return $this;
    }

    public function cloturer(): static
    {
        if (!$this->estValide()) {
            throw new \LogicException(
                'Le rapprochement doit être validé avant sa clôture.'
            );
        }

        if (!$this->estEquilibre()) {
            throw new \LogicException(
                'Impossible de clôturer un rapprochement présentant un écart.'
            );
        }

        $this->statut = self::STATUT_CLOTURE;
        $this->dateCloture = new \DateTimeImmutable();

        return $this;
    }
}