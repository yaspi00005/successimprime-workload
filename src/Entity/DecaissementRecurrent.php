<?php

namespace App\Entity;

use App\Repository\DecaissementRecurrentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Charge fixe qui doit être décaissée automatiquement, chaque mois ou
 * chaque année, sans ressaisie manuelle : frais bancaires, remboursement
 * d'un crédit, loyer... La commande app:executer-decaissements-recurrents
 * (lancée par une tâche planifiée) crée le MouvementTresorerie dès que
 * prochaineDateExecution est atteinte.
 */
#[ORM\Entity(repositoryClass: DecaissementRecurrentRepository::class)]
#[ORM\Table(name: 'decaissement_recurrent')]
#[ORM\HasLifecycleCallbacks]
class DecaissementRecurrent
{
    public const FREQUENCE_MENSUELLE = 'mensuelle';
    public const FREQUENCE_ANNUELLE = 'annuelle';

    public const FREQUENCES = [
        self::FREQUENCE_MENSUELLE,
        self::FREQUENCE_ANNUELLE,
    ];

    public const FREQUENCES_LABELS = [
        self::FREQUENCE_MENSUELLE => 'Tous les mois',
        self::FREQUENCE_ANNUELLE => 'Tous les ans',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: CompteTresorerie::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le compte à débiter est obligatoire.')]
    private ?CompteTresorerie $compteSource = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(
        callback: [MouvementTresorerie::class, 'getCategoriesDisponibles'],
        message: 'La catégorie sélectionnée est invalide.'
    )]
    private string $categorie = MouvementTresorerie::CATEGORIE_AUTRE_CHARGE;

    #[ORM\Column]
    #[Assert\Positive(message: 'Le montant doit être supérieur à zéro.')]
    private int $montant = 0;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::FREQUENCES, message: 'La fréquence sélectionnée est invalide.')]
    private string $frequence = self::FREQUENCE_MENSUELLE;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 28, notInRangeMessage: 'Le jour du mois doit être compris entre 1 et 28 (pour rester valable tous les mois).')]
    private int $jourDuMois = 1;

    #[ORM\Column(nullable: true)]
    private ?int $moisDeLAnnee = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date de début est obligatoire.')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $prochaineDateExecution = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $derniereDateExecution = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        if ($this->dateCreation === null) {
            $this->dateCreation = new \DateTimeImmutable();
        }

        if ($this->prochaineDateExecution === null && $this->dateDebut !== null) {
            $this->prochaineDateExecution = $this->calculerPremiereDateExecution($this->dateDebut);
        }
    }

    #[Assert\Callback]
    public function validerMoisAnnuel(ExecutionContextInterface $context): void
    {
        if ($this->frequence !== self::FREQUENCE_ANNUELLE) {
            return;
        }

        if ($this->moisDeLAnnee === null || $this->moisDeLAnnee < 1 || $this->moisDeLAnnee > 12) {
            $context->buildViolation('Le mois est obligatoire pour une charge annuelle.')
                ->atPath('moisDeLAnnee')
                ->addViolation();
        }
    }

    /**
     * Première échéance à partir d'une date donnée (utilisée à la
     * création, quand aucune échéance n'a encore été calculée).
     */
    public function calculerPremiereDateExecution(\DateTimeImmutable $depuis): \DateTimeImmutable
    {
        $annee = (int) $depuis->format('Y');
        $mois = $this->frequence === self::FREQUENCE_ANNUELLE
            ? (int) ($this->moisDeLAnnee ?? 1)
            : (int) $depuis->format('n');

        $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $annee, $mois, $this->jourDuMois));

        if ($candidate < $depuis) {
            $candidate = $this->avancerDate($candidate);
        }

        return $candidate;
    }

    private function avancerDate(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $this->frequence === self::FREQUENCE_ANNUELLE
            ? $date->modify('+1 year')
            : $date->modify('+1 month');
    }

    public function estDue(\DateTimeImmutable $reference): bool
    {
        return $this->actif
            && $this->prochaineDateExecution !== null
            && $this->prochaineDateExecution <= $reference;
    }

    /**
     * Appelée après avoir effectivement créé le décaissement pour
     * l'échéance en cours : avance à l'échéance suivante.
     */
    public function marquerExecutee(\DateTimeImmutable $dateExecution): static
    {
        $this->derniereDateExecution = $dateExecution;
        $this->prochaineDateExecution = $this->avancerDate(
            $this->prochaineDateExecution ?? $dateExecution
        );

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = trim($libelle);

        return $this;
    }

    public function getCompteSource(): ?CompteTresorerie
    {
        return $this->compteSource;
    }

    public function setCompteSource(?CompteTresorerie $compteSource): static
    {
        $this->compteSource = $compteSource;

        return $this;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getFrequence(): string
    {
        return $this->frequence;
    }

    public function setFrequence(string $frequence): static
    {
        $this->frequence = $frequence;

        return $this;
    }

    public function getJourDuMois(): int
    {
        return $this->jourDuMois;
    }

    public function setJourDuMois(int $jourDuMois): static
    {
        $this->jourDuMois = $jourDuMois;

        return $this;
    }

    public function getMoisDeLAnnee(): ?int
    {
        return $this->moisDeLAnnee;
    }

    public function setMoisDeLAnnee(?int $moisDeLAnnee): static
    {
        $this->moisDeLAnnee = $this->frequence === self::FREQUENCE_ANNUELLE
            ? $moisDeLAnnee
            : null;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getProchaineDateExecution(): ?\DateTimeImmutable
    {
        return $this->prochaineDateExecution;
    }

    public function setProchaineDateExecution(?\DateTimeImmutable $prochaineDateExecution): static
    {
        $this->prochaineDateExecution = $prochaineDateExecution;

        return $this;
    }

    public function getDerniereDateExecution(): ?\DateTimeImmutable
    {
        return $this->derniereDateExecution;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        return $this;
    }
}
