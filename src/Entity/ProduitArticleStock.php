<?php

namespace App\Entity;

use App\Repository\ProduitArticleStockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProduitArticleStockRepository::class)]
#[ORM\Table(name: 'produit_article_stock')]
#[ORM\UniqueConstraint(
    name: 'uniq_produit_article_stock',
    columns: [
        'produit_id',
        'article_id',
    ]
)]
class ProduitArticleStock
{
    public const MODE_UNITE = 'unite';
    public const MODE_QUANTITE = 'quantite';
    public const MODE_SURFACE = 'surface';
    public const MODE_METRE = 'metre';
    public const MODE_FORFAIT = 'forfait';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    #[ORM\ManyToOne(
        inversedBy: 'articlesStock'
    )]
    #[ORM\JoinColumn(
        name: 'produit_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Produits $produit = null;


    #[ORM\ManyToOne(
        inversedBy: 'produitsStock'
    )]
    #[ORM\JoinColumn(
        name: 'article_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Articles $article = null;


    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 12,
        scale: 3,
        options: [
            'default' => '1.000',
        ]
    )]
    private string $coefficient = '1.000';


    #[ORM\Column(
        length: 30,
        options: [
            'default' => self::MODE_QUANTITE,
        ]
    )]
    private string $modeCalcul =
        self::MODE_QUANTITE;


    #[ORM\Column(
        options: [
            'default' => true,
        ]
    )]
    private bool $actif = true;


    #[ORM\Column(
        options: [
            'default' => true,
        ]
    )]
    private bool $obligatoire = true;


    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $observation = null;


    #[ORM\Column(
        options: [
            'default' => 10,
        ]
    )]
    private int $ordre = 10;


    public function getId(): ?int
    {
        return $this->id;
    }


    public function getProduit(): ?Produits
    {
        return $this->produit;
    }


    public function setProduit(
        ?Produits $produit
    ): static {
        $this->produit = $produit;

        return $this;
    }


    public function getArticle(): ?Articles
    {
        return $this->article;
    }


    public function setArticle(
        ?Articles $article
    ): static {
        $this->article = $article;

        return $this;
    }


    public function getCoefficient(): string
    {
        return $this->coefficient;
    }


    public function setCoefficient(
        string|int|float $coefficient
    ): static {
        $valeur = (float) $coefficient;

        if ($valeur <= 0) {
            throw new \InvalidArgumentException(
                'Le coefficient de consommation doit être supérieur à zéro.'
            );
        }

        $this->coefficient = number_format(
            $valeur,
            3,
            '.',
            ''
        );

        return $this;
    }


    public function getModeCalcul(): string
    {
        return $this->modeCalcul;
    }


    public function setModeCalcul(
        string $modeCalcul
    ): static {
        $modesAutorises = [
            self::MODE_UNITE,
            self::MODE_QUANTITE,
            self::MODE_SURFACE,
            self::MODE_METRE,
            self::MODE_FORFAIT,
        ];

        if (
            !in_array(
                $modeCalcul,
                $modesAutorises,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Mode de calcul de stock invalide.'
            );
        }

        $this->modeCalcul = $modeCalcul;

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


    public function isObligatoire(): bool
    {
        return $this->obligatoire;
    }


    public function setObligatoire(
        bool $obligatoire
    ): static {
        $this->obligatoire = $obligatoire;

        return $this;
    }


    public function getObservation(): ?string
    {
        return $this->observation;
    }


    public function setObservation(
        ?string $observation
    ): static {
        $this->observation = $observation;

        return $this;
    }


    public function getOrdre(): int
    {
        return $this->ordre;
    }


    public function setOrdre(
        int $ordre
    ): static {
        $this->ordre = $ordre;

        return $this;
    }


    public function calculerQuantitePourDetail(
        CommandesDetails $detail
    ): float {
        $coefficient =
            (float) $this->coefficient;

        $quantite =
            max(
                1,
                (int) $detail->getQuantite()
            );

        return match ($this->modeCalcul) {
            self::MODE_SURFACE =>
                max(
                    0,
                    (float) (
                        $detail->getSurface()
                        ?? 0
                    )
                )
                * $quantite
                * $coefficient,

            self::MODE_METRE =>
                max(
                    0,
                    (float) (
                        $detail->getLongueur()
                        ?? 0
                    )
                )
                * $quantite
                * $coefficient,

            self::MODE_FORFAIT =>
                $coefficient,

            self::MODE_UNITE,
            self::MODE_QUANTITE =>
                $quantite
                * $coefficient,

            default => 0,
        };
    }
}