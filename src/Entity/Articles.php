<?php

namespace App\Entity;

use App\Repository\ArticlesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ArticlesRepository::class)]
#[ORM\Table(name: 'articles')]
#[ORM\UniqueConstraint(
    name: 'uniq_article_reference',
    columns: ['reference']
)]
class Articles
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    private ?string $designation = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column(length: 30)]
    private string $unite = 'unite';

    /*
     * Ancien champ conservé temporairement pour compatibilité.
     *
     * Il ne représente PLUS le stock réel.
     *
     * Le stock réel est calculé par StockService :
     * StockEntrees - StockSorties.
     */
    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 14,
        scale: 3,
        options: ['default' => '0.000']
    )]
    private string $stock = '0.000';

    #[ORM\Column(
        name: 'stock_min',
        type: Types::DECIMAL,
        precision: 14,
        scale: 3,
        options: ['default' => '0.000']
    )]
    private string $stockMin = '0.000';

    #[ORM\Column(
        name: 'prix_achat',
        type: Types::INTEGER,
        nullable: true
    )]
    private ?int $prixAchat = null;

    #[ORM\Column(
        name: 'prix_vente',
        type: Types::INTEGER,
        nullable: true
    )]
    private ?int $prixVente = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fournisseurs $fournisseur = null;

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $description = null;

    #[ORM\Column(
        options: ['default' => true]
    )]
    private bool $actif = true;


    /**
     * @var Collection<int, StockEntrees>
     */
    #[ORM\OneToMany(
        targetEntity: StockEntrees::class,
        mappedBy: 'article'
    )]
    private Collection $stockEntrees;


    /**
     * @var Collection<int, StockSorties>
     */
    #[ORM\OneToMany(
        targetEntity: StockSorties::class,
        mappedBy: 'article'
    )]
    private Collection $stockSorties;

    #[ORM\Column(
    options: [
        'default' => true,
    ]
)]
private bool $vendable = true;

/*
 * Indépendant de "vendable" : un article peut être vendu
 * directement au client ET servir de matière première
 * consommée manuellement en production (ex. bâche vinyle),
 * alors qu'un article uniquement vendable (ex. kakémono) ne
 * doit pas apparaître dans l'écran Consommables.
 */
#[ORM\Column(
    options: [
        'default' => false,
    ]
)]
private bool $consommableProduction = false;



/**
 * @var Collection<int, ProduitArticleStock>
 */
