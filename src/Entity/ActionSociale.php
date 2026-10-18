<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\ActionSocialeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Action sociale de Succès Imprim, montrée sur la page « Nos actions
 * sociales » du site vitrine (demande de Yaya du 16/10/2026).
 *
 * Toute la partie reste invisible (page introuvable, ni lien, ni bloc
 * d'accueil, ni plan du site) tant qu'aucune action publiée n'a un texte
 * et au moins une photo (estVisible). Les photos de personnes
 * reconnaissables, d'enfants surtout, ne se mettent qu'avec leur accord
 * (celui des parents) : l'écran de saisie le rappelle.
 */
#[ORM\Entity(repositoryClass: ActionSocialeRepository::class)]
#[ORM\Table(name: 'site_action_sociale')]
class ActionSociale
{
    use PublicIdTrait;

    /** Photos d'une action, au plus. */
    public const PHOTOS_MAX = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $titre = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateAction;

    /** Lieu, facultatif (« École de Sébénikoro, Bamako »). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieu = null;

    /** Récit de l'action, en Markdown simplifié (MarkdownSite). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $texte = null;

    /** @var Collection<int, ImageSite> */
    #[ORM\ManyToMany(targetEntity: ImageSite::class)]
    #[ORM\JoinTable(name: 'site_action_sociale_image')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['ordre' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    #[ORM\Column]
    private bool $publie = false;

    /** Ordre sur la page : le plus petit d'abord ; à égalité, la plus récente. */
    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->images = new ArrayCollection();
        $this->dateAction = new \DateTimeImmutable('today');
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

    public function getDateAction(): \DateTimeImmutable
    {
        return $this->dateAction;
    }

    public function setDateAction(\DateTimeImmutable $date): static
    {
        $this->dateAction = $date;

        return $this;
    }

    public function getLieu(): ?string
    {
        return $this->lieu;
    }

    public function setLieu(?string $lieu): static
    {
        $this->lieu = PageSite::texteOuNull($lieu, 120);

        return $this;
    }

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $texte = trim(str_replace(["\r\n", "\r"], "\n", (string) $texte));
        $this->texte = '' === $texte ? null : $texte;

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
        $this->ordre = max(0, min(999, $ordre));

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

    /**
     * Montrée sur le site : publiée, avec un texte et au moins une photo.
     * Une seule action montrée suffit à faire paraître la page, le bloc de
     * l'accueil et le lien du pied de page.
     */
    public function estVisible(): bool
    {
        return $this->publie && [] === $this->reglesDePublication();
    }

    /**
     * Ce qui manque pour paraître sur le site, en deux ou trois mots
     * (« le texte », « une photo »).
     *
     * @return list<string>
     */
    public function manques(): array
    {
        return array_keys(array_filter([
            'le titre' => '' === trim($this->titre),
            'le texte' => null === $this->texte,
            'une photo' => $this->images->isEmpty(),
        ]));
    }

    /** @return list<string> ce qui manque pour la publier */
    public function reglesDePublication(): array
    {
        return array_map(static fn (string $manque): string => 'Pour paraître sur le site, il manque '.$manque.'.', $this->manques());
    }
}
