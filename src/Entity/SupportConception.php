<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Support qu'un client peut faire concevoir depuis son espace (maquette
 * DC01) : kakémono, bâche, flyer… avec le forfait du graphiste, l'acompte,
 * le délai du premier BAT et les produits du catalogue qui l'impriment.
 *
 * Réglé au local (Paramètres › Conception), envoyé au site.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_conception')]
#[ORM\UniqueConstraint(name: 'uniq_support_conception_code', columns: ['code'])]
class SupportConception
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Repère stable (kakemono, bache…) : jamais montré. */
    #[ORM\Column(length: 30)]
    private string $code = '';

    /** « Kakémono / roll-up » sur la carte. */
    #[ORM\Column(length: 80)]
    private string $nom = '';

    /** « Kakémono » sur la puce du téléphone et le bouton « Continuer avec… ». */
    #[ORM\Column(length: 30)]
    private string $court = '';

    /** « 85 × 200 cm », « A5 · A4 », « sur mesure ». */
    #[ORM\Column(length: 60)]
    private string $format = '';

    /** Taille de référence : orientation de l'image IA et cadre « Voir sur le support ». */
    #[ORM\Column(nullable: true)]
    private ?float $largeurCm = null;

    #[ORM\Column(nullable: true)]
    private ?float $hauteurCm = null;

    /** Forfait du graphiste, en F. */
    #[ORM\Column]
    private int $forfait = 0;

    #[ORM\Column]
    private int $acomptePourcent = 50;

    /** Premier BAT sous… heures ouvrées. */
    #[ORM\Column]
    private int $delaiBatHeures = 24;

    #[ORM\Column]
    private int $correctionsIncluses = 2;

    /** Pastille de la carte (#RRGGBB). */
    #[ORM\Column(length: 7)]
    private string $couleur = '#0F4590';

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private int $ordre = 0;

    /**
     * Produits du catalogue qui impriment ce support ensuite (le premier
     * publié sert à la commande d'impression d'un visuel IA).
     *
     * @var Collection<int, ProduitConfiguration>
     */
    #[ORM\ManyToMany(targetEntity: ProduitConfiguration::class)]
    #[ORM\JoinTable(name: 'support_conception_configuration')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $configurations;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->configurations = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(string $code): static { $this->code = mb_substr(trim($code), 0, 30); return $this; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = mb_substr(trim($nom), 0, 80); return $this; }
    public function getCourt(): string { return '' !== $this->court ? $this->court : $this->nom; }
    public function setCourt(string $court): static { $this->court = mb_substr(trim($court), 0, 30); return $this; }
    public function getFormat(): string { return $this->format; }
    public function setFormat(string $format): static { $this->format = mb_substr(trim($format), 0, 60); return $this; }
    public function getLargeurCm(): ?float { return $this->largeurCm; }
    public function setLargeurCm(?float $cm): static { $this->largeurCm = null !== $cm && $cm > 0 ? $cm : null; return $this; }
    public function getHauteurCm(): ?float { return $this->hauteurCm; }
    public function setHauteurCm(?float $cm): static { $this->hauteurCm = null !== $cm && $cm > 0 ? $cm : null; return $this; }
    public function getForfait(): int { return $this->forfait; }
    public function setForfait(int $forfait): static { $this->forfait = max(0, $forfait); return $this; }
    public function getAcomptePourcent(): int { return $this->acomptePourcent; }
    public function setAcomptePourcent(int $pourcent): static { $this->acomptePourcent = max(0, min(100, $pourcent)); return $this; }
    public function getDelaiBatHeures(): int { return $this->delaiBatHeures; }
    public function setDelaiBatHeures(int $heures): static { $this->delaiBatHeures = max(1, $heures); return $this; }
    public function getCorrectionsIncluses(): int { return $this->correctionsIncluses; }
    public function setCorrectionsIncluses(int $nombre): static { $this->correctionsIncluses = max(0, $nombre); return $this; }
    public function getCouleur(): string { return $this->couleur; }
    public function setCouleur(string $couleur): static { $this->couleur = preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur) ? strtoupper($couleur) : '#0F4590'; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }
    public function getOrdre(): int { return $this->ordre; }
    public function setOrdre(int $ordre): static { $this->ordre = $ordre; return $this; }

    /** @return Collection<int, ProduitConfiguration> */
    public function getConfigurations(): Collection { return $this->configurations; }

    public function addConfiguration(ProduitConfiguration $c): static
    {
        if (!$this->configurations->contains($c)) {
            $this->configurations->add($c);
        }

        return $this;
    }

    public function removeConfiguration(ProduitConfiguration $c): static
    {
        $this->configurations->removeElement($c);

        return $this;
    }

    /** « Kakémono 85 × 200 cm » : titre de la page des informations. */
    public function titre(): string
    {
        return trim($this->getCourt().' '.(preg_match('/\d/', $this->format) ? $this->format : ''));
    }
}