#[ORM\OneToMany(
    mappedBy: 'article',
    targetEntity: ProduitArticleStock::class,
    cascade: ['persist', 'remove'],
    orphanRemoval: true
)]
private Collection $produitsStock;

  
    


    public function __construct()
    {
        $this->stockEntrees =
            new ArrayCollection();

        $this->stockSorties =
            new ArrayCollection();

        
    }


    public function getId(): ?int
    {
        return $this->id;
    }


    public function getReference(): ?string
    {
        return $this->reference;
    }


    public function setReference(
        string $reference
    ): static {
        $this->reference =
            strtoupper(
                trim($reference)
            );

        return $this;
    }


    public function getDesignation(): ?string
    {
        return $this->designation;
    }


    public function setDesignation(
        string $designation
    ): static {
        $this->designation =
            trim($designation);

        return $this;
    }


    public function getCategorie(): ?string
    {
        return $this->categorie;
    }


    public function setCategorie(
        ?string $categorie
    ): static {
        $categorie =
            $categorie !== null
                ? trim($categorie)
                : null;

        $this->categorie =
            $categorie !== ''
                ? $categorie
                : null;

        return $this;
    }


    public function getUnite(): string
    {
        return $this->unite;
    }


    public function setUnite(
        string $unite
    ): static {
        $unite =
            strtolower(
                trim($unite)
            );

        $this->unite =
            $unite !== ''
                ? $unite
                : 'unite';

        return $this;
    }


    /*
     * ============================================================
     * ANCIEN STOCK
     * ============================================================
     *
     * Ne pas utiliser pour les calculs métier.
     */
    public function getStock(): float
    {
        return (float) $this->stock;
    }


    public function setStock(
        float|int|string $stock
    ): static {
        $this->stock =
            number_format(
                max(
                    0,
                    (float) $stock
                ),
                3,
                '.',
                ''
            );

        return $this;
    }


    /*
     * ============================================================
     * SEUIL D'ALERTE
     * ============================================================
     */
    public function getStockMin(): float
    {
        return (float) $this->stockMin;
    }


    public function setStockMin(
        float|int|string $stockMin
    ): static {
        $this->stockMin =
            number_format(
                max(
                    0,
                    (float) $stockMin
                ),
                3,
                '.',
                ''
            );

        return $this;
    }


    /*
     * Alias utilisé par la page État du stock.
     */
    public function getSeuilAlerte(): float
    {
        return $this->getStockMin();
    }


    public function setSeuilAlerte(
        float|int|string $seuil
    ): static {
        return $this->setStockMin(
            $seuil
        );
    }


    public function getPrixAchat(): ?int
    {
        return $this->prixAchat;
    }


    public function setPrixAchat(
        ?int $prixAchat
    ): static {
        $this->prixAchat =
            $prixAchat !== null
                ? max(0, $prixAchat)
                : null;

        return $this;
    }


    public function getPrixVente(): ?int
    {
        return $this->prixVente;
    }


    public function setPrixVente(
        ?int $prixVente
    ): static {
        $this->prixVente =
            $prixVente !== null
                ? max(0, $prixVente)
                : null;

        return $this;
    }


    public function getFournisseur(): ?Fournisseurs
    {
        return $this->fournisseur;
    }


    public function setFournisseur(
        ?Fournisseurs $fournisseur
    ): static {
        $this->fournisseur = $fournisseur;

        return $this;
    }


    public function getDescription(): ?string
    {
        return $this->description;
    }


    public function setDescription(
        ?string $description
    ): static {
        $description =
            $description !== null
                ? trim($description)
                : null;

        $this->description =
            $description !== ''
                ? $description
                : null;

        return $this;
    }


    public function isActif(): bool
    {
        return $this->actif;
    }


    public function setActif(
        bool $actif
    ): static {
        $this->actif = $actif;

        return $this;
    }


    /**
     * @return Collection<int, StockEntrees>
     */
    public function getStockEntrees(): Collection
    {
        return $this->stockEntrees;
    }


    public function addStockEntree(
        StockEntrees $stockEntree
    ): static {
        if (
            !$this->stockEntrees
                ->contains($stockEntree)
        ) {
            $this->stockEntrees
                ->add($stockEntree);

            $stockEntree
                ->setArticle($this);
        }

        return $this;
    }


    public function removeStockEntree(
        StockEntrees $stockEntree
    ): static {
        if (
            $this->stockEntrees
                ->removeElement($stockEntree)
        ) {
            if (
                $stockEntree->getArticle()
                === $this
            ) {
                $stockEntree
                    ->setArticle(null);
            }
        }

        return $this;
    }


    /**
     * @return Collection<int, StockSorties>
     */
    public function getStockSorties(): Collection
    {
        return $this->stockSorties;
    }


    public function addStockSortie(
        StockSorties $stockSortie
    ): static {
        if (
            !$this->stockSorties
                ->contains($stockSortie)
        ) {
            $this->stockSorties
                ->add($stockSortie);

            $stockSortie
                ->setArticle($this);
        }

        return $this;
    }


    public function removeStockSortie(
        StockSorties $stockSortie
    ): static {
        if (
            $this->stockSorties
                ->removeElement($stockSortie)
        ) {
            if (
                $stockSortie->getArticle()
                === $this
            ) {
                $stockSortie
                    ->setArticle(null);
            }
        }

        return $this;
    }

    public function addProduitStock(
        ProduitArticleStock $liaison
    ): static {
        if (
            !$this->produitsStock
                ->contains($liaison)
        ) {
            $this->produitsStock
                ->add($liaison);

            $liaison
                ->setArticle($this);
        }

        return $this;
    }


    public function removeProduitStock(
        ProduitArticleStock $liaison
    ): static {
        if (
            $this->produitsStock
                ->removeElement($liaison)
        ) {
            if (
                $liaison->getArticle()
                === $this
            ) {
                $liaison
                    ->setArticle(null);
            }
        }

        return $this;
    }

public function isVendable(): bool
{
    return $this->vendable;
}

public function setVendable(
    bool $vendable
): static {
    $this->vendable = $vendable;

    return $this;
}

public function isConsommableProduction(): bool
{
    return $this->consommableProduction;
}

public function setConsommableProduction(
    bool $consommableProduction
): static {
    $this->consommableProduction = $consommableProduction;

    return $this;
}
    public function __toString(): string
    {
        return trim(
            sprintf(
                '%s - %s',
                $this->reference ?? '',
                $this->designation ?? ''
            )
        );
    }
   


/**
 * @return Collection<int, ProduitArticleStock>
 */
public function getProduitsStock(): Collection
{
    return $this->produitsStock;
}

public function addProduitsStock(
    ProduitArticleStock $produitStock
): static {
    if (!$this->produitsStock->contains($produitStock)) {
        $this->produitsStock->add($produitStock);
        $produitStock->setArticle($this);
    }

    return $this;
}

public function removeProduitsStock(
    ProduitArticleStock $produitStock
): static {
    if ($this->produitsStock->removeElement($produitStock)) {
        if ($produitStock->getArticle() === $this) {
            $produitStock->setArticle(null);
        }
    }

    return $this;
}


}