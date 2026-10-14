<?php

namespace App\Entity;

use App\Repository\EvaluationPrimeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Évaluation mensuelle d'un agent (maquette N02) : critères mesurés dans
 * l'ERP, appréciation du responsable, score sur 100 et prime.
 *
 * En brouillon, les critères se recalculent à chaque affichage. « Valider
 * l'évaluation » fige la photo des critères ; un recalcul crée alors une
 * nouvelle version, avec son motif, qui remplace la précédente quand elle
 * est validée à son tour. La direction valide ensuite la prime (« Valider
 * les primes ») : elle passe dans la paie comme somme due, jamais versée
 * automatiquement. Voir App\Service\Primes\Primes. Table locale.
 */
#[ORM\Entity(repositoryClass: EvaluationPrimeRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_evaluation_agent_mois_version', columns: ['agent_id', 'mois', 'version'])]
#[ORM\Index(name: 'idx_evaluation_mois', columns: ['mois'])]
class EvaluationPrime
{
    public const BROUILLON = 'brouillon';
    public const VALIDEE = 'validee';
    public const REMPLACEE = 'remplacee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $agent;

    /** Premier jour du mois évalué. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $mois;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $version = 1;

    #[ORM\Column(length: 10)]
    private string $statut = self::BROUILLON;

    /** Rôle de prime au moment de l'évaluation. */
    #[ORM\Column(length: 20)]
    private string $role;

    /** Score sur 100 (null tant qu'aucun critère n'a de données). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    /** Part de la prime cible, en %. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $taux = 0;

    #[ORM\Column]
    private int $primeCible = 0;

    #[ORM\Column]
    private int $prime = 0;

    /** @var list<array<string, mixed>> photo des critères (voir CalculPrime::evaluer) */
    #[ORM\Column(type: Types::JSON)]
    private array $criteres = [];

    /** Note du responsable sur 100. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $appreciationNote = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appreciationTexte = null;

    /** Faute grave ou écart de caisse non justifié : prime suspendue. */
    #[ORM\Column(options: ['default' => false])]
    private bool $suspendue = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifSuspension = null;

    /** Motif d'une nouvelle version (recalcul après validation). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $valideePar = null;

    /** Validation de la prime par la direction, avant la paie. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $primeValideeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $primeValideePar = null;

    /** Montant déjà reporté sur un bulletin de paie par cette version (F). */
    #[ORM\Column(options: ['default' => 0])]
    private int $primeReportee = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BulletinPaie $bulletin = null;

    public function __construct(User $agent, \DateTimeImmutable $mois, string $role, int $version, \DateTimeImmutable $le, ?User $par)
    {
        $this->agent = $agent;
        $this->mois = $mois->modify('first day of this month')->setTime(0, 0);
        $this->role = $role;
        $this->version = max(1, $version);
        $this->creeLe = $le;
        $this->creePar = $par;
    }

