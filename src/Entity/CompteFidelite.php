<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fidélité d'un client (docs/fidelite.md) : ses valeurs propres, fixées par
 * l'administrateur pour un gros client, et le résumé recalculé à chaque
 * mouvement (solde, progression vers les seuils, points qui expirent
 * bientôt).
 *
 * Seul le résumé descend sur le site, avec les valeurs qui s'appliquent au
 * client : le motif et l'auteur des valeurs propres restent au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compte_fidelite')]
class CompteFidelite
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /* ---------- Valeurs propres au client (null : valeur générale) ---------- */

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $pointsParM2 = null;

    /** Pourcentage du montant pour les articles qui ne se vendent pas au m². */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $tauxAutre = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $seuilM2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $seuilMontant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifPerso = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $persoPar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $persoLe = null;

    /* ---------- Résumé (recalculé, envoyé au site) ---------- */

    /** Client au prix B2B : hors programme. */
    #[ORM\Column(options: ['default' => false])]
    private bool $exclu = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $solde = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $soldeF = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $m2Cumules = '0';

    #[ORM\Column(options: ['default' => 0])]
    private int $montantCumule = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '5.00'])]
    private string $seuilM2Applique = '5';

    #[ORM\Column(options: ['default' => 25000])]
    private int $seuilMontantApplique = 25000;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '100.00'])]
    private string $pointsParM2Applique = '100';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '2.00'])]
    private string $tauxAutreApplique = '2';

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 2])]
    private int $multiplicateurJour = 2;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 72])]
    private int $delaiHeures = 72;

    #[ORM\Column(options: ['default' => 0])]
    private int $pointsExpirant = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expirationProchaine = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $majLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->majLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function setClient(?Clients $client): static { $this->client = $client; return $this; }

    public function getPointsParM2(): ?float { return null === $this->pointsParM2 ? null : (float) $this->pointsParM2; }
    public function getTauxAutre(): ?float { return null === $this->tauxAutre ? null : (float) $this->tauxAutre; }
    public function getSeuilM2(): ?float { return null === $this->seuilM2 ? null : (float) $this->seuilM2; }
    public function getSeuilMontant(): ?int { return $this->seuilMontant; }
    public function getMotifPerso(): ?string { return $this->motifPerso; }
    public function getPersoPar(): ?User { return $this->persoPar; }
    public function getPersoLe(): ?\DateTimeImmutable { return $this->persoLe; }

    public function aDesValeursPropres(): bool
    {
        return null !== $this->pointsParM2 || null !== $this->tauxAutre || null !== $this->seuilM2 || null !== $this->seuilMontant;
    }

    /**
     * @return array{pointsParM2: ?float, tauxAutre: ?float, seuilM2: ?float, seuilMontant: ?int}
     */
    public function valeursPropres(): array
    {
        return ['pointsParM2' => $this->getPointsParM2(), 'tauxAutre' => $this->getTauxAutre(), 'seuilM2' => $this->getSeuilM2(), 'seuilMontant' => $this->seuilMontant];
    }

    public function personnaliser(?float $pointsParM2, ?float $tauxAutre, ?float $seuilM2, ?int $seuilMontant, ?string $motif, ?User $par, \DateTimeImmutable $le): static
    {
        $this->pointsParM2 = null === $pointsParM2 ? null : number_format(max(0, $pointsParM2), 2, '.', '');
        $this->tauxAutre = null === $tauxAutre ? null : number_format(min(100, max(0, $tauxAutre)), 2, '.', '');
        $this->seuilM2 = null === $seuilM2 ? null : number_format(max(0, $seuilM2), 2, '.', '');
        $this->seuilMontant = null === $seuilMontant ? null : max(0, $seuilMontant);
        $this->motifPerso = null !== $motif && '' !== trim($motif) ? mb_substr(trim($motif), 0, 255) : null;
        $this->persoPar = $par;
        $this->persoLe = $le;

        return $this;
    }

    public function isExclu(): bool { return $this->exclu; }
    public function getSolde(): int { return $this->solde; }
    public function getSoldeF(): int { return $this->soldeF; }
    public function getM2Cumules(): float { return (float) $this->m2Cumules; }
    public function getMontantCumule(): int { return $this->montantCumule; }
    public function getSeuilM2Applique(): float { return (float) $this->seuilM2Applique; }
    public function getSeuilMontantApplique(): int { return $this->seuilMontantApplique; }
    public function getPointsParM2Applique(): float { return (float) $this->pointsParM2Applique; }
    public function getTauxAutreApplique(): float { return (float) $this->tauxAutreApplique; }
    public function getMultiplicateurJour(): int { return $this->multiplicateurJour; }
    public function getDelaiHeures(): int { return $this->delaiHeures; }
    public function getPointsExpirant(): int { return $this->pointsExpirant; }
    public function getExpirationProchaine(): ?\DateTimeImmutable { return $this->expirationProchaine; }
    public function getMajLe(): \DateTimeImmutable { return $this->majLe; }

    public function eligibleM2(): bool { return $this->getM2Cumules() >= $this->getSeuilM2Applique(); }
    public function eligibleMontant(): bool { return $this->montantCumule > 0 && $this->montantCumule >= $this->seuilMontantApplique; }

    /**
     * Écrit le résumé ; ne change rien (et donc n'envoie rien au site)
     * quand les valeurs sont les mêmes.
     *
     * @param array{exclu: bool, solde: int, soldeF: int, m2: float, montant: int, seuilM2: float, seuilMontant: int, pointsParM2: float, tauxAutre: float, multiplicateurJour: int, delaiHeures: int, expirant: int, expiration: ?\DateTimeImmutable} $r
     */
    public function majResume(array $r, \DateTimeImmutable $le): bool
    {
        $nouveau = [
            'exclu' => $r['exclu'],
            'solde' => $r['solde'],
            'soldeF' => $r['soldeF'],
            'm2Cumules' => number_format($r['m2'], 2, '.', ''),
            'montantCumule' => $r['montant'],
            'seuilM2Applique' => number_format($r['seuilM2'], 2, '.', ''),
            'seuilMontantApplique' => $r['seuilMontant'],
            'pointsParM2Applique' => number_format($r['pointsParM2'], 2, '.', ''),
            'tauxAutreApplique' => number_format($r['tauxAutre'], 2, '.', ''),
            'multiplicateurJour' => $r['multiplicateurJour'],
            'delaiHeures' => $r['delaiHeures'],
            'pointsExpirant' => $r['expirant'],
        ];
        $change = $this->expirationProchaine?->format('Y-m-d') !== $r['expiration']?->format('Y-m-d');
        foreach ($nouveau as $champ => $valeur) {
            $actuel = $this->$champ;
            if (\is_string($valeur) ? (float) $actuel !== (float) $valeur : $actuel !== $valeur) {
                $this->$champ = $valeur;
                $change = true;
            }
        }
        if ($change) {
            $this->expirationProchaine = $r['expiration'];
            $this->majLe = $le;
        }

        return $change;
    }
}
