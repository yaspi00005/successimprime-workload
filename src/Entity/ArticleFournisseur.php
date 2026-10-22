<?php

namespace App\Entity;

use App\Repository\ArticleFournisseurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fournisseur d'un article (maquette S02) : son prix par unité
 * d'achat, son délai de livraison, et s'il est le fournisseur habituel.
 */
#[ORM\Entity(repositoryClass: ArticleFournisseurRepository::class)]
#[ORM\Table(name: 'article_fournisseur')]
#[ORM\UniqueConstraint(name: 'uniq_article_fournisseur', columns: ['article_id', 'fournisseur_id'])]
class ArticleFournisseur
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'fournisseurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Articles $article = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Fournisseurs $fournisseur = null;

    /*
     * Prix HT d'une unité d'achat (un rouleau, un carton…), en F CFA.
     */
    #[ORM\Column(nullable: true)]
    private ?int $prixAchat = null;

    #[ORM\Column(nullable: true)]
    private ?int $delaiJours = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $principal = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referenceFournisseur = null;

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

    public function getFournisseur(): ?Fournisseurs
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseurs $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getPrixAchat(): ?int
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?int $prixAchat): static
    {
        $this->prixAchat = $prixAchat !== null ? max(0, $prixAchat) : null;

        return $this;
    }

    public function getDelaiJours(): ?int
    {
        return $this->delaiJours;
    }

    public function setDelaiJours(?int $delaiJours): static
    {
        $this->delaiJours = $delaiJours !== null ? max(0, $delaiJours) : null;

        return $this;
    }

    public function isPrincipal(): bool
    {
        return $this->principal;
    }

    public function setPrincipal(bool $principal): static
    {
        $this->principal = $principal;

        return $this;
    }

    public function getReferenceFournisseur(): ?string
    {
        return $this->referenceFournisseur;
    }

    public function setReferenceFournisseur(?string $referenceFournisseur): static
    {
        $referenceFournisseur = $referenceFournisseur !== null ? trim($referenceFournisseur) : null;
        $this->referenceFournisseur = $referenceFournisseur !== '' ? $referenceFournisseur : null;

        return $this;
    }
}
