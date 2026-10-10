<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\ProduitClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Produit deja valide d'un client (« Mes produits », docs/mes-produits.md) :
 * format, fichier et BAT figes, prix negocie et unite de vente. Le client
 * le recommande en pieces, en m2 ou par lot ; changer le visuel ou la
 * taille cree un nouveau produit qui remplace celui-ci.
 */
#[ORM\Entity(repositoryClass: ProduitClientRepository::class)]
#[ORM\Index(columns: ['client_id', 'actif'])]
class ProduitClient
{
    use PublicIdTrait;

    public const UNITE_PIECE = 'piece';
    public const UNITE_M2 = 'm2';
    public const UNITE_PIECE_OU_M2 = 'piece_ou_m2';
    public const UNITE_LOT = 'lot';

    public const UNITES_LABELS = [
        self::UNITE_PIECE => 'À la pièce',
        self::UNITE_M2 => 'Au m²',
        self::UNITE_PIECE_OU_M2 => 'Pièce ou m², au choix',
        self::UNITE_LOT => 'Par lot',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Clients $client = null;

    /*
     * Reference lisible, ex. PC-AWA-01, reprise sur la ligne de commande.
     */
    #[ORM\Column(length: 30, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Produits $produit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supports $support = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Machines $machine = null;

    /*
     * Format en metres, comme les lignes de commande. Une hauteur vide signifie « longueur libre » (vinyle,
     * bache au metre) : la largeur est alors celle du rouleau.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $largeur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $hauteur = null;

    /**
     * @var Collection<int, Finition>
     */
    #[ORM\ManyToMany(targetEntity: Finition::class)]
    #[ORM\JoinTable(name: 'produit_client_finition')]
    private Collection $finitions;

    #[ORM\Column(length: 20, options: ['default' => self::UNITE_PIECE])]
    #[Assert\Choice(choices: [self::UNITE_PIECE, self::UNITE_M2, self::UNITE_PIECE_OU_M2, self::UNITE_LOT])]
    private string $uniteVente = self::UNITE_PIECE;

    /*
     * Prix negocie TTC par unite de vente : la piece, le m2 ou le lot.
     */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $prixUnitaire = 0;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    private int $quantiteMinimum = 1;

    #[ORM\Column(nullable: true)]
    private ?int $tailleLot = null;

    #[ORM\Column(nullable: true)]
    private ?int $posesParFeuille = null;

    /*
     * En m2 sur un format fixe : true = toujours le nombre de pieces
     * superieur ; false = le client choisit entre superieur et inferieur.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $arrondiSuperieurImpose = false;

    /*
     * Fichier et BAT valides, figes : une commande de ce produit part en
     * production sans repasser par la PAO.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $fichier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ControlePrePresse $batValide = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CommandesDetails $ligneOrigine = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $remplace = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->finitions = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getProduit(): ?Produits
    {
        return $this->produit;
    }

    public function setProduit(?Produits $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getSupport(): ?Supports
    {
        return $this->support;
    }

    public function setSupport(?Supports $support): static
    {
        $this->support = $support;

        return $this;
    }

    public function getMachine(): ?Machines
    {
        return $this->machine;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getLargeur(): ?float
    {
        return null === $this->largeur ? null : (float) $this->largeur;
    }

    public function getHauteur(): ?float
    {
        return null === $this->hauteur ? null : (float) $this->hauteur;
    }

    /**
     * Dimensions en metres ; une hauteur nulle = longueur libre sur rouleau.
     */
    public function setFormat(?float $largeur, ?float $hauteur): static
    {
        $this->largeur = null === $largeur ? null : number_format($largeur, 4, '.', '');
        $this->hauteur = null === $hauteur ? null : number_format($hauteur, 4, '.', '');

        return $this;
    }

    public function estFormatFixe(): bool
    {
        return null !== $this->largeur && null !== $this->hauteur;
    }

    public function estLongueurLibre(): bool
    {
        return null !== $this->largeur && null === $this->hauteur;
    }

    /**
     * @return Collection<int, Finition>
     */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

    public function addFinition(Finition $finition): static
    {
        if (!$this->finitions->contains($finition)) {
            $this->finitions->add($finition);
        }

        return $this;
    }

    public function removeFinition(Finition $finition): static
    {
        $this->finitions->removeElement($finition);

        return $this;
    }

    public function getUniteVente(): string
    {
        return $this->uniteVente;
    }

    public function setUniteVente(string $uniteVente): static
    {
        $this->uniteVente = $uniteVente;

        return $this;
    }

    public function accepteLesPieces(): bool
    {
        return \in_array($this->uniteVente, [self::UNITE_PIECE, self::UNITE_PIECE_OU_M2], true);
    }

    public function accepteLesM2(): bool
    {
        return \in_array($this->uniteVente, [self::UNITE_M2, self::UNITE_PIECE_OU_M2], true);
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(int $prixUnitaire): static
    {
        $this->prixUnitaire = max(0, $prixUnitaire);

        return $this;
    }

    public function getQuantiteMinimum(): int
    {
        return $this->quantiteMinimum;
    }

    public function setQuantiteMinimum(int $quantiteMinimum): static
    {
        $this->quantiteMinimum = max(1, $quantiteMinimum);

        return $this;
    }

    public function getTailleLot(): ?int
    {
        return $this->tailleLot;
    }

    public function setTailleLot(?int $tailleLot): static
    {
        $this->tailleLot = $tailleLot;

        return $this;
    }

    public function getPosesParFeuille(): ?int
    {
        return $this->posesParFeuille;
    }

    public function setPosesParFeuille(?int $posesParFeuille): static
    {
        $this->posesParFeuille = $posesParFeuille;

        return $this;
    }

    public function isArrondiSuperieurImpose(): bool
    {
        return $this->arrondiSuperieurImpose;
    }

    public function setArrondiSuperieurImpose(bool $arrondiSuperieurImpose): static
    {
        $this->arrondiSuperieurImpose = $arrondiSuperieurImpose;

        return $this;
    }

    public function getFichier(): ?CommandeDetailFichier
    {
        return $this->fichier;
    }

    public function setFichier(?CommandeDetailFichier $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getBatValide(): ?ControlePrePresse
    {
        return $this->batValide;
    }

    public function setBatValide(?ControlePrePresse $batValide): static
    {
        $this->batValide = $batValide;

        return $this;
    }

    public function getLigneOrigine(): ?CommandesDetails
    {
        return $this->ligneOrigine;
    }

    public function setLigneOrigine(?CommandesDetails $ligneOrigine): static
    {
        $this->ligneOrigine = $ligneOrigine;

        return $this;
    }

    public function getRemplace(): ?self
    {
        return $this->remplace;
    }

    public function setRemplace(?self $remplace): static
    {
        $this->remplace = $remplace;

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

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
