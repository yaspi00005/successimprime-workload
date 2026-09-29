<?php

namespace App\Entity;

use App\Repository\ProduitsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitsRepository::class)]
class Produits
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 1000)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $publie = true;

    #[ORM\Column(options: ['default' => 10])]
    private int $ordre = 10;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $code = null;

    #[ORM\Column(nullable: true)]
    private ?float $prixBase = null;
    /**
 * Prix net réservé aux clients B2B.
 *
 * Le prix affiché sur la facture restera prixBase.
 * La remise sera : prixBase - prixB2B.
 */
#[ORM\Column(nullable: true)]
private ?float $prixB2B = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $personnalisable = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /**
     * Catégorie du produit.
     */
    #[ORM\ManyToOne(inversedBy: 'produits')]
    #[ORM\JoinColumn(nullable: false)]
    private ?CategorieProduit $categorieProduit = null;

    /**
     * Types d’impression compatibles.
     *
     * @var Collection<int, TypesImpression>
     */
    #[ORM\ManyToMany(targetEntity: TypesImpression::class)]
    private Collection $typesImpressions;

    /**
     * Supports compatibles.
     *
     * @var Collection<int, Supports>
     */
    #[ORM\ManyToMany(targetEntity: Supports::class)]
    private Collection $supports;

    /**
     * Formats compatibles.
     *
     * @var Collection<int, Format>
     */
    #[ORM\ManyToMany(targetEntity: Format::class)]
    private Collection $formats;

    /**
     * Finitions compatibles.
     *
     * Cette relation générale peut être conservée pour les filtres rapides.
     *
     * @var Collection<int, Finition>
     */
    #[ORM\ManyToMany(targetEntity: Finition::class)]
    private Collection $finitions;

    /**
     * Détails des commandes associés au produit.
     *
     * @var Collection<int, CommandesDetails>
     */
    #[ORM\OneToMany(
        targetEntity: CommandesDetails::class,
        mappedBy: 'produit'
    )]
    private Collection $commandesDetails;

    /**
     * Configurations précises autorisées pour ce produit.
     *
     * @var Collection<int, ProduitConfiguration>
     */
    #[ORM\OneToMany(
        targetEntity: ProduitConfiguration::class,
        mappedBy: 'produit',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $configurations;

   #[ORM\Column(
    length: 30,
    options: ['default' => 'unite']
)]
#[Assert\Choice(choices: [
    'forfait',
    'unite',
    'heure',
    'feuille',
    'exemplaire',
    'metre',
    'metre_carre',
    'point',
    'face',
])]
private string $modeCalcul = 'unite';

#[ORM\Column(options: ['default' => false])]
private bool $gestionStock = false;

#[ORM\ManyToOne]
#[ORM\JoinColumn(
    nullable: true,
    onDelete: 'SET NULL'
)]
private ?Articles $articleStock = null;

/**
 * @var Collection<int, ProduitArticleStock>
 */
