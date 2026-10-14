<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\PageSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Page de contenu du site vitrine (équipements, guide des fichiers, à
 * propos, engagements, questions fréquentes). Le corps est écrit en
 * Markdown simplifié (MarkdownSite), jamais en HTML.
 */
#[ORM\Entity(repositoryClass: PageSiteRepository::class)]
#[ORM\Table(name: 'site_page')]
class PageSite
{
    use PublicIdTrait;

    /** Pages du site : adresse (slug) => route et nom dans l'admin. */
    public const PAGES = [
        'equipements' => ['route' => 'app_site_equipements', 'libelle' => 'Nos équipements', 'maquette' => '05'],
        'guide-fichiers' => ['route' => 'app_site_guide', 'libelle' => 'Préparer son fichier', 'maquette' => '06'],
        'a-propos' => ['route' => 'app_site_a_propos', 'libelle' => 'Qui sommes-nous ?', 'maquette' => '07'],
        'nos-engagements' => ['route' => 'app_site_engagements', 'libelle' => 'Nos engagements', 'maquette' => '08'],
        'faq' => ['route' => 'app_site_faq', 'libelle' => 'Questions fréquentes', 'maquette' => '09'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $slug = '';

    #[ORM\Column(length: 160)]
    private string $titre = '';

    /** Phrase du bandeau, sous le titre. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accroche = null;

    /** Description pour les moteurs de recherche (balise meta). */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $corps = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImageSite $image = null;

    #[ORM\Column]
    private bool $publie = false;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(string $slug = '')
    {
        $this->initialiserPublicId();
        $this->slug = $slug;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
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

    public function getAccroche(): ?string
    {
        return $this->accroche;
    }

    public function setAccroche(?string $accroche): static
    {
        $this->accroche = self::texteOuNull($accroche, 255);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::texteOuNull($description, 300);

        return $this;
    }

    public function getCorps(): string
    {
        return $this->corps;
    }

    public function setCorps(string $corps): static
    {
        $this->corps = $corps;

        return $this;
    }

    public function getImage(): ?ImageSite
    {
        return $this->image;
    }

    public function setImage(?ImageSite $image): static
    {
        $this->image = $image;

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

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function noterModification(\DateTimeImmutable $le): static
    {
        $this->modifieLe = $le;

        return $this;
    }

    /** Raisons qui empêchent de publier (vide : publiable). */
    public function reglesDePublication(): array
    {
        return '' === trim($this->titre) || '' === trim($this->corps) ? ['Une page publiée a besoin d\'un titre et d\'un texte.'] : [];
    }

    public static function texteOuNull(?string $texte, int $max): ?string
    {
        $texte = trim((string) $texte);

        return '' === $texte ? null : mb_substr($texte, 0, $max);
    }
}
