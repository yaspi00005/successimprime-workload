<?php

namespace App\Entity;

use App\Repository\FinitionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FinitionRepository::class)]
class Finition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column]
    private ?bool $publie = null;

    #[ORM\Column]
    private ?int $ordre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    /**
     * @var Collection<int, TypesImpression>
     */
    #[ORM\ManyToMany(targetEntity: TypesImpression::class, inversedBy: 'finitions')]
    private Collection $typesImpressions;

    /**
 * @var Collection<int, Supports>
 */
#[ORM\ManyToMany(
    targetEntity: Supports::class,
    mappedBy: 'finitions'
)]
private Collection $supports;


    public function __construct()
    {
        $this->typesImpressions = new ArrayCollection();
        $this->supports = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function isPublie(): ?bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, TypesImpression>
     */
    public function getTypesImpressions(): Collection
    {
        return $this->typesImpressions;
    }

    public function addTypeImpression(TypesImpression $typeImpression): static
    {
        if (!$this->typesImpressions->contains($typeImpression)) {
            $this->typesImpressions->add($typeImpression);
        }

        return $this;
    }

    public function removeTypeImpression(TypesImpression $typesImpression): static
    {
        $this->typesImpressions->removeElement($typesImpression);

        return $this;
    }

    /**
 * @return Collection<int, Support>
 */
public function getSupports(): Collection
{
    return $this->supports;
}

public function addSupport(Supports $support): static
{
    if (!$this->supports->contains($support)) {
        $this->supports->add($support);
        $support->addFinition($this);
    }

    return $this;
}

public function removeSupport(Supports $supports): static
{
    if ($this->supports->removeElement($supports)) {
        $supports->removeFinition($this);
    }

    return $this;
}
}
