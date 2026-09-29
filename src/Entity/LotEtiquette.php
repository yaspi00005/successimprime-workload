<?php

namespace App\Entity;

use App\Repository\LotEtiquetteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LotEtiquetteRepository::class)]
#[ORM\Table(name: 'lot_etiquette')]
#[ORM\HasLifecycleCallbacks]
class LotEtiquette
{
    public const STATUT_EN_GENERATION = 'en_generation';
    public const STATUT_PRET = 'pret';
    public const STATUT_ECHEC = 'echec';
    public const STATUT_ARCHIVE = 'archive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Exemple : LOT-20260806-001
     */
    #[ORM\Column(length: 30, unique: true)]
    private ?string $numero = null;

    /**
     * Nombre total de QR codes demandés dans le lot.
     */
    #[ORM\Column]
    private int $quantite = 0;

    /**
     * Préfixe utilisé pour les numéros des étiquettes.
     * Exemple : ETQ
     */
    #[ORM\Column(length: 20)]
    private string $prefixe = 'ETQ';

    /**
     * Nom du dossier dans public/uploads/etiquettes.
     * Exemple : LOT-20260806-001
     */
    #[ORM\Column(length: 255, unique: true)]
    private ?string $dossier = null;

    /**
     * Chemin relatif du fichier ZIP.
     * Exemple :
     * uploads/etiquettes/LOT-20260806-001/LOT-20260806-001.zip
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichierZip = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_EN_GENERATION;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineLe = null;

    /**
     * Utilisateur ayant lancé la génération.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'genere_par_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    private ?User $generePar = null;

    /**
     * @var Collection<int, Etiquette>
     */
    #[ORM\OneToMany(
        targetEntity: Etiquette::class,
        mappedBy: 'lot',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $etiquettes;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->etiquettes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $numero = strtoupper(trim($numero));

        if ($numero === '') {
            throw new \InvalidArgumentException(
                'Le numéro du lot ne peut pas être vide.'
            );
        }

        $this->numero = $numero;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        if ($quantite < 1) {
            throw new \InvalidArgumentException(
                'La quantité doit être supérieure à zéro.'
            );
        }

        $this->quantite = $quantite;

        return $this;
    }

    public function getPrefixe(): string
    {
        return $this->prefixe;
    }

    public function setPrefixe(string $prefixe): static
    {
        $prefixe = strtoupper(trim($prefixe));

        if ($prefixe === '') {
            throw new \InvalidArgumentException(
                'Le préfixe ne peut pas être vide.'
            );
        }

        $this->prefixe = $prefixe;

        return $this;
    }

    public function getDossier(): ?string
    {
        return $this->dossier;
    }

    public function setDossier(string $dossier): static
    {
        $dossier = trim($dossier);

        if ($dossier === '') {
            throw new \InvalidArgumentException(
                'Le dossier du lot ne peut pas être vide.'
            );
        }

        $this->dossier = $dossier;

        return $this;
    }

    public function getFichierZip(): ?string
    {
        return $this->fichierZip;
    }

    public function setFichierZip(?string $fichierZip): static
    {
        $this->fichierZip = $fichierZip !== null
            ? trim($fichierZip)
            : null;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $statutsAutorises = [
            self::STATUT_EN_GENERATION,
            self::STATUT_PRET,
            self::STATUT_ECHEC,
            self::STATUT_ARCHIVE,
        ];

        if (!in_array($statut, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut de lot invalide : %s', $statut)
            );
        }

        $this->statut = $statut;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(
        \DateTimeImmutable $creeLe
    ): static {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function getTermineLe(): ?\DateTimeImmutable
    {
        return $this->termineLe;
    }

    public function setTermineLe(
        ?\DateTimeImmutable $termineLe
    ): static {
        $this->termineLe = $termineLe;

        return $this;
    }

    public function getGenerePar(): ?User
    {
        return $this->generePar;
    }

    public function setGenerePar(?User $generePar): static
    {
        $this->generePar = $generePar;

        return $this;
    }

    /**
     * @return Collection<int, Etiquette>
     */
    public function getEtiquettes(): Collection
    {
        return $this->etiquettes;
    }

    public function addEtiquette(Etiquette $etiquette): static
    {
        if (!$this->etiquettes->contains($etiquette)) {
            $this->etiquettes->add($etiquette);
            $etiquette->setLot($this);
        }

        return $this;
    }

    public function removeEtiquette(Etiquette $etiquette): static
    {
        if ($this->etiquettes->removeElement($etiquette)) {
            if ($etiquette->getLot() === $this) {
                $etiquette->setLot(null);
            }
        }

        return $this;
    }

    public function getNombreEtiquettesGenerees(): int
    {
        return $this->etiquettes->count();
    }

    public function estComplet(): bool
    {
        return $this->quantite > 0
            && $this->getNombreEtiquettesGenerees() === $this->quantite;
    }

    public function marquerCommePret(): static
    {
        if (!$this->estComplet()) {
            throw new \LogicException(
                'Le lot ne peut pas être terminé : le nombre de QR générés '
                . 'ne correspond pas à la quantité demandée.'
            );
        }

        $this->statut = self::STATUT_PRET;
        $this->termineLe = new \DateTimeImmutable();

        return $this;
    }

    public function marquerCommeEchec(): static
    {
        $this->statut = self::STATUT_ECHEC;
        $this->termineLe = new \DateTimeImmutable();

        return $this;
    }

    public function archiver(): static
    {
        if ($this->statut !== self::STATUT_PRET) {
            throw new \LogicException(
                'Seul un lot prêt peut être archivé.'
            );
        }

        $this->statut = self::STATUT_ARCHIVE;

        return $this;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Nouveau lot';
    }
}