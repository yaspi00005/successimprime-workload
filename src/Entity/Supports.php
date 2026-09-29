<?php

namespace App\Entity;

use App\Repository\SupportsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupportsRepository::class)]
class Supports
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $publie = true;

    #[ORM\Column]
    private ?int $ordre = 0;

   
/**
 * @var Collection<int, TypesImpression>
 */
#[ORM\ManyToMany(
    targetEntity: TypesImpression::class,
    inversedBy: 'supports'
)]
#[ORM\JoinTable(
    name: 'support_types_impression',
    joinColumns: [
        new ORM\JoinColumn(
            name: 'support_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ],
    inverseJoinColumns: [
        new ORM\JoinColumn(
            name: 'types_impression_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ]
)]
private Collection $typesImpressions;



   /**
 * @var Collection<int, Format>
 */
#[ORM\ManyToMany(
    targetEntity: Format::class,
    inversedBy: 'supports'
)]
#[ORM\JoinTable(
    name: 'support_format',
    joinColumns: [
        new ORM\JoinColumn(
            name: 'support_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ],
    inverseJoinColumns: [
        new ORM\JoinColumn(
            name: 'format_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ]
)]
private Collection $formats;




/**
 * @var Collection<int, Finition>
 */
#[ORM\ManyToMany(
    targetEntity: Finition::class,
    inversedBy: 'supports'
)]
#[ORM\JoinTable(
    name: 'support_finition',
    joinColumns: [
        new ORM\JoinColumn(
            name: 'support_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ],
    inverseJoinColumns: [
        new ORM\JoinColumn(
            name: 'finition_id',
            referencedColumnName: 'id',
            nullable: false,
            onDelete: 'CASCADE'
        ),
    ]
)]
private Collection $finitions;


    /**
     * @var Collection<int, CommandesDetails>
     */
    #[ORM\OneToMany(
        targetEntity: CommandesDetails::class,
        mappedBy: 'support'
    )]
    private Collection $commandesDetails;

    public function __construct()
    {
        $this->typesImpressions = new ArrayCollection();
        $this->formats = new ArrayCollection();
        $this->finitions = new ArrayCollection();
        $this->commandesDetails = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = trim($description);

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
            $typeImpression->addSupport($this);
        }

        return $this;
    }

    public function removeTypeImpression(
        TypesImpression $typeImpression
    ): static {
        if ($this->typesImpressions->removeElement($typeImpression)) {
            $typeImpression->removeSupport($this);
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
            $format->addSupport($this);
        }

        return $this;
    }

    public function removeFormat(Format $format): static
    {
        if ($this->formats->removeElement($format)) {
            $format->removeSupport($this);
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
            $finition->addSupport($this);
        }

        return $this;
    }

    public function removeFinition(Finition $finition): static
    {
        if ($this->finitions->removeElement($finition)) {
            $finition->removeSupport($this);
        }

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
            $commandesDetail->setSupport($this);
        }

        return $this;
    }

    public function removeCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if ($this->commandesDetails->removeElement($commandesDetail)) {
            if ($commandesDetail->getSupport() === $this) {
                $commandesDetail->setSupport(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->nom ?? '';
    }
}