<?php

namespace App\Entity;

use App\Repository\FamilleArticleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Famille d'articles du stock (maquette S01) : Supports, Encres, DTF,
 * Textile, Finition, Papier… Réglable sans développement.
 */
#[ORM\Entity(repositoryClass: FamilleArticleRepository::class)]
#[ORM\Table(name: 'famille_article')]
class FamilleArticle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $nom = '';

    /*
     * Préfixe des codes articles de la famille : MAT, ENC, DTF…
     */
    #[ORM\Column(length: 10)]
    private string $prefixe = '';

    #[ORM\Column(length: 7, options: ['default' => '#1d4ed8'])]
    private string $couleur = '#1d4ed8';

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getPrefixe(): string
    {
        return $this->prefixe;
    }

    public function setPrefixe(string $prefixe): static
    {
        $this->prefixe = strtoupper(trim($prefixe));

        return $this;
    }

    public function getCouleur(): string
    {
        return $this->couleur;
    }

    public function setCouleur(string $couleur): static
    {
        $this->couleur = preg_match('/^#[0-9a-f]{6}$/i', $couleur) ? strtolower($couleur) : '#1d4ed8';

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

    public function __toString(): string
    {
        return $this->nom;
    }
}
