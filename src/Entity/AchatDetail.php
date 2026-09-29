<?php

namespace App\Entity;

use App\Repository\AchatDetailRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AchatDetailRepository::class)]
class AchatDetail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Achats $achat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Articles $article = null;

    #[ORM\Column]
    #[Assert\Positive(message: 'La quantité doit être supérieure à zéro.')]
    private int $quantite = 1;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le prix unitaire ne peut pas être négatif.')]
    private int $prixUnitaire = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAchat(): ?Achats
    {
        return $this->achat;
    }

    public function setAchat(?Achats $achat): static
    {
        $this->achat = $achat;

        return $this;
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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(1, $quantite);
        $this->calculerTotalHt();

        return $this;
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(int $prixUnitaire): static
    {
        $this->prixUnitaire = max(0, $prixUnitaire);
        $this->calculerTotalHt();

        return $this;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    private function calculerTotalHt(): void
    {
        $this->totalHt = $this->quantite * $this->prixUnitaire;
    }
}