#[ORM\OneToMany(
    mappedBy: 'produit',
    targetEntity: ProduitArticleStock::class,
    cascade: ['persist', 'remove'],
    orphanRemoval: true
)]
private Collection $articlesStock;

    public function __construct()
    {
        $this->typesImpressions = new ArrayCollection();
        $this->supports = new ArrayCollection();
        $this->formats = new ArrayCollection();
        $this->finitions = new ArrayCollection();
        $this->commandesDetails = new ArrayCollection();
        $this->configurations = new ArrayCollection();
        $this->articlesStock = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getPrixBase(): ?float
    {
        return $this->prixBase;
    }

    public function setPrixBase(?float $prixBase): static
    {
        $this->prixBase = $prixBase;

        return $this;
    }

    public function isPersonnalisable(): bool
    {
        return $this->personnalisable;
    }

    public function setPersonnalisable(bool $personnalisable): static
    {
        $this->personnalisable = $personnalisable;

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

    public function getCategorieProduit(): ?CategorieProduit
    {
        return $this->categorieProduit;
    }

    public function setCategorieProduit(
        ?CategorieProduit $categorieProduit
    ): static {
        $this->categorieProduit = $categorieProduit;

        return $this;
    }

    /**
     * @return Collection<int, TypesImpression>
     */
    public function getTypesImpressions(): Collection
    {
        return $this->typesImpressions;
    }

    public function addTypeImpression(
        TypesImpression $typeImpression
    ): static {
        if (!$this->typesImpressions->contains($typeImpression)) {
            $this->typesImpressions->add($typeImpression);
        }

        return $this;
    }

    public function removeTypeImpression(
        TypesImpression $typeImpression
    ): static {
        $this->typesImpressions->removeElement($typeImpression);

        return $this;
    }

    /**
     * @return Collection<int, Supports>
     */
    public function getSupports(): Collection
    {
        return $this->supports;
    }

    public function addSupport(Supports $support): static
    {
        if (!$this->supports->contains($support)) {
            $this->supports->add($support);
        }

        return $this;
    }

    public function removeSupport(Supports $support): static
    {
        $this->supports->removeElement($support);

        return $this;
    }

    /**
     * @return Collection<int, Format>
     */
    public function getFormats(): Collection
    {
        return $this->formats;
    }

    public function addFormat(Format $format): static
    {
        if (!$this->formats->contains($format)) {
            $this->formats->add($format);
        }

        return $this;
    }

    public function removeFormat(Format $format): static
    {
        $this->formats->removeElement($format);

        return $this;
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

    /**
     * @return Collection<int, CommandesDetails>
     */
    public function getCommandesDetails(): Collection
    {
        return $this->commandesDetails;
    }

    public function addCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if (!$this->commandesDetails->contains($commandesDetail)) {
            $this->commandesDetails->add($commandesDetail);
            $commandesDetail->setProduit($this);
        }

        return $this;
    }

    public function removeCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if (
            $this->commandesDetails->removeElement($commandesDetail)
            && $commandesDetail->getProduit() === $this
        ) {
            $commandesDetail->setProduit(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, ProduitConfiguration>
     */
    public function getConfigurations(): Collection
    {
        return $this->configurations;
    }

    public function addConfiguration(
        ProduitConfiguration $configuration
    ): static {
        if (!$this->configurations->contains($configuration)) {
            $this->configurations->add($configuration);
            $configuration->setProduit($this);
        }

        return $this;
    }

    public function removeConfiguration(
        ProduitConfiguration $configuration
    ): static {
        if (
            $this->configurations->removeElement($configuration)
            && $configuration->getProduit() === $this
        ) {
            $configuration->setProduit(null);
        }

        return $this;
    }

    public function getPrixB2B(): ?float
{
    return $this->prixB2B;
}

public function setPrixB2B(?float $prixB2B): static
{
    if ($prixB2B === null) {
        $this->prixB2B = null;

        return $this;
    }

    $prixB2B = max(0, $prixB2B);

    if (
        $this->prixBase !== null
        && $prixB2B > $this->prixBase
    ) {
        throw new \DomainException(
            'Le prix B2B ne peut pas dépasser le prix normal du produit.'
        );
    }

    $this->prixB2B = $prixB2B;

    return $this;
}

/**
 * Taux de remise B2B en pourcentage, dérivé de prixBase et prixB2B.
 * Source unique pour tout calcul de remise B2B sur ce produit
 * (configurations comprises).
 */
public function getRemiseB2B(): float
{
    $prixBase = (float) ($this->prixBase ?? 0);
    $prixB2B = $this->prixB2B;

    if ($prixBase <= 0 || $prixB2B === null) {
        return 0.0;
    }

    $prixB2B = (float) $prixB2B;

    if ($prixB2B < 0 || $prixB2B >= $prixBase) {
        return 0.0;
    }

    return (($prixBase - $prixB2B) / $prixBase) * 100;
}
public function getModeCalcul(): string
{
    return $this->modeCalcul;
}

public function setModeCalcul(?string $modeCalcul): static
{
    $modesAutorises = [
        'forfait',
        'unite',
        'heure',
        'feuille',
        'exemplaire',
        'metre',
        'metre_carre',
        'point',
        'face',
    ];

    $modeCalcul = strtolower(
        trim($modeCalcul ?? '')
    );

    $this->modeCalcul = in_array(
        $modeCalcul,
        $modesAutorises,
        true
    ) ? $modeCalcul : 'unite';

    return $this;
}

public function utiliseSurface(): bool
{
    return $this->modeCalcul === 'metre_carre';
}

public function utiliseLongueur(): bool
{
    return $this->modeCalcul === 'metre';
}

public function estForfaitaire(): bool
{
    return $this->modeCalcul === 'forfait';
}
public function isGestionStock(): bool
{
    return $this->gestionStock;
}

public function setGestionStock(
    bool $gestionStock
): static {
    $this->gestionStock = $gestionStock;

    return $this;
}

public function getArticleStock(): ?Articles
{
    return $this->articleStock;
}

public function setArticleStock(
    ?Articles $articleStock
): static {
    $this->articleStock = $articleStock;

    return $this;
}

/**
 * @return Collection<int, ProduitArticleStock>
 */
public function getArticlesStock(): Collection
{
    return $this->articlesStock;
}

public function addArticlesStock(
    ProduitArticleStock $articleStock
): static {
    if (!$this->articlesStock->contains($articleStock)) {
        $this->articlesStock->add($articleStock);
        $articleStock->setProduit($this);
    }

    return $this;
}

public function removeArticlesStock(
    ProduitArticleStock $articleStock
): static {
    if ($this->articlesStock->removeElement($articleStock)) {
        if ($articleStock->getProduit() === $this) {
            $articleStock->setProduit(null);
        }
    }

    return $this;
}

}