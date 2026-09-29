<?php

namespace App\Entity;

use App\Repository\AchatsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AchatsRepository::class)]
class Achats
{
    public const STATUT_DEMANDE = 'demande';
    public const STATUT_RECU = 'recu';
    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [
        self::STATUT_DEMANDE,
        self::STATUT_RECU,
        self::STATUT_ANNULE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true, unique: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(inversedBy: 'achats')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fournisseurs $fournisseur = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: self::STATUTS,
        message: 'Statut d’achat invalide.'
    )]
    private string $statut = self::STATUT_DEMANDE;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateDemande = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateReception = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $tva = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTtc = 0;

    /**
     * @var Collection<int, AchatDetail>
     */
    #[ORM\OneToMany(
        targetEntity: AchatDetail::class,
        mappedBy: 'achat',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getFournisseur(): ?Fournisseurs
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseurs $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = in_array($statut, self::STATUTS, true)
            ? $statut
            : self::STATUT_DEMANDE;

        return $this;
    }

    public function estEnDemande(): bool
    {
        return $this->statut === self::STATUT_DEMANDE;
    }

    public function estRecu(): bool
    {
        return $this->statut === self::STATUT_RECU;
    }

    public function estAnnule(): bool
    {
        return $this->statut === self::STATUT_ANNULE;
    }

    public function getDateDemande(): ?\DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeImmutable $dateDemande): static
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }

    public function getDateReception(): ?\DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(?\DateTimeImmutable $dateReception): static
    {
        $this->dateReception = $dateReception;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $this->observation = $observation;

        return $this;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function setTotalHt(int $totalHt): static
    {
        $this->totalHt = max(0, $totalHt);

        return $this;
    }

    public function getTva(): int
    {
        return $this->tva;
    }

    public function setTva(int $tva): static
    {
        $this->tva = max(0, $tva);

        return $this;
    }

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }

    public function setTotalTtc(int $totalTtc): static
    {
        $this->totalTtc = max(0, $totalTtc);

        return $this;
    }

    /**
     * @return Collection<int, AchatDetail>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(AchatDetail $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setAchat($this);
        }

        return $this;
    }

    public function removeLigne(AchatDetail $ligne): static
    {
        if (
            $this->lignes->removeElement($ligne)
            && $ligne->getAchat() === $this
        ) {
            $ligne->setAchat(null);
        }

        return $this;
    }

    /**
     * Recalcule totalHt/totalTtc à partir des lignes actuelles.
     */
    public function recalculerTotaux(): static
    {
        $totalHt = 0;

        foreach ($this->lignes as $ligne) {
            $totalHt += $ligne->getTotalHt();
        }

        $this->totalHt = $totalHt;
        $this->totalTtc = (int) round(
            $totalHt * (1 + $this->tva / 100)
        );

        return $this;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Nouvel achat';
    }
}
