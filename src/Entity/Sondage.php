<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Service\Sondages\Questions;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sondage construit par l'admin (docs/sondages.md) : global, proposé dans
 * l'espace client à tous les clients ou à un segment ; ou libre, publié
 * par un lien ou un QR code, anonyme possible. Le sondage de commande,
 * lui, a un modèle fixe (SondageCommande) et n'a pas de fiche ici.
 *
 * Créé au local, il descend sur le site où les clients répondent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sondage')]
#[ORM\Index(name: 'idx_sondage_statut', columns: ['statut'])]
class Sondage
{
    use PublicIdTrait;

    public const GLOBAL = 'global';
    public const LIBRE = 'libre';

    public const TYPES = [
        self::GLOBAL => 'Global (clients)',
        self::LIBRE => 'Libre (lien ou QR code)',
    ];

    public const BROUILLON = 'brouillon';
    public const OUVERT = 'ouvert';
    public const CLOS = 'clos';

    public const STATUTS = [
        self::BROUILLON => 'Brouillon',
        self::OUVERT => 'En cours',
        self::CLOS => 'Clos',
    ];

    /** Segments proposés pour un sondage global (SegmentsClients). */
    public const SEGMENTS = [
        'tous' => 'Tous les clients',
        'actifs' => 'Clients actifs (achat sur 12 mois)',
        'inactifs' => 'Inactifs depuis 90 jours',
        'entreprises' => 'Entreprises et institutions',
        'particuliers' => 'Particuliers',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $type = self::GLOBAL;

    #[ORM\Column(length: 120)]
    private string $titre = '';

    /** Phrase d'accueil lue avant les questions. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $introduction = null;

    #[ORM\Column(length: 12)]
    private string $statut = self::BROUILLON;

    /** Sondage global : à qui il est proposé. */
    #[ORM\Column(length: 20)]
    private string $segment = 'tous';

    /** Sondage libre : on peut répondre sans donner son nom. */
    #[ORM\Column]
    private bool $anonymeAutorise = true;

    /** Jeton du lien public d'un sondage libre (/avis/s/…). */
    #[ORM\Column(length: 24, unique: true)]
    private string $jeton;

    /** @var list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}> */
    #[ORM\Column(type: Types::JSON)]
    private array $questions = [];

    /** Clients du segment à l'ouverture : base du taux de réponse (au local). */
    #[ORM\Column(nullable: true)]
    private ?int $destinataires = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ouvertLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Prénom de l'employé qui l'a créé : reste au local. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $creePar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->jeton = self::nouveauJeton();
    }

    public static function nouveauJeton(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = \array_key_exists($type, self::TYPES) ? $type : self::GLOBAL;

        return $this;
    }

    public function getLibelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function estLibre(): bool
    {
        return self::LIBRE === $this->type;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = mb_substr(trim($titre), 0, 120);

        return $this;
    }

    public function getIntroduction(): ?string
    {
        return $this->introduction;
    }

    public function setIntroduction(?string $introduction): static
    {
        $introduction = trim((string) $introduction);
        $this->introduction = '' === $introduction ? null : mb_substr($introduction, 0, 500);

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getLibelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function estOuvert(): bool
    {
        return self::OUVERT === $this->statut;
    }

    public function estBrouillon(): bool
    {
        return self::BROUILLON === $this->statut;
    }

    /** @throws \DomainException */
    public function ouvrir(\DateTimeImmutable $le, ?int $destinataires): void
    {
        if (self::OUVERT === $this->statut) {
            throw new \DomainException('Ce sondage est déjà en cours.');
        }
        if ([] === $this->questions) {
            throw new \DomainException('Ajoutez au moins une question avant de lancer le sondage.');
        }
        $this->statut = self::OUVERT;
        $this->ouvertLe ??= $le;
        $this->closLe = null;
        $this->destinataires = $destinataires;
    }

    public function clore(\DateTimeImmutable $le): void
    {
        $this->statut = self::CLOS;
        $this->closLe = $le;
    }

    public function getSegment(): string
    {
        return $this->segment;
    }

    public function setSegment(string $segment): static
    {
        $this->segment = \array_key_exists($segment, self::SEGMENTS) ? $segment : 'tous';

        return $this;
    }

    public function getLibelleSegment(): string
    {
        return self::SEGMENTS[$this->segment] ?? self::SEGMENTS['tous'];
    }

    public function isAnonymeAutorise(): bool
    {
        return $this->anonymeAutorise;
    }

    public function setAnonymeAutorise(bool $anonymeAutorise): static
    {
        $this->anonymeAutorise = $anonymeAutorise;

        return $this;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    /** @return list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}> */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * Questions déjà mises en forme (Questions::normaliser). Elles ne
     * changent plus une fois les premières réponses reçues.
     *
     * @param list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}> $questions
     */
    public function setQuestions(array $questions): static
    {
        $this->questions = $questions;

        return $this;
    }

    /** Clé de la question qui sert de note globale (la première en étoiles). */
    public function cleNoteGlobale(): ?string
    {
        return Questions::premiere($this->questions, Questions::ETOILES);
    }

    public function getDestinataires(): ?int
    {
        return $this->destinataires;
    }

    public function getOuvertLe(): ?\DateTimeImmutable
    {
        return $this->ouvertLe;
    }

    public function getClosLe(): ?\DateTimeImmutable
    {
        return $this->closLe;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreePar(): ?string
    {
        return $this->creePar;
    }

    public function setCreePar(?string $creePar): static
    {
        $this->creePar = null === $creePar ? null : mb_substr($creePar, 0, 80);

        return $this;
    }
}
