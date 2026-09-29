<?php

namespace App\Entity;

use App\Repository\StockSortiesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockSortiesRepository::class)]
class StockSorties
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stockSorties')]
    private ?Articles $article = null;

    #[ORM\ManyToOne(inversedBy: 'stockSorties')]
    private ?CommandesDetails $commandeDetail = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $quantite = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date = null;


    public const ORIGINE_PRODUCTION = 'production';
public const ORIGINE_LIVRAISON = 'livraison';
public const ORIGINE_MANUELLE = 'manuelle';

#[ORM\Column(
    length: 30,
    nullable: true
)]
private ?string $origine = null;

#[ORM\Column(
    length: 100,
    nullable: true
)]
private ?string $referenceOrigine = null;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): ?Articles
    {
        return $this->article;
    }

    public function setArticle(?Articles $article): static
    {
        $this->article = $article;

        return $this;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

    public function setCommandeDetail(?CommandesDetails $commandeDetail): static
    {
        $this->commandeDetail = $commandeDetail;

        return $this;
    }

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }
    public function getOrigine(): ?string
{
    return $this->origine;
}

public function setOrigine(
    ?string $origine
): static {
    if ($origine !== null) {
        $originesAutorisees = [
            self::ORIGINE_PRODUCTION,
            self::ORIGINE_LIVRAISON,
            self::ORIGINE_MANUELLE,
        ];

        if (!in_array(
            $origine,
            $originesAutorisees,
            true
        )) {
            throw new \InvalidArgumentException(
                'Origine de sortie de stock invalide.'
            );
        }
    }

    $this->origine = $origine;

    return $this;
}

public function getReferenceOrigine(): ?string
{
    return $this->referenceOrigine;
}

public function setReferenceOrigine(
    ?string $referenceOrigine
): static {
    $referenceOrigine =
        $referenceOrigine !== null
            ? trim($referenceOrigine)
            : null;

    $this->referenceOrigine =
        $referenceOrigine !== ''
            ? $referenceOrigine
            : null;

    return $this;
}
}
