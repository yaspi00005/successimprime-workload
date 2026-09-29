<?php

namespace App\Entity;

use App\Repository\StockEntreesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockEntreesRepository::class)]
class StockEntrees
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stockEntrees')]
    private ?Articles $article = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $quantites = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $prix = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $date = null;

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

    public function getQuantites(): ?int
    {
        return $this->quantites;
    }

    public function setQuantites(int $quantites): static
    {
        $this->quantites = $quantites;

        return $this;
    }

    public function getPrix(): ?int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = $prix;

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
}
