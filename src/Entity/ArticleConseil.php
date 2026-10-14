<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\ArticleConseilRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article de la page « Conseils » (maquette 12). Visible à partir de sa
 * date de publication, s'il est publié.
 */
#[ORM\Entity(repositoryClass: ArticleConseilRepository::class)]
#[ORM\Table(name: 'site_article')]
class ArticleConseil
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $titre = '';

    #[ORM\Column(length: 100, unique: true)]
    private string $slug = '';

    /** Petite étiquette : « Grand format », « Fichiers et BAT »… */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $rubrique = null;

    #[ORM\Column(length: 300)]
    private string $resume = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $corps = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImageSite $image = null;

    #[ORM\Column]
    private \DateTimeImmutable $publieLe;

    #[ORM\Column]
    private bool $publie = false;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->publieLe = new \DateTimeImmutable('today');
        $this->modifieLe = new \DateTimeImmutable();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = mb_substr($slug, 0, 100);

        return $this;
    }

    public function getRubrique(): ?string
    {
        return $this->rubrique;
    }

    public function setRubrique(?string $rubrique): static
    {
        $this->rubrique = PageSite::texteOuNull($rubrique, 60);

        return $this;
    }

    public function getResume(): string
    {
        return $this->resume;
    }

    public function setResume(string $resume): static
    {
        $this->resume = mb_substr(trim($resume), 0, 300);

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

    public function getPublieLe(): \DateTimeImmutable
    {
        return $this->publieLe;
    }

    public function setPublieLe(\DateTimeImmutable $le): static
    {
        $this->publieLe = $le;

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

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function noterModification(\DateTimeImmutable $le): static
    {
        $this->modifieLe = $le;

        return $this;
    }

    /** Montré sur le site à cet instant. */
    public function estVisible(\DateTimeImmutable $maintenant): bool
    {
        return $this->publie && $this->publieLe <= $maintenant && '' !== $this->slug;
    }

    /** @return list<string> */
    public function reglesDePublication(): array
    {
        return '' === trim($this->titre) || '' === trim($this->corps) || '' === $this->slug
            ? ['Un conseil publié a besoin d\'un titre, d\'une adresse et d\'un texte.']
            : [];
    }
}
