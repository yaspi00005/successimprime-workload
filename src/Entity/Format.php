<?php

namespace App\Entity;

use App\Repository\FormatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormatRepository::class)]
#[ORM\Table(name: 'format')]
class Format
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
private ?string $largeur = null;

#[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
private ?string $hauteur = null;

    #[ORM\Column(length: 10)]
    private string $unite = 'mm';

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column]
    private bool $publie = true;

    #[ORM\Column]
    private int $ordre = 0;

    /**
     * @var Collection<int, CommandesDetails>
     */
    #[ORM\OneToMany(
        targetEntity: CommandesDetails::class,
        mappedBy: 'format'
    )]
    private Collection $commandesDetails;

    /**
     * @var Collection<int, TypesImpression>
     */
    #[ORM\ManyToMany(
        targetEntity: TypesImpression::class,
        inversedBy: 'formats'
    )]
    #[ORM\JoinTable(name: 'format_types_impression')]
    private Collection $typesImpressions;

    /**
 * @var Collection<int, Supports>
 */
#[ORM\ManyToMany(
    targetEntity: Supports::class,
    mappedBy: 'formats'
)]
private Collection $supports;
    public function __construct()
    {
        $this->commandesDetails = new ArrayCollection();
        $this->typesImpressions = new ArrayCollection();
        $this->supports = new ArrayCollection();
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
        $support->addFormat($this);
    }

    return $this;
}

public function removeSupport(Supports $support): static
{
    if ($this->supports->removeElement($support)) {
        $support->removeFormat($this);
    }

    return $this;
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
        $this->nom = trim($nom);

        return $this;
    }

    public function getLargeur(): ?int
    {
        return $this->largeur;
    }

    public function setLargeur(int $largeur): static
    {
        $this->largeur = $largeur;

        return $this;
    }

    public function getHauteur(): ?int
    {
        return $this->hauteur;
    }

    public function setHauteur(int $hauteur): static
    {
        $this->hauteur = $hauteur;

        return $this;
    }

    public function getUnite(): string
    {
        return $this->unite;
    }

    public function setUnite(string $unite): static
    {
        $this->unite = trim($unite);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = trim($description);

        return $this;
    }

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

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

    /**
     * @return Collection<int, CommandesDetails>
     */
    public function getCommandesDetails(): Collection
    {
        return $this->commandesDetails;
    }

    public function addCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if (!$this->commandesDetails->contains($commandesDetail)) {
            $this->commandesDetails->add($commandesDetail);
            $commandesDetail->setFormat($this);
        }

        return $this;
    }

    public function removeCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if ($this->commandesDetails->removeElement($commandesDetail)) {
            if ($commandesDetail->getFormat() === $this) {
                $commandesDetail->setFormat(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, TypesImpression>
     */
    public function getTypesImpressions(): Collection
    {
        return $this->typesImpressions;
    }

    public function addTypeImpression(
        TypesImpression $typeImpression
    ): static {
        if (!$this->typesImpressions->contains($typeImpression)) {
            $this->typesImpressions->add($typeImpression);

            if (!$typeImpression->getFormats()->contains($this)) {
                $typeImpression->addFormat($this);
            }
        }

        return $this;
    }

    public function removeTypeImpression(
        TypesImpression $typeImpression
    ): static {
        if ($this->typesImpressions->removeElement($typeImpression)) {
            if ($typeImpression->getFormats()->contains($this)) {
                $typeImpression->removeFormat($this);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %d × %d %s',
            $this->nom ?? '',
            $this->largeur ?? 0,
            $this->hauteur ?? 0,
            $this->unite
        );
    }
}