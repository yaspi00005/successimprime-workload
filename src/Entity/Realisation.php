<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\RealisationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Travail livré montré sur la page Réalisations (maquette 04).
 *
 * Le nom du client n'est montré, et ne quitte l'ERP local, que si la case
 * « accord du client » est cochée (CatalogueSynchro, filtre nomClient).
 */
#[ORM\Entity(repositoryClass: RealisationRepository::class)]
#[ORM\Table(name: 'site_realisation')]
class Realisation
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $titre = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CategorieProduit $categorie = null;

    /**
     * Identifiant public de la catégorie, recopié pour le site : la
     * réalisation n'attend pas sa catégorie à la synchronisation (les
     * catégories se chargent après les données du site).
     */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $categorieRef = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var Collection<int, ImageSite> */
    #[ORM\ManyToMany(targetEntity: ImageSite::class)]
    #[ORM\JoinTable(name: 'site_realisation_image')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $nomClient = null;

    #[ORM\Column]
    private bool $accordClient = false;

    #[ORM\Column]
    private bool $publie = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateRealisation;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->images = new ArrayCollection();
        $this->dateRealisation = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = mb_substr(trim($titre), 0, 160);

        return $this;
    }

    public function getCategorie(): ?CategorieProduit
    {
        return $this->categorie;
    }

    public function setCategorie(?CategorieProduit $categorie): static
    {
        $this->categorie = $categorie;
        $this->categorieRef = $categorie?->getPublicId()->toRfc4122();

        return $this;
    }

    public function getCategorieRef(): ?string
    {
        return $this->categorieRef;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim((string) $description);
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    /** @return Collection<int, ImageSite> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function getImagePrincipale(): ?ImageSite
    {
        return $this->images->first() ?: null;
    }

    public function ajouterImage(ImageSite $image): static
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
        }

        return $this;
    }

    public function retirerImage(ImageSite $image): static
    {
        $this->images->removeElement($image);

        return $this;
    }

    public function getNomClient(): ?string
    {
        return $this->nomClient;
    }

    public function setNomClient(?string $nom): static
    {
        $this->nomClient = PageSite::texteOuNull($nom, 160);

        return $this;
    }

    public function isAccordClient(): bool
    {
        return $this->accordClient;
    }

    public function setAccordClient(bool $accord): static
    {
        $this->accordClient = $accord;

        return $this;
    }

    /** Nom du client à montrer : seulement avec son accord. */
    public function getClientAffiche(): ?string
    {
        return $this->accordClient ? $this->nomClient : null;
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

    public function getDateRealisation(): \DateTimeImmutable
    {
        return $this->dateRealisation;
    }

    public function setDateRealisation(\DateTimeImmutable $date): static
    {
        $this->dateRealisation = $date;

        return $this;
    }

    /** @return list<string> */
    public function reglesDePublication(): array
    {
        return '' === trim($this->titre) ? ['Une réalisation publiée a besoin d\'un titre.'] : [];
    }

    /** Filtre de la synchronisation : le nom ne part qu'avec l'accord du client. */
    public static function nomClientPublic(?string $nom, self $realisation): ?string
    {
        return $realisation->getClientAffiche();
    }
}
