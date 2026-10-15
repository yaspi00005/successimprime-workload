<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AnnonceRepository;
use App\Service\Site\MarkdownSite;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bande d'annonce (demande de Yaya du 15/10/2026) : une phrase courte en
 * haut du site public, de l'espace client, ou des deux (fermeture
 * exceptionnelle, promotion du mois, retard de livraison…). Saisie dans
 * Réglages › Site vitrine › Annonces, à l'atelier, puis descendue au site
 * par la synchronisation (CatalogueSynchro, « site_annonce »).
 *
 * Montrée seulement si elle est active et dans sa période (dates
 * comprises). Plusieurs annonces visibles défilent l'une après l'autre ;
 * le visiteur peut les fermer pour sa visite.
 */
#[ORM\Entity(repositoryClass: AnnonceRepository::class)]
#[ORM\Table(name: 'site_annonce')]
class Annonce
{
    use PublicIdTrait;

    public const TEXTE_MAX = 200;
    public const LIBELLE_LIEN_MAX = 40;

    public const INFORMATION = 'information';
    public const PROMOTION = 'promotion';
    public const ALERTE = 'alerte';
    public const STYLES = [self::INFORMATION => 'Information', self::PROMOTION => 'Promotion', self::ALERTE => 'Alerte'];

    public const SITE = 'site';
    public const ESPACE = 'espace';
    public const LES_DEUX = 'tous';
    public const CIBLES = [self::SITE => 'Site public', self::ESPACE => 'Espace client', self::LES_DEUX => 'Site public et espace client'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::TEXTE_MAX)]
    private string $texte = '';

    /** Adresse web (http, https) ou page du site (« /nos-produits ») ; null : pas de lien. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lien = null;

    /** Texte du lien (« En savoir plus » si vide). */
    #[ORM\Column(length: self::LIBELLE_LIEN_MAX, nullable: true)]
    private ?string $libelleLien = null;

    #[ORM\Column(length: 12)]
    private string $style = self::INFORMATION;

    #[ORM\Column(length: 8)]
    private string $cible = self::LES_DEUX;

    /** Premier jour où elle se montre ; null : tout de suite. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $debut = null;

    /** Dernier jour où elle se montre (compris) ; null : sans fin. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    /** « Active » dans l'écran : sans elle, l'annonce reste à l'atelier. */
    #[ORM\Column]
    private bool $publie = false;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    /** Une seule ligne : les retours à la ligne et les espaces en trop sont retirés. */
    public function setTexte(string $texte): static
    {
        $this->texte = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $texte)), 0, self::TEXTE_MAX);

        return $this;
    }

    /** Titre de la fiche dans l'admin : le début du texte. */
    public function getTitre(): string
    {
        return mb_strlen($this->texte) > 60 ? rtrim(mb_substr($this->texte, 0, 59)).'…' : $this->texte;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    /** Seulement une adresse web ou une page du site (MarkdownSite::lienSur) : sinon, pas de lien. */
    public function setLien(?string $lien): static
    {
        $lien = trim((string) $lien);
        $this->lien = '' !== $lien && \strlen($lien) <= 255 ? MarkdownSite::lienSur($lien) : null;

        return $this;
    }

    public function getLibelleLien(): ?string
    {
        return $this->libelleLien;
    }

    public function setLibelleLien(?string $libelle): static
    {
        $libelle = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $libelle)), 0, self::LIBELLE_LIEN_MAX);
        $this->libelleLien = '' === $libelle ? null : $libelle;

        return $this;
    }

    /** Texte du lien montré : celui saisi, sinon « En savoir plus ». */
    public function getLibelleLienAffiche(): string
    {
        return $this->libelleLien ?? 'En savoir plus';
    }

    /** Le lien sort-il du site (ouvert dans un nouvel onglet) ? */
    public function lienExterne(): bool
    {
        return null !== $this->lien && 1 === preg_match('#^https?://#i', $this->lien);
    }

    public function getStyle(): string
    {
        return $this->style;
    }

    public function setStyle(string $style): static
    {
        $this->style = isset(self::STYLES[$style]) ? $style : self::INFORMATION;

        return $this;
    }

    public function getStyleLibelle(): string
    {
        return self::STYLES[$this->style];
    }

    public function getCible(): string
    {
        return $this->cible;
    }

    public function setCible(string $cible): static
    {
        $this->cible = isset(self::CIBLES[$cible]) ? $cible : self::LES_DEUX;

        return $this;
    }

    public function getCibleLibelle(): string
    {
        return self::CIBLES[$this->cible];
    }

    /** Montrée à cet endroit (« site » ou « espace ») ? */
    public function pourCible(string $cible): bool
    {
        return self::LES_DEUX === $this->cible || $cible === $this->cible;
    }

    public function getDebut(): ?\DateTimeImmutable
    {
        return $this->debut;
    }

    public function setDebut(?\DateTimeImmutable $debut): static
    {
        $this->debut = $debut?->setTime(0, 0);

        return $this;
    }

    public function getFin(): ?\DateTimeImmutable
    {
        return $this->fin;
    }

    public function setFin(?\DateTimeImmutable $fin): static
    {
        $this->fin = $fin?->setTime(0, 0);

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

    /** Montrée ce jour-là : active, avec un texte, dans sa période (premier et dernier jour compris). */
    public function estVisible(\DateTimeInterface $maintenant): bool
    {
        $jour = $maintenant->format('Y-m-d');

        return $this->publie
            && '' !== $this->texte
            && (null === $this->debut || $this->debut->format('Y-m-d') <= $jour)
            && (null === $this->fin || $this->fin->format('Y-m-d') >= $jour);
    }

    /**
     * État pour la liste de l'admin : « active », « programmee » (pas
     * encore commencée), « terminee » (période passée) ou « brouillon ».
     */
    public function etat(\DateTimeInterface $maintenant): string
    {
        $jour = $maintenant->format('Y-m-d');

        return match (true) {
            !$this->publie => 'brouillon',
            null !== $this->fin && $this->fin->format('Y-m-d') < $jour => 'terminee',
            null !== $this->debut && $this->debut->format('Y-m-d') > $jour => 'programmee',
            default => 'active',
        };
    }

    /** @return list<string> ce qui manque pour l'activer */
    public function reglesDePublication(): array
    {
        $regles = [];
        if ('' === $this->texte) {
            $regles[] = 'Une annonce a besoin d\'un texte.';
        }
        if (null !== $this->debut && null !== $this->fin && $this->fin < $this->debut) {
            $regles[] = 'Le dernier jour est avant le premier : corrigez la période.';
        }

        return $regles;
    }
}
