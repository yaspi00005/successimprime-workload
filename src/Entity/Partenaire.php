<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\PartenaireRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Partenaire de la bande « Ils nous font confiance » (docs/site-public.md).
 * Son logo n'est montré qu'avec son accord : la case « accord obtenu » est
 * obligatoire pour qu'il soit visible.
 */
#[ORM\Entity(repositoryClass: PartenaireRepository::class)]
#[ORM\Table(name: 'site_partenaire')]
class Partenaire
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $nom = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImageSite $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lien = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $visible = false;

    #[ORM\Column]
    private bool $accordObtenu = false;

    public function __construct()
    {
        $this->initialiserPublicId();
    }

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
        $this->nom = mb_substr(trim($nom), 0, 120);

        return $this;
    }

    public function getLogo(): ?ImageSite
    {
        return $this->logo;
    }

    public function setLogo(?ImageSite $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    /** Seulement une adresse web (http ou https) : sinon, pas de lien. */
    public function setLien(?string $lien): static
    {
        $lien = trim((string) $lien);
        $this->lien = '' !== $lien && \strlen($lien) <= 255 && preg_match('#^https?://[^\s<>"]+$#i', $lien) ? $lien : null;

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

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function isAccordObtenu(): bool
    {
        return $this->accordObtenu;
    }

    public function setAccordObtenu(bool $accord): static
    {
        $this->accordObtenu = $accord;

        return $this;
    }

    /** Montré sur le site : visible et avec l'accord du partenaire. */
    public function estVisible(): bool
    {
        return $this->visible && $this->accordObtenu && '' !== $this->nom;
    }

    /** @return list<string> */
    public function reglesDePublication(): array
    {
        $regles = [];
        if (!$this->accordObtenu) {
            $regles[] = 'Cochez « accord obtenu » : un logo ne s\'affiche qu\'avec l\'accord du partenaire.';
        }
        if ('' === $this->nom) {
            $regles[] = 'Un partenaire a besoin d\'un nom.';
        }

        return $regles;
    }
}
