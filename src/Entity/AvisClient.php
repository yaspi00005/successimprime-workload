<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AvisClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Avis d'un client, recopié à la main (reçu à l'imprimerie, sur WhatsApp ou
 * sur la fiche Google). Montré sur le site seulement avec l'accord du
 * client ; sans accord, ni le texte ni le nom ne quittent l'ERP local.
 */
#[ORM\Entity(repositoryClass: AvisClientRepository::class)]
#[ORM\Table(name: 'site_avis')]
class AvisClient
{
    use PublicIdTrait;

    public const TEXTE_MAX = 600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « Awa, boutique de mode » : un prénom ou une enseigne, jamais un nom complet sans accord. */
    #[ORM\Column(length: 80)]
    private string $nomAffiche = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $note = 5;

    /** D'où vient l'avis : « À l'imprimerie », « WhatsApp », « Avis Google ». */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateAvis;

    #[ORM\Column]
    private bool $accordClient = false;

    #[ORM\Column]
    private bool $publie = false;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->dateAvis = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNomAffiche(): string
    {
        return $this->nomAffiche;
    }

    public function setNomAffiche(string $nom): static
    {
        $this->nomAffiche = mb_substr(trim($nom), 0, 80);

        return $this;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function setTexte(string $texte): static
    {
        $this->texte = mb_substr(trim($texte), 0, self::TEXTE_MAX);

        return $this;
    }

    public function getNote(): int
    {
        return $this->note;
    }

    /** Note de 1 à 5 : une valeur hors bornes est ramenée dans les bornes. */
    public function setNote(int $note): static
    {
        $this->note = max(1, min(5, $note));

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = PageSite::texteOuNull($source, 60);

        return $this;
    }

    public function getDateAvis(): \DateTimeImmutable
    {
        return $this->dateAvis;
    }

    public function setDateAvis(\DateTimeImmutable $date): static
    {
        $this->dateAvis = $date;

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

    public function isPublie(): bool
    {
        return $this->publie;
    }

    public function setPublie(bool $publie): static
    {
        $this->publie = $publie;

        return $this;
    }

    /** Montré sur le site : publié, avec l'accord du client, et un texte. */
    public function estVisible(): bool
    {
        return $this->publie && $this->accordClient && '' !== trim($this->texte);
    }

    /** @return list<string> */
    public function reglesDePublication(): array
    {
        $regles = [];
        if (!$this->accordClient) {
            $regles[] = 'Cochez « accord du client » avant de publier un avis.';
        }
        if ('' === trim($this->texte) || '' === trim($this->nomAffiche)) {
            $regles[] = 'Un avis publié a besoin d\'un texte et d\'un nom affiché.';
        }

        return $regles;
    }

    /** Filtre de la synchronisation : sans accord, le texte et le nom restent au local. */
    public static function textePublic(?string $texte, self $avis): string
    {
        return $avis->isAccordClient() ? (string) $texte : '';
    }
}
