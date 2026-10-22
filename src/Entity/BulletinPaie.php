<?php

namespace App\Entity;

use App\Repository\BulletinPaieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bulletin de paie d'un mois : salaire de base de la fiche employé et
 * lignes ajoutées par la RH (primes, retenues, ITS ou INPS quand l'option
 * est active). Brouillon, puis validé (l'employé le voit), puis payé par
 * un décaissement confidentiel en trésorerie.
 */
#[ORM\Entity(repositoryClass: BulletinPaieRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_bulletin_mois', columns: ['utilisateur_id', 'mois'])]
class BulletinPaie
{
    public const BROUILLON = 'brouillon';
    public const VALIDE = 'valide';
    public const PAYE = 'paye';

    public const ETATS = [self::BROUILLON => 'Brouillon', self::VALIDE => 'Validé', self::PAYE => 'Payé'];

    /** Type de ligne => [libellé, sens] : +1 ajoute au salaire, -1 retient, 0 au choix. */
    public const LIGNES = [
        'prime' => ['Prime', 1],
        'heures_sup' => ['Heures supplémentaires', 1],
        'indemnite' => ['Indemnité', 1],
        'absence' => ['Retenue pour absence', -1],
        'avance' => ['Avance sur salaire', -1],
        'its' => ['ITS', -1],
        'inps' => ['Cotisation INPS', -1],
        'autre' => ['Autre', 0],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $utilisateur;

    /** Premier jour du mois payé. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $mois;

    #[ORM\Column]
    private int $salaireBase;

    /** @var list<array{type: string, libelle: string, montant: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $lignes = [];

    #[ORM\Column(length: 20)]
    private string $etat = self::BROUILLON;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    private ?User $validePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MouvementTresorerie $mouvement = null;

    public function __construct(User $utilisateur, \DateTimeImmutable $mois, int $salaireBase, \DateTimeImmutable $creeLe)
    {
        $this->utilisateur = $utilisateur;
        $this->mois = self::premierDuMois($mois);
        $this->salaireBase = max(0, $salaireBase);
        $this->creeLe = $creeLe;
    }

    public static function premierDuMois(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Ajoute une ligne. Le sens du type décide du signe ; « autre » garde
     * le signe saisi.
     *
     * @return string|null message d'erreur
     */
    public function ajouterLigne(string $type, string $libelle, int $montant): ?string
    {
        if (!$this->modifiable()) {
            return 'Ce bulletin est validé : il ne change plus.';
        }
        if (!isset(self::LIGNES[$type])) {
            return 'Type de ligne inconnu.';
        }
        if ($montant === 0 || abs($montant) > 100_000_000) {
            return 'Indiquez un montant.';
        }
        $sens = self::LIGNES[$type][1];
        $libelle = trim($libelle) !== '' ? mb_substr(trim($libelle), 0, 120) : self::LIGNES[$type][0];
        $this->lignes[] = ['type' => $type, 'libelle' => $libelle, 'montant' => $sens === 0 ? $montant : $sens * abs($montant)];

        return null;
    }

    public function retirerLigne(int $index): void
    {
        if ($this->modifiable() && isset($this->lignes[$index])) {
            array_splice($this->lignes, $index, 1);
        }
    }

    public function setSalaireBase(int $salaire): static
    {
        if ($this->modifiable()) {
            $this->salaireBase = max(0, $salaire);
        }

        return $this;
    }

    /** Salaire brut : base et lignes positives, hors ITS et INPS. */
    public function brut(): int
    {
        return $this->salaireBase + array_sum(array_map(static fn (array $l): int => $l['montant'] > 0 ? $l['montant'] : 0, $this->lignes));
    }

    /** Total retenu (positif). */
    public function retenues(): int
    {
        return -array_sum(array_map(static fn (array $l): int => $l['montant'] < 0 ? $l['montant'] : 0, $this->lignes));
    }

    public function net(): int
    {
        return max(0, $this->brut() - $this->retenues());
    }

    public function valider(User $par, \DateTimeImmutable $le): ?string
    {
        if ($this->etat !== self::BROUILLON) {
            return 'Ce bulletin est déjà validé.';
        }
        if ($this->net() <= 0) {
            return 'Le net à payer est nul : vérifiez le salaire de base.';
        }
        $this->etat = self::VALIDE;
        $this->validePar = $par;
        $this->valideLe = $le;

        return null;
    }

    /** Remet un bulletin validé (non payé) en brouillon pour le corriger. */
    public function rouvrir(): ?string
    {
        if ($this->etat !== self::VALIDE) {
            return $this->etat === self::PAYE ? 'Ce bulletin est payé : il ne change plus.' : null;
        }
        $this->etat = self::BROUILLON;
        $this->validePar = null;
        $this->valideLe = null;

        return null;
    }

    public function payer(MouvementTresorerie $mouvement): void
    {
        $this->etat = self::PAYE;
        $this->mouvement = $mouvement;
    }

    public function modifiable(): bool
    {
        return $this->etat === self::BROUILLON;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): User
    {
        return $this->utilisateur;
    }

    public function getMois(): \DateTimeImmutable
    {
        return $this->mois;
    }

    public function getSalaireBase(): int
    {
        return $this->salaireBase;
    }

    /** @return list<array{type: string, libelle: string, montant: int}> */
    public function getLignes(): array
    {
        return $this->lignes;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function libelleEtat(): string
    {
        return self::ETATS[$this->etat];
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getMouvement(): ?MouvementTresorerie
    {
        return $this->mouvement;
    }
}
