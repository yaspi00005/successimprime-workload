<?php

namespace App\Entity;

use App\Repository\ProduitConfigurationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProduitConfigurationRepository::class)]
#[ORM\Table(name: 'produit_configuration')]
#[ORM\UniqueConstraint(
    name: 'uniq_produit_configuration',
    columns: [
        'produit_id',
        'type_impression_id',
        'support_id',
        'format_id',
    ]
)]
class ProduitConfiguration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'configurations')]
    #[ORM\JoinColumn(
        name: 'produit_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Produits $produit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'type_impression_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?TypesImpression $typeImpression = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'support_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Supports $support = null;

   #[ORM\ManyToOne]
#[ORM\JoinColumn(
    name: 'format_id',
    referencedColumnName: 'id',
    nullable: true,
    onDelete: 'SET NULL'
)]
private ?Format $format = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $ordre = 10;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Prix normal B2C de cette configuration.
     */
    #[ORM\Column(nullable: true)]
    private ?int $prixBase = null;

    /**
     * Modes possibles :
     *
     * forfait
     * unite
     * metre
     * metre_carre
     * heure
     */
    #[ORM\Column(length: 30)]
    private string $modeCalcul = 'forfait';

    /**
     * Type de dimensions utilisé par cette configuration :
     *
     * format  : A4, A3, A2, carte de visite, etc.
     * mesure  : largeur × longueur en mètres.
     * aucune  : aucun format et aucune dimension.
     */
    #[ORM\Column(
        length: 20,
        options: ['default' => 'format']
    )]
    #[Assert\Choice(
        choices: ['format', 'mesure', 'aucune'],
        message: 'Le mode de dimensions est invalide.'
    )]
    private string $modeDimension = 'format';

    /**
     * Largeur imposée en mode automatique.
     * La valeur est exprimée en mètres.
     *
     * Exemple : 2 mètres.
     */
    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
        nullable: true
    )]
    #[Assert\PositiveOrZero]
    private ?string $largeurDefaut = null;

    /**
     * Longueur imposée en mode automatique.
     * La valeur est exprimée en mètres.
     *
     * Exemple : 100 mètres.
     */
    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
        nullable: true
    )]
    #[Assert\PositiveOrZero]
    private ?string $longueurDefaut = null;

    #[ORM\Column]
    private int $quantiteMinimale = 1;

    #[ORM\Column(nullable: true)]
    private ?int $quantiteMaximale = null;

    /**
     * Prix net réservé aux clients B2B.
     */
    /**
     * Prix net réservé aux clients B2B.
     */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $prixB2B = null;

    /**
     * @var Collection<int, ProduitConfigurationFinition>
     */
    #[ORM\OneToMany(
        targetEntity: ProduitConfigurationFinition::class,
        mappedBy: 'produitConfiguration',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['ordre' => 'ASC'])]
    private Collection $configurationFinitions;

    public function __construct()
    {
        $this->configurationFinitions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTypeImpression(): ?TypesImpression
    {
        return $this->typeImpression;
    }

    public function setTypeImpression(
        ?TypesImpression $typeImpression
    ): static {
        $this->typeImpression = $typeImpression;

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

    public function getFormat(): ?Format
    {
        return $this->format;
    }

    public function setFormat(?Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = max(0, $ordre);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description !== null
            ? trim($description)
            : null;

        $this->description = $description !== ''
            ? $description
            : null;

        return $this;
    }

    public function getPrixBase(): ?int
    {
        return $this->prixBase;
    }

    public function setPrixBase(?int $prixBase): static
    {
        $this->prixBase = $prixBase !== null
            ? max(0, $prixBase)
            : null;

        return $this;
    }

    public function getModeCalcul(): string
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(string $modeCalcul): static
    {
        $modesAutorises = [
            'forfait',
            'unite',
            'metre',
            'metre_carre',
            'heure',
        ];

        $modeCalcul = strtolower(trim($modeCalcul));

        $this->modeCalcul = in_array(
            $modeCalcul,
            $modesAutorises,
            true
        )
            ? $modeCalcul
            : 'forfait';

        return $this;
    }

    public function getQuantiteMinimale(): int
    {
        return $this->quantiteMinimale;
    }

    public function setQuantiteMinimale(
        int $quantiteMinimale
    ): static {
        $this->quantiteMinimale = max(
            1,
            $quantiteMinimale
        );

        if (
            $this->quantiteMaximale !== null
            && $this->quantiteMaximale < $this->quantiteMinimale
        ) {
            $this->quantiteMaximale = $this->quantiteMinimale;
        }

        return $this;
    }

    public function getQuantiteMaximale(): ?int
    {
        return $this->quantiteMaximale;
    }

    public function setQuantiteMaximale(
        ?int $quantiteMaximale
    ): static {
        if ($quantiteMaximale === null) {
            $this->quantiteMaximale = null;

            return $this;
        }

        $this->quantiteMaximale = max(
            $this->quantiteMinimale,
            $quantiteMaximale
        );

        return $this;
    }
    public function getModeDimension(): string
    {
        return $this->modeDimension;
    }

    public function setModeDimension(string $modeDimension): static
    {
        $modesAutorises = [
            'format',
            'mesure',
            'aucune',
        ];

        $modeDimension = strtolower(trim($modeDimension));

        $this->modeDimension = in_array(
            $modeDimension,
            $modesAutorises,
            true
        )
            ? $modeDimension
            : 'format';

        /*
     * Une configuration sans dimensions n’utilise pas
     * de largeur ou de longueur par défaut.
     */
        if ($this->modeDimension !== 'mesure') {
            $this->largeurDefaut = null;
            $this->longueurDefaut = null;
        }

        return $this;
    }

    public function getLargeurDefaut(): ?string
    {
        return $this->largeurDefaut;
    }

    public function setLargeurDefaut(
        string|float|int|null $largeurDefaut
    ): static {
        $this->largeurDefaut = $this->normaliserDecimal(
            $largeurDefaut,
            3
        );

        return $this;
    }

    public function getLongueurDefaut(): ?string
    {
        return $this->longueurDefaut;
    }

    public function setLongueurDefaut(
        string|float|int|null $longueurDefaut
    ): static {
        $this->longueurDefaut = $this->normaliserDecimal(
            $longueurDefaut,
            3
        );

        return $this;
    }

    /**
     * Surface imposée par la configuration, en m².
     */
    public function getSurfaceDefaut(): ?string
    {
        if (
            $this->modeDimension !== 'mesure'
            || $this->largeurDefaut === null
            || $this->longueurDefaut === null
        ) {
            return null;
        }

        return number_format(
            (float) $this->largeurDefaut
                * (float) $this->longueurDefaut,
            4,
            '.',
            ''
        );
    }

    /**
     * Indique que la configuration utilise un format fixe :
     * A4, A3, A2, carte de visite, etc.
     */
    public function utiliseFormatFixe(): bool
    {
        return $this->modeDimension === 'format';
    }

    /**
     * Indique que largeur, longueur et surface doivent apparaître.
     */
    public function utiliseDimensions(): bool
    {
        return $this->modeDimension === 'mesure';
    }

    /**
     * Indique que seuls la quantité et le prix sont nécessaires.
     */
    public function estSansDimensions(): bool
    {
        return $this->modeDimension === 'aucune';
    }

    /**
     * Vérifie si les dimensions automatiques sont renseignées.
     */
    public function possedeDimensionsParDefaut(): bool
    {
        return $this->utiliseDimensions()
            && $this->largeurDefaut !== null
            && $this->longueurDefaut !== null
            && (float) $this->largeurDefaut > 0
            && (float) $this->longueurDefaut > 0;
    }

    /**
     * Retourne le prix correspondant au profil du client.
     */
    public function getPrixApplicable(bool $clientB2B = false): int
    {
        if ($clientB2B && $this->prixB2B !== null) {
            return $this->prixB2B;
        }

        return $this->prixBase ?? 0;
    }

    /**
     * Calcule le prix de base, sans les finitions.
     */
    public function calculerMontant(
        int $quantite,
        ?float $largeur = null,
        ?float $longueur = null,
        bool $clientB2B = false
    ): int {
        $quantite = max(1, $quantite);
        $prix = $this->getPrixApplicable($clientB2B);

        return match ($this->modeCalcul) {
            'unite' => (int) round($prix * $quantite),

            'metre' => (int) round(
                $prix
                    * max(0, $longueur ?? 0)
                    * $quantite
            ),

            'metre_carre' => (int) round(
                $prix
                    * max(0, $largeur ?? 0)
                    * max(0, $longueur ?? 0)
                    * $quantite
            ),

            'heure' => (int) round($prix * $quantite),

            default => $prix,
        };
    }

    private function normaliserDecimal(
        string|float|int|null $valeur,
        int $precision
    ): ?string {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        $valeurNormalisee = str_replace(
            ',',
            '.',
            trim((string) $valeur)
        );

        if (!is_numeric($valeurNormalisee)) {
            return null;
        }

        return number_format(
            max(0, (float) $valeurNormalisee),
            $precision,
            '.',
            ''
        );
    }
    /**
     * @return Collection<int, ProduitConfigurationFinition>
     */
    public function getConfigurationFinitions(): Collection
    {
        return $this->configurationFinitions;
    }

    public function addConfigurationFinition(
        ProduitConfigurationFinition $configurationFinition
    ): static {
        if (
            !$this->configurationFinitions
                ->contains($configurationFinition)
        ) {
            $this->configurationFinitions->add(
                $configurationFinition
            );

            $configurationFinition
                ->setProduitConfiguration($this);
        }

        return $this;
    }

    public function removeConfigurationFinition(
        ProduitConfigurationFinition $configurationFinition
    ): static {
        if (
            $this->configurationFinitions
            ->removeElement($configurationFinition)
            && $configurationFinition
            ->getProduitConfiguration() === $this
        ) {
            $configurationFinition
                ->setProduitConfiguration(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s — %s — %s',
            $this->produit?->getNom() ?? 'Produit',
            $this->typeImpression?->getNom() ?? 'Type d’impression',
            $this->support?->getNom() ?? 'Support',
            $this->format?->getNom() ?? 'Format'
        );
    }
    public function getPrixB2B(): ?int
    {
        return $this->prixB2B;
    }

    public function setPrixB2B(?int $prixB2B): static
    {
        $this->prixB2B = $prixB2B !== null
            ? max(0, $prixB2B)
            : null;

        return $this;
    }

    /**
     * Taux de remise B2B dérivé du Produit parent (pas du prixB2B
     * propre à la configuration) — cf. règle métier documentée :
     * la remise B2B en configuration automatique vient du Produit.
     */
    public function getRemiseB2BProduit(): float
    {
        return $this->produit?->getRemiseB2B() ?? 0.0;
    }

    /**
     * Prix B2B théorique de cette configuration, calculé en appliquant
     * la remise B2B du Produit au prixBase de la configuration.
     */
    public function getPrixB2BCalcule(): int
    {
        $remise = $this->getRemiseB2BProduit();

        return (int) round(($this->prixBase ?? 0) * (1 - $remise / 100));
    }
}
