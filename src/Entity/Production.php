<?php

namespace App\Entity;

use App\Repository\ProductionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductionRepository::class)]
class Production
{

public const PRODUCTION_A_PRODUIRE = 'a_produire';
public const PRODUCTION_EN_COURS = 'en_production';
public const PRODUCTION_TERMINEE = 'terminee';
public const PRODUCTION_PRETE_LIVRAISON = 'prete_livraison';
public const PRODUCTION_EN_LIVRAISON = 'en_livraison';
public const PRODUCTION_LIVREE = 'livree';
public const PRODUCTION_ANNULEE = 'annulee';
public const PRODUCTION_NON_REQUISE = 'non_requise';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'productions')]
    private ?CommandesDetails $commandeDetails = null;

    #[ORM\ManyToOne(inversedBy: 'productions')]
    private ?Machines $machine = null;

    #[ORM\Column]
    private ?\DateTime $dateDebut = null;

    #[ORM\Column]
    private ?\DateTime $dateFin = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $temps = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $m2Imprimes = null;

    #[ORM\Column]
    private ?bool $etat = null;
    #[ORM\Column(options: ['default' => false])]
private bool $gestionStock = false;

#[ORM\OneToMany(
    targetEntity: ProduitArticleStock::class,
    mappedBy: 'produit',
    cascade: ['persist', 'remove'],
    orphanRemoval: true
)]
#[ORM\OrderBy([
    'ordre' => 'ASC',
    'id' => 'ASC',
])]
private Collection $articlesStock;

    /**
     * @var Collection<int, ConsommationEncres>
     */
    #[ORM\OneToMany(targetEntity: ConsommationEncres::class, mappedBy: 'production')]
    private Collection $consommationEncres;

    public function __construct()
    {
        $this->consommationEncres = new ArrayCollection();
        $this->articlesStock = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommandeDetails(): ?CommandesDetails
    {
        return $this->commandeDetails;
    }

    public function setCommandeDetails(?CommandesDetails $commandeDetails): static
    {
        $this->commandeDetails = $commandeDetails;

        return $this;
    }

    public function getMachine(): ?Machines
    {
        return $this->machine;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getDateDebut(): ?\DateTime
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTime $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTime
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTime $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getTemps(): ?Number
    {
        return $this->temps;
    }

    public function setTemps(int $temps): static
    {
        $this->temps = $temps;

        return $this;
    }

    public function getM2Imprimes(): ?int
    {
        return $this->m2Imprimes;
    }

    public function setM2Imprimes(int $m2Imprimes): static
    {
        $this->m2Imprimes = $m2Imprimes;

        return $this;
    }

    public function isEtat(): ?bool
    {
        return $this->etat;
    }

    public function setEtat(bool $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    /**
     * @return Collection<int, ConsommationEncres>
     */
    public function getConsommationEncres(): Collection
    {
        return $this->consommationEncres;
    }

    public function addConsommationEncre(ConsommationEncres $consommationEncre): static
    {
        if (!$this->consommationEncres->contains($consommationEncre)) {
            $this->consommationEncres->add($consommationEncre);
            $consommationEncre->setProduction($this);
        }

        return $this;
    }

    public function removeConsommationEncre(ConsommationEncres $consommationEncre): static
    {
        if ($this->consommationEncres->removeElement($consommationEncre)) {
            // set the owning side to null (unless already changed)
            if ($consommationEncre->getProduction() === $this) {
                $consommationEncre->setProduction(null);
            }
        }

        return $this;
    }

    public function isGestionStock(): bool
{
    return $this->gestionStock;
}

public function setGestionStock(bool $gestionStock): static
{
    $this->gestionStock = $gestionStock;

    return $this;
}
/**
 * @return Collection<int, ProduitArticleStock>
 */
public function getArticlesStock(): Collection
{
    return $this->articlesStock;
}

public function addArticleStock(
    ProduitArticleStock $articleStock
): static {
    if (!$this->articlesStock->contains($articleStock)) {
        $this->articlesStock->add($articleStock);

        $articleStock->setProduit(
            $this
        );
    }

    return $this;
}

public function removeArticleStock(
    ProduitArticleStock $articleStock
): static {
    if ($this->articlesStock->removeElement($articleStock)) {
        if (
            $articleStock->getProduit()
            === $this
        ) {
            $articleStock->setProduit(
                null
            );
        }
    }

    return $this;
}
}
