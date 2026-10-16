<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\MembreEquipeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personne de la section « Notre équipe » de la page « Qui sommes-nous ? »
 * (demande de Yaya du 16/10/2026 : « avec les photos et nom et poste »).
 *
 * Saisie dans Réglages › Site vitrine › Équipe, à l'atelier. Ce n'est PAS
 * la fiche de l'employé : aucun lien vers le personnel, ni téléphone, ni
 * e-mail, ni compte. Seulement le nom affiché (un prénom suffit), le poste
 * et une photo, montrés seulement si « Visible » est coché ET si la
 * personne a accepté que sa photo et son nom paraissent sur le site.
 *
 * Sans cet accord, le nom et le poste descendent vides au site
 * (nomPublic) et aucune photo n'est gardée (ContenuSite).
 */
#[ORM\Entity(repositoryClass: MembreEquipeRepository::class)]
#[ORM\Table(name: 'site_equipe')]
class MembreEquipe
{
    use PublicIdTrait;

    public const NOM_MAX = 60;
    public const POSTE_MAX = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom affiché sur le site, comme la personne le souhaite (« Awa », « Seydou K. »). */
    #[ORM\Column(length: self::NOM_MAX)]
    private string $nom = '';

    #[ORM\Column(length: self::POSTE_MAX)]
    private string $poste = '';

    /** Portrait carré, réencodé sans métadonnées (ImagesSite, usage « portrait »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ImageSite $photo = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $visible = false;

    /** « La personne a accepté que sa photo et son nom paraissent sur le site. » */
    #[ORM\Column]
    private bool $accordPersonne = false;

    public function __construct()
    {
        $this->initialiserPublicId();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nom)), 0, self::NOM_MAX);

        return $this;
    }

    public function getPoste(): string
    {
        return $this->poste;
    }

    public function setPoste(string $poste): static
    {
        $this->poste = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $poste)), 0, self::POSTE_MAX);

        return $this;
    }

    public function getPhoto(): ?ImageSite
    {
        return $this->photo;
    }

    public function setPhoto(?ImageSite $photo): static
    {
        $this->photo = $photo;

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

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    public function isAccordPersonne(): bool
    {
        return $this->accordPersonne;
    }

    public function setAccordPersonne(bool $accord): static
    {
        $this->accordPersonne = $accord;

        return $this;
    }

    /** Montrée sur le site : visible, avec son accord, et un nom. */
    public function estVisible(): bool
    {
        return $this->visible && $this->accordPersonne && '' !== $this->nom;
    }

    /**
     * Initiales de l'avatar sans photo : « Awa Traoré » donne « AT »,
     * « Seydou K. » donne « SK », « Moussa » donne « M ».
     */
    public function getInitiales(): string
    {
        $initiales = '';
        foreach (preg_split('/[\s\-]+/u', $this->nom, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
            if (preg_match('/\p{L}/u', $mot, $lettre)) {
                $initiales .= mb_strtoupper($lettre[0]);
            }
            if (2 === mb_strlen($initiales)) {
                break;
            }
        }

        return $initiales;
    }

    /** Texte de la photo pour les malvoyants : « Photo de Awa, Graphiste PAO ». */
    public function getTexteAlternatif(): string
    {
        return 'Photo de '.$this->nom.('' !== $this->poste ? ', '.$this->poste : '');
    }

    /** @return list<string> */
    public function reglesDePublication(): array
    {
        $regles = [];
        if (!$this->accordPersonne) {
            $regles[] = 'Cochez « La personne a accepté que sa photo et son nom paraissent sur le site » : sans son accord, elle n\'apparaît pas.';
        }
        if ('' === $this->nom) {
            $regles[] = 'Il faut un nom à afficher.';
        }

        return $regles;
    }

    /**
     * Filtre de la synchronisation (CatalogueSynchro, « site_equipe ») :
     * sans l'accord de la personne, son nom et son poste partent vides.
     */
    public static function textePublic(?string $texte, self $membre): string
    {
        return $membre->isAccordPersonne() ? (string) $texte : '';
    }
}
