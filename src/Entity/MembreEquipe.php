<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\MembreEquipeRepository;
use Doctrine\DBAL\Types\Types;
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
 * (textePublic) et aucune photo n'est gardée (ContenuSite).
 *
 * Le gérant (demande de Yaya du 17/10/2026 : « le mot du gérant avec sa
 * photo, ensuite l'équipe ») est l'une de ces personnes, cochée
 * « Gérant » : son mot paraît en haut de la page, avant « Notre équipe »,
 * avec sa photo, son nom et son poste, seulement quand la photo et le
 * texte sont là (estMotVisible). Il n'est pas montré une seconde fois
 * dans « Notre équipe », sauf si « aussi dans l'équipe » est coché.
 */
#[ORM\Entity(repositoryClass: MembreEquipeRepository::class)]
#[ORM\Table(name: 'site_equipe')]
class MembreEquipe
{
    use PublicIdTrait;

    public const NOM_MAX = 60;
    public const POSTE_MAX = 80;
    /** « Le mot du gérant » : court, quelques phrases. */
    public const MOT_MAX = 900;

    /**
     * Un montant suivi de sa monnaie : « 5 000 F », « 25 000 FCFA »,
     * « 10.000 francs », « 5000 fr », « 15 € ». Le nombre finit par un
     * chiffre : un pourcentage, une année (« depuis 2015 ») ou une initiale
     * après une virgule (« en 2010, F. Traoré ») n'en sont pas.
     */
    private const MONTANT = '/\d(?:[\d \x{00A0}\x{202F}.,]*\d)?[ \x{00A0}\x{202F}]?(?:f\s?cfa|xof|francs?|frs?|euros?|€|f)(?![\p{L}\p{N}])/iu';

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

    /** Le gérant (ou la gérante) : un seul à la fois (ContenuSite). */
    #[ORM\Column(options: ['default' => false])]
    private bool $gerant = false;

    /** « Le mot du gérant », sans mise en forme : un paragraphe par ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motGerant = null;

    /** Le gérant paraît aussi dans « Notre équipe » ; sinon, une seule fois, avec son mot. */
    #[ORM\Column(options: ['default' => false])]
    private bool $aussiDansEquipe = false;

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

    public function isGerant(): bool
    {
        return $this->gerant;
    }

    public function setGerant(bool $gerant): static
    {
        $this->gerant = $gerant;

        return $this;
    }

    public function getMotGerant(): ?string
    {
        return $this->motGerant;
    }

    /**
     * Fins de ligne unifiées, espaces superflus et lignes vides en trop
     * retirés ; le texte trop long est refusé par ContenuSite, pas coupé.
     */
    public function setMotGerant(?string $mot): static
    {
        $lignes = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", str_replace(["\r\n", "\r"], "\n", (string) $mot)));
        $mot = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lignes)));
        $this->motGerant = '' === $mot ? null : $mot;

        return $this;
    }

    /**
     * Paragraphes du mot, pour la page : une ligne (ou un bloc séparé par
     * une ligne vide) donne un paragraphe.
     *
     * @return list<string>
     */
    public function getParagraphesMot(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->motGerant)), static fn (string $p): bool => '' !== $p));
    }

    /** « Le mot de la gérante » quand le poste le dit, sinon « Le mot du gérant ». */
    public function getTitreMot(): string
    {
        return preg_match('/\bg[ée]rante\b/iu', $this->poste) ? 'Le mot de la gérante' : 'Le mot du gérant';
    }

    public function isAussiDansEquipe(): bool
    {
        return $this->aussiDansEquipe;
    }

    public function setAussiDansEquipe(bool $aussi): static
    {
        $this->aussiDansEquipe = $aussi;

        return $this;
    }

    /** Montrée sur le site : visible, avec son accord, et un nom. */
    public function estVisible(): bool
    {
        return $this->visible && $this->accordPersonne && '' !== $this->nom;
    }

    /**
     * « Le mot du gérant » paraît : le gérant est montré (visible, accord,
     * nom) et sa photo et son texte sont remplis.
     */
    public function estMotVisible(): bool
    {
        return $this->gerant && $this->estVisible() && null !== $this->photo && null !== $this->motGerant;
    }

    /**
     * Ce qui manque encore au mot du gérant pour paraître (écran de
     * l'atelier), dans l'ordre où le remplir.
     *
     * @return list<string>
     */
    public function manquesDuMot(): array
    {
        if (!$this->gerant) {
            return [];
        }
        $manques = [];
        if (null === $this->motGerant) {
            $manques[] = 'le texte';
        }
        if (null === $this->photo) {
            $manques[] = 'la photo';
        }
        if (!$this->accordPersonne) {
            $manques[] = 'son accord';
        }
        if (!$this->visible) {
            $manques[] = 'la case « Visible »';
        }

        return $manques;
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
     * Le premier montant écrit dans le nom, le poste ou le mot (« 5 000 F »),
     * ou null : ces textes paraissent sur le site public, jamais avec un
     * prix ni un montant (ContenuSite le refuse).
     */
    public function montantEcrit(): ?string
    {
        foreach ([$this->nom, $this->poste, (string) $this->motGerant] as $texte) {
            if (preg_match(self::MONTANT, $texte, $trouve)) {
                return trim($trouve[0]);
            }
        }

        return null;
    }

    /**
     * Filtre de la synchronisation (CatalogueSynchro, « site_equipe ») :
     * sans l'accord de la personne, son nom et son poste partent vides.
     */
    public static function textePublic(?string $texte, self $membre): string
    {
        return $membre->isAccordPersonne() ? (string) $texte : '';
    }

    /**
     * Filtre du mot : il ne part qu'avec l'accord de la personne, et
     * seulement si elle est le gérant.
     */
    public static function motPublic(?string $mot, self $membre): ?string
    {
        return $membre->isAccordPersonne() && $membre->isGerant() ? $mot : null;
    }
}
