<?php

namespace App\Entity;

use App\Repository\ConsommationEncresRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ConsommationEncresRepository::class)]
#[ORM\Table(name: 'consommation_encres')]
class ConsommationEncres
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        targetEntity: Production::class,
        inversedBy: 'consommationEncres'
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Production $production = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(
        message: 'La quantité d’encre doit être positive.'
    )]
    private int $encre = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(
        message: 'La quantité doit être positive.'
    )]
    private int $quantite = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(
        message: 'Le coût doit être positif.'
    )]
    private int $cout = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduction(): ?Production
    {
        return $this->production;
    }

    public function setProduction(?Production $production): static
    {
        $this->production = $production;

        return $this;
    }

    public function getEncre(): int
    {
        return $this->encre;
    }

    public function setEncre(?int $encre): static
    {
        $this->encre = max(0, $encre ?? 0);

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(?int $quantite): static
    {
        $this->quantite = max(0, $quantite ?? 0);

        return $this;
    }

    public function getCout(): int
    {
        return $this->cout;
    }

    public function setCout(?int $cout): static
    {
        $this->cout = max(0, $cout ?? 0);

        return $this;
    }

    public function getMontantTotal(): int
    {
        return $this->quantite * $this->cout;
    }
}