    public function getId(): ?int { return $this->id; }
    public function getAgent(): User { return $this->agent; }
    public function getMois(): \DateTimeImmutable { return $this->mois; }
    public function getVersion(): int { return $this->version; }
    public function getStatut(): string { return $this->statut; }
    public function getRole(): string { return $this->role; }
    public function getScore(): ?int { return $this->score; }
    public function getTaux(): int { return $this->taux; }
    public function getPrimeCible(): int { return $this->primeCible; }
    public function getPrime(): int { return $this->prime; }
    /** @return list<array<string, mixed>> */
    public function getCriteres(): array { return $this->criteres; }
    public function getAppreciationNote(): ?int { return $this->appreciationNote; }
    public function getAppreciationTexte(): ?string { return $this->appreciationTexte; }
    public function isSuspendue(): bool { return $this->suspendue; }
    public function getMotifSuspension(): ?string { return $this->motifSuspension; }
    public function getMotif(): ?string { return $this->motif; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function getValideeLe(): ?\DateTimeImmutable { return $this->valideeLe; }
    public function getValideePar(): ?User { return $this->valideePar; }
    public function getPrimeValideeLe(): ?\DateTimeImmutable { return $this->primeValideeLe; }
    public function getPrimeValideePar(): ?User { return $this->primeValideePar; }
    public function getPrimeReportee(): int { return $this->primeReportee; }
    public function getBulletin(): ?BulletinPaie { return $this->bulletin; }

    public function estValidee(): bool
    {
        return self::VALIDEE === $this->statut;
    }

    public function estModifiable(): bool
    {
        return self::BROUILLON === $this->statut;
    }

    /**
     * Résultat du calcul (CalculPrime::evaluer), tant que l'évaluation est
     * en brouillon.
     *
     * @param array{score: ?int, taux: int, primeCible: int, prime: int, criteres: list<array<string, mixed>>} $resultat
     */
    public function appliquer(array $resultat, string $role): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Évaluation validée : recalculez-la dans une nouvelle version.');
        }
        $this->role = $role;
        $this->score = $resultat['score'];
        $this->taux = $resultat['taux'];
        $this->primeCible = $resultat['primeCible'];
        $this->prime = $resultat['prime'];
        $this->criteres = $resultat['criteres'];

        return $this;
    }

    public function apprecier(?int $note, ?string $texte): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Évaluation validée : recalculez-la dans une nouvelle version.');
        }
        $this->appreciationNote = null !== $note ? max(0, min(100, $note)) : null;
        $texte = null !== $texte ? trim($texte) : '';
        $this->appreciationTexte = '' !== $texte ? mb_substr($texte, 0, 255) : null;

        return $this;
    }

    public function suspendre(bool $suspendue, ?string $motif): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Évaluation validée : recalculez-la dans une nouvelle version.');
        }
        $motif = null !== $motif ? trim($motif) : '';
        $this->suspendue = $suspendue;
        $this->motifSuspension = $suspendue && '' !== $motif ? mb_substr($motif, 0, 255) : null;

        return $this;
    }

    public function valider(User $par, \DateTimeImmutable $le): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Cette évaluation est déjà validée.');
        }
        $this->statut = self::VALIDEE;
        $this->valideePar = $par;
        $this->valideeLe = $le;

        return $this;
    }

    /** Version remplacée par une version plus récente, validée. */
    public function remplacer(): static
    {
        $this->statut = self::REMPLACEE;

        return $this;
    }

    /**
     * Nouvelle version (brouillon) d'une évaluation validée, avec son
     * motif ; l'appréciation et la suspension sont reprises.
     */
    public function nouvelleVersion(string $motif, User $par, \DateTimeImmutable $le): self
    {
        if (!$this->estValidee()) {
            throw new \LogicException('Seule une évaluation validée se recalcule dans une nouvelle version.');
        }
        $suivante = new self($this->agent, $this->mois, $this->role, $this->version + 1, $le, $par);
        $suivante->motif = mb_substr(trim($motif), 0, 255);
        $suivante->appreciationNote = $this->appreciationNote;
        $suivante->appreciationTexte = $this->appreciationTexte;
        $suivante->suspendue = $this->suspendue;
        $suivante->motifSuspension = $this->motifSuspension;
        $suivante->criteres = $this->criteres;
        $suivante->score = $this->score;
        $suivante->taux = $this->taux;
        $suivante->primeCible = $this->primeCible;
        $suivante->prime = $this->prime;

        return $suivante;
    }

    public function validerPrime(User $par, \DateTimeImmutable $le): static
    {
        if (!$this->estValidee()) {
            throw new \LogicException('Validez d\'abord l\'évaluation.');
        }
        $this->primeValideePar = $par;
        $this->primeValideeLe = $le;

        return $this;
    }

    public function primeEstValidee(): bool
    {
        return null !== $this->primeValideeLe;
    }

    /** Montant reporté sur un bulletin (positif ou régularisation négative). */
    public function reporter(int $montant, BulletinPaie $bulletin): static
    {
        $this->primeReportee += $montant;
        $this->bulletin = $bulletin;

        return $this;
    }
}
