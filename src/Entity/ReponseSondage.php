<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Service\Sondages\Indicateurs;
use App\Service\Sondages\Themes;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réponse d'un client à un sondage (docs/sondages.md) : sondage de
 * commande (note globale, note par article, délai, accueil), global ou
 * libre. Les réponses brutes sont dans « reponses » ; les notes que
 * l'analyse compte sont recopiées dans leurs colonnes.
 *
 * Écrite en ligne, elle monte au local. Le thème de la proposition, son
 * statut et le suivi d'une mauvaise note (rappel, réclamation) restent au
 * local : ils ne sont pas dans la liste des champs synchronisés.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reponse_sondage')]
#[ORM\Index(name: 'idx_reponse_sondage_type_date', columns: ['type', 'cree_le'])]
#[ORM\Index(name: 'idx_reponse_sondage_alerte', columns: ['alerte'])]
#[ORM\HasLifecycleCallbacks]
class ReponseSondage
{
    use PublicIdTrait;

    public const COMMANDE = 'commande';
    public const GLOBAL = Sondage::GLOBAL;
    public const LIBRE = Sondage::LIBRE;

    public const TYPES = [
        self::COMMANDE => 'Commande',
        self::GLOBAL => 'Global',
        self::LIBRE => 'Libre',
    ];

    public const CANAL_ESPACE = 'espace';
    public const CANAL_LIEN = 'lien';
    public const CANAL_QR = 'qr';

    public const CANAUX = [
        self::CANAL_ESPACE => 'Espace client',
        self::CANAL_LIEN => 'Lien',
        self::CANAL_QR => 'QR code',
    ];

    public const ALERTE_A_RAPPELER = 'a_rappeler';
    public const ALERTE_RAPPELE = 'rappele';
    public const ALERTE_RECLAMATION = 'reclamation';
    public const ALERTE_SANS_SUITE = 'sans_suite';

