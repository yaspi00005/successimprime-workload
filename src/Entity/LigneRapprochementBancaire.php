<?php

namespace App\Entity;

use App\Repository\LigneRapprochementBancaireRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(
    repositoryClass: LigneRapprochementBancaireRepository::class
)]
#[ORM\Table(name: 'ligne_rapprochement_bancaire')]
#[ORM\UniqueConstraint(
    name: 'uniq_ligne_rapprochement_mouvement',
    columns: [
        'rapprochement_bancaire_id',
        'mouvement_tresorerie_id',
    ]
)]
class LigneRapprochementBancaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        inversedBy: 'lignes'
    )]
    #[ORM\JoinColumn(
        name: 'rapprochement_bancaire_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?RapprochementBancaire $rapprochementBancaire = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'mouvement_tresorerie_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    private ?MouvementTresorerie $mouvementTresorerie = null;

    #[ORM\Column(
        type: 'date_immutable',
        nullable: true
    )]
    private ?\DateTimeImmutable $dateReleve = null;

    #[ORM\Column(
        length: 100,
        nullable: true
    )]
    private ?string $referenceBancaire = null;

    #[ORM\Column]
    private int $montantReleve = 0;

    #[ORM\Column(
        options: ['default' => false]
    )]
    private bool $pointee = false;

    #[ORM\Column(
        type: 'text',
        nullable: true
    )]
    private ?string $observation = null;

    #[ORM\Column(
        type: 'datetime_immutable'
    )]
    private ?\DateTimeImmutable $dateCreation = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRapprochementBancaire(): ?RapprochementBancaire
    {
        return $this->rapprochementBancaire;
    }

    public function setRapprochementBancaire(
        ?RapprochementBancaire $rapprochementBancaire
    ): static {
        $this->rapprochementBancaire = $rapprochementBancaire;

        return $this;
    }

    public function getMouvementTresorerie(): ?MouvementTresorerie
    {
        return $this->mouvementTresorerie;
    }

    public function setMouvementTresorerie(
        ?MouvementTresorerie $mouvementTresorerie
    ): static {
        $this->mouvementTresorerie = $mouvementTresorerie;

        return $this;
    }

    public function getDateReleve(): ?\DateTimeImmutable
    {
        return $this->dateReleve;
    }

    public function setDateReleve(
        ?\DateTimeImmutable $dateReleve
    ): static {
        $this->dateReleve = $dateReleve;

        return $this;
    }

    public function getReferenceBancaire(): ?string
    {
        return $this->referenceBancaire;
    }

    public function setReferenceBancaire(
        ?string $referenceBancaire
    ): static {
        $referenceBancaire = $referenceBancaire !== null
            ? trim($referenceBancaire)
            : null;

        $this->referenceBancaire = $referenceBancaire !== ''
            ? $referenceBancaire
            : null;

        return $this;
    }

    public function getMontantReleve(): int
    {
        return $this->montantReleve;
    }

    public function setMontantReleve(int $montantReleve): static
    {
        $this->montantReleve = $montantReleve;

        return $this;
    }

    public function isPointee(): bool
    {
        return $this->pointee;
    }

    public function setPointee(bool $pointee): static
    {
        $this->pointee = $pointee;

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

    /**
     * Écart entre le montant du relevé
     * et celui du mouvement comptable.
     */
    public function getEcart(): int
    {
        $montantMouvement = $this->mouvementTresorerie
            ? $this->mouvementTresorerie->getMontant()
            : 0;

        return $this->montantReleve - $montantMouvement;
    }

    public function estConforme(): bool
    {
        return $this->pointee && $this->getEcart() === 0;
    }
}