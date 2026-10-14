<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fidélité programmée par article (demande de Yaya du 13/10/2026,
 * docs/fidelite.md) : un produit rapporte des points ou non, combien, ce
 * que vaut un point, à partir de quel seuil, et entre quelles dates.
 *
 * Une règle peut être préparée à l'avance : elle ne s'applique qu'à partir
 * de son début. Les valeurs propres d'un gros client passent avant elle ;
 * elle passe avant les valeurs générales. Une règle « désactivée » retire
 * le produit du programme pendant sa période. Reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'regle_fidelite')]
#[ORM\Index(name: 'idx_regle_fidelite_produit', columns: ['produit_id', 'debut'])]
class RegleFidelite
{
    /** Points par m² (produit au m²) ou par pièce (autres). */
    public const MODE_UNITE = 'unite';
    /** Pourcentage du montant de la ligne. */
    public const MODE_POURCENTAGE = 'pourcentage';

    public const MODES = [
        self::MODE_UNITE => 'Points par m² ou par pièce',
        self::MODE_POURCENTAGE => '% du montant',
    ];

    /** Le seuil se compte sur ce produit seulement, ou sur toute sa famille (m², autres). */
    public const PORTEE_PRODUIT = 'produit';
    public const PORTEE_FAMILLE = 'famille';

    public const PORTEES = [
        self::PORTEE_PRODUIT => 'sur ce produit',
        self::PORTEE_FAMILLE => 'sur toute la famille',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Produits $produit = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(length: 12)]
    private string $mode = self::MODE_UNITE;

    /** Points par unité, ou pourcentage. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $valeur = '0';

    #[ORM\Column(options: ['default' => 1])]
    private int $valeurPoint = 1;

    /** En m², en pièces ou en francs selon le produit et le mode. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $seuil = '0';

    #[ORM\Column(length: 10)]
    private string $portee = self::PORTEE_PRODUIT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->debut = new \DateTimeImmutable('today');
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduit(): ?Produits { return $this->produit; }
    public function setProduit(?Produits $produit): static { $this->produit = $produit; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): static { $this->mode = isset(self::MODES[$mode]) ? $mode : self::MODE_UNITE; return $this; }
    public function getValeur(): float { return (float) $this->valeur; }
    public function setValeur(float $valeur): static { $this->valeur = number_format(max(0, $valeur), 2, '.', ''); return $this; }
    public function getValeurPoint(): int { return $this->valeurPoint; }
    public function setValeurPoint(int $valeur): static { $this->valeurPoint = max(1, $valeur); return $this; }
    public function getSeuil(): float { return (float) $this->seuil; }
    public function setSeuil(float $seuil): static { $this->seuil = number_format(max(0, $seuil), 2, '.', ''); return $this; }
    public function getPortee(): string { return $this->portee; }
    public function setPortee(string $portee): static { $this->portee = isset(self::PORTEES[$portee]) ? $portee : self::PORTEE_PRODUIT; return $this; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function setDebut(\DateTimeImmutable $debut): static { $this->debut = $debut->setTime(0, 0); return $this; }
    public function getFin(): ?\DateTimeImmutable { return $this->fin; }
    public function setFin(?\DateTimeImmutable $fin): static { $this->fin = $fin?->setTime(0, 0); return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 255) : null; return $this; }
    public function getModifiePar(): ?User { return $this->modifiePar; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function marquerModifiee(?User $par, \DateTimeImmutable $le): static { $this->modifiePar = $par; $this->modifieLe = $le; return $this; }

    /** Vrai le jour donné (début et fin compris). */
    public function sAppliqueLe(\DateTimeInterface $jour): bool
    {
        $j = $jour->format('Y-m-d');

        return $this->debut->format('Y-m-d') <= $j && (null === $this->fin || $j <= $this->fin->format('Y-m-d'));
    }

    /** Valeurs lisibles, pour le journal (avant → après). */
    public function instantane(): array
    {
        return [
            'produit' => $this->produit?->getNom(),
            'actif' => $this->actif,
            'mode' => $this->mode,
            'valeur' => $this->getValeur(),
            'valeurPoint' => $this->valeurPoint,
            'seuil' => $this->getSeuil(),
            'portee' => $this->portee,
            'debut' => $this->debut->format('Y-m-d'),
            'fin' => $this->fin?->format('Y-m-d'),
            'note' => $this->note,
        ];
    }
}