    public const ALERTES = [
        self::ALERTE_A_RAPPELER => 'À rappeler',
        self::ALERTE_RAPPELE => 'Client rappelé',
        self::ALERTE_RECLAMATION => 'Réclamation ouverte',
        self::ALERTE_SANS_SUITE => 'Sans suite',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type = self::COMMANDE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Sondage $sondage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    /** Personne qui a répondu (compte d'entreprise à plusieurs). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    /** Sondage libre : nom et téléphone laissés par la personne, si elle veut. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(length: 10)]
    private string $canal = self::CANAL_ESPACE;

    /** Note globale sur 5. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $noteGlobale = null;

    /** Recommandation de 0 à 10 (NPS). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $nps = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $noteDelai = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $noteAccueil = null;

    /** « Votre proposition pour nous améliorer ». */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $proposition = null;

    /** @var array<string, int|string|list<string>> [clé de la question => réponse] */
    #[ORM\Column(type: Types::JSON)]
    private array $reponses = [];

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** @var Collection<int, NoteArticleSondage> */
    #[ORM\OneToMany(targetEntity: NoteArticleSondage::class, mappedBy: 'reponse', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $notes;

    /* ---------- Au local seulement ---------- */

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $theme = null;

    /** Thème choisi à la main : le classement par mots-clés ne le change plus. */
    #[ORM\Column]
    private bool $themeManuel = false;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $statutProposition = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $alerte = null;

    /** Ce qui s'est dit au rappel. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alerteNote = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $alerteTraiteePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $alerteTraiteeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReclamationClient $reclamation = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->notes = new ArrayCollection();
    }

    /**
     * @param array<string, int|string|list<string>> $reponses
     */
    public static function nouvelle(string $type, array $reponses, string $canal, \DateTimeImmutable $le): self
    {
        $r = new self();
        $r->type = \array_key_exists($type, self::TYPES) ? $type : self::LIBRE;
        $r->reponses = $reponses;
        $r->canal = \array_key_exists($canal, self::CANAUX) ? $canal : self::CANAL_LIEN;
        $r->creeLe = $le;

        return $r;
    }

    /**
     * Avant l'enregistrement, ici ou à l'arrivée de la synchronisation :
     * thème de la proposition par mots-clés, alerte de suivi pour une note
     * de 1 ou 2 quand on peut rappeler la personne.
     */
    #[ORM\PrePersist]
    public function analyser(): void
    {
        if (!$this->themeManuel) {
            $this->theme = Themes::classer($this->proposition);
        }
        if (null !== $this->proposition && null === $this->statutProposition) {
            $this->statutProposition = Themes::A_ETUDIER;
        }
        if (null === $this->alerte && $this->aUneMauvaiseNote() && $this->peutEtreRappele()) {
            $this->alerte = self::ALERTE_A_RAPPELER;
        }
    }

    public function aUneMauvaiseNote(): bool
    {
        return Indicateurs::noteEnAlerte($this->noteLaPlusBasse());
    }

    /** La note la plus basse : globale ou d'un article. */
    public function noteLaPlusBasse(): ?int
    {
        $notes = array_filter([$this->noteGlobale, ...array_map(static fn (NoteArticleSondage $n): int => $n->getNote(), $this->notes->toArray())], static fn (?int $n): bool => null !== $n);

        return [] === $notes ? null : min($notes);
    }

    public function peutEtreRappele(): bool
    {
        return null !== $this->client || null !== $this->telephone;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLibelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getSondage(): ?Sondage
    {
        return $this->sondage;
    }

    public function setSondage(?Sondage $sondage): static
    {
        $this->sondage = $sondage;

        return $this;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): static
    {
        $this->auteur = null === $auteur ? null : mb_substr($auteur, 0, 80);

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setIdentite(?string $nom, ?string $telephone): static
    {
        $nom = trim((string) $nom);
        $telephone = trim((string) $telephone);
        $this->nom = '' === $nom ? null : mb_substr($nom, 0, 80);
        $this->telephone = '' === $telephone ? null : mb_substr($telephone, 0, 20);

        return $this;
    }

    /** Qui a répondu, pour l'atelier : client, nom laissé, ou « Anonyme ». */
    public function getQui(): string
    {
        return $this->client?->getNomComplet() ?? $this->nom ?? 'Anonyme';
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getLibelleCanal(): string
    {
        return self::CANAUX[$this->canal] ?? $this->canal;
    }

    public function getNoteGlobale(): ?int
    {
        return $this->noteGlobale;
    }

    public function getNps(): ?int
    {
        return $this->nps;
    }

    public function getNoteDelai(): ?int
    {
        return $this->noteDelai;
    }

    public function getNoteAccueil(): ?int
    {
        return $this->noteAccueil;
    }

    /** Recopie les notes que l'analyse compte. */
    public function setNotes(?int $globale, ?int $nps, ?int $delai = null, ?int $accueil = null): static
    {
        $this->noteGlobale = $globale;
        $this->nps = $nps;
        $this->noteDelai = $delai;
        $this->noteAccueil = $accueil;

        return $this;
    }

    public function getProposition(): ?string
    {
        return $this->proposition;
    }

    public function setProposition(?string $proposition): static
    {
        $proposition = trim((string) $proposition);
        $this->proposition = '' === $proposition ? null : mb_substr($proposition, 0, 1000);

        return $this;
    }

    /** @return array<string, int|string|list<string>> */
    public function getReponses(): array
    {
        return $this->reponses;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** @return Collection<int, NoteArticleSondage> */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function ajouterNote(NoteArticleSondage $note): static
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
        }

        return $this;
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function getLibelleTheme(): string
    {
        return Themes::libelle($this->theme);
    }

    public function isThemeManuel(): bool
    {
        return $this->themeManuel;
    }

    public function getStatutProposition(): ?string
    {
        return $this->statutProposition;
    }

    public function getLibelleStatutProposition(): string
    {
        return Themes::STATUTS[$this->statutProposition] ?? Themes::STATUTS[Themes::A_ETUDIER];
    }

    /** Reclassement à la main par l'atelier. */
    public function classer(string $theme, string $statut): void
    {
        if (!\array_key_exists($theme, Themes::THEMES) || !\array_key_exists($statut, Themes::STATUTS)) {
            throw new \DomainException('Thème ou statut inconnu.');
        }
        $this->themeManuel = $this->themeManuel || $theme !== $this->theme;
        $this->theme = $theme;
        $this->statutProposition = $statut;
    }

    public function getAlerte(): ?string
    {
        return $this->alerte;
    }

    public function getLibelleAlerte(): ?string
    {
        return null === $this->alerte ? null : (self::ALERTES[$this->alerte] ?? $this->alerte);
    }

    public function alerteOuverte(): bool
    {
        return self::ALERTE_A_RAPPELER === $this->alerte;
    }

    /**
     * Suite donnée à une mauvaise note.
     *
     * @throws \DomainException
     */
    public function traiterAlerte(string $suite, ?string $note, string $par, \DateTimeImmutable $le, ?ReclamationClient $reclamation = null): void
    {
        if (!\in_array($suite, [self::ALERTE_RAPPELE, self::ALERTE_RECLAMATION, self::ALERTE_SANS_SUITE], true)) {
            throw new \DomainException('Choisissez la suite donnée.');
        }
        if (null === $this->alerte) {
            throw new \DomainException('Cette réponse n\'a pas d\'alerte.');
        }
        $note = trim((string) $note);
        if (self::ALERTE_RAPPELE === $suite && '' === $note) {
            throw new \DomainException('Notez en quelques mots ce que le client a dit.');
        }
        $this->alerte = $suite;
        $this->alerteNote = '' === $note ? $this->alerteNote : mb_substr($note, 0, 1000);
        $this->alerteTraiteePar = mb_substr($par, 0, 80);
        $this->alerteTraiteeLe = $le;
        $this->reclamation = $reclamation ?? $this->reclamation;
    }

    public function getAlerteNote(): ?string
    {
        return $this->alerteNote;
    }

    public function getAlerteTraiteePar(): ?string
    {
        return $this->alerteTraiteePar;
    }

    public function getAlerteTraiteeLe(): ?\DateTimeImmutable
    {
        return $this->alerteTraiteeLe;
    }

    public function getReclamation(): ?ReclamationClient
    {
        return $this->reclamation;
    }
}
