<?php

namespace App\Entity;

use App\Repository\TypesImpressionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypesImpressionRepository::class)]
class TypesImpression
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    /**
     * @var Collection<int, CommandesDetails>
     */
   #[ORM\OneToMany(
    targetEntity: CommandesDetails::class,
    mappedBy: 'typeImpression'
)]
private Collection $commandesDetails;
    /**
     * @var Collection<int, Finition>
     */
    #[ORM\ManyToMany(targetEntity: Finition::class, mappedBy: 'typesImpressions')]
    private Collection $finitions;

    #[ORM\Column(length: 255)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $publie = null;

    #[ORM\Column]
    private ?int $ordre = null;
     /**
 * @var Collection<int, Supports>
 */
#[ORM\ManyToMany(
    targetEntity: Supports::class,
    mappedBy: 'typesImpressions'
)]
private Collection $supports;


    public function __construct()
    {
        $this->commandesDetails = new ArrayCollection();
        $this->finitions = new ArrayCollection();
        $this->formats = new ArrayCollection();
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
    /**
 * @var Collection<int, Format>
 */
#[ORM\ManyToMany(
    targetEntity: Format::class,
    mappedBy: 'typesImpressions'
)]
private Collection $formats;

    /**
     * @return Collection<int, CommandesDetails>
     */
    public function getCommandesDetails(): Collection
    {
        return $this->commandesDetails;
    }

    public function addCommandesDetail(CommandesDetails $commandesDetail): static
    {
        if (!$this->commandesDetails->contains($commandesDetail)) {
            $this->commandesDetails->add($commandesDetail);
            $commandesDetail->setTypeImpression($this);
        }

        return $this;
    }

    public function removeCommandesDetail(CommandesDetails $commandesDetail): static
    {
        if ($this->commandesDetails->removeElement($commandesDetail)) {
            // set the owning side to null (unless already changed)
            if ($commandesDetail->getTypeImpression() === $this) {
                $commandesDetail->setTypeImpression(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Finition>
     */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

   public function addFinition(Finition $finition): static
{
    if (!$this->finitions->contains($finition)) {
        $this->finitions->add($finition);

        if (!$finition->getTypesImpressions()->contains($this)) {
            $finition->addTypeImpression($this);
        }
    }

    return $this;
}

public function removeFinition(Finition $finition): static
{
    if ($this->finitions->removeElement($finition)) {
        if ($finition->getTypesImpressions()->contains($this)) {
            $finition->removeTypeImpression($this);
        }
    }

    return $this;
}
/**
 * @return Collection<int, Format>
 */
public function getFormats(): Collection
{
    return $this->formats;
}

public function addFormat(Format $format): static
{
    if (!$this->formats->contains($format)) {
        $this->formats->add($format);

        if (!$format->getTypesImpressions()->contains($this)) {
            $format->addTypeImpression($this);
        }
    }

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
        $support->addTypeImpression($this);
    }

    return $this;
}

public function removeSupport(Supports $support): static
{
    if ($this->supports->removeElement($support)) {
        $support->removeTypeImpression($this);
    }

    return $this;
}

public function removeFormat(Format $format): static
{
    if ($this->formats->removeElement($format)) {
        if ($format->getTypesImpressions()->contains($this)) {
            $format->removeTypeImpression($this);
        }
    }

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
}
