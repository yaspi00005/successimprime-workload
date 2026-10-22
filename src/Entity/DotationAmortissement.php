<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use App\Repository\DotationAmortissementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dotation aux amortissements d'un bien pour un mois (M04) : le montant
 * passé en écriture SYSCOHADA (débit 681, crédit 28x de la catégorie) et
 * l'amortissement cumulé à la fin du mois. Une fois passée, une dotation
 * ne change plus ; le mois suivant reprend l'écart (relevé tardif).
 * Voir App\Service\Immobilisations\Patrimoine::doter(). Table locale.
 */
#[ORM\Entity(repositoryClass: DotationAmortissementRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_dotation_bien_mois', columns: ['immobilisation_id', 'mois'])]
class DotationAmortissement implements AppartientASociete
{
    use SocieteTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Immobilisation $immobilisation;

    /** Premier jour du mois doté. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $mois;

    /** F passés en charge ce mois-ci. */
    #[ORM\Column]
    private int $montant;

    /** Amortissement cumulé à la fin du mois (F). */
    #[ORM\Column]
    private int $cumul;

    /** Usage du mois dans l'unité de la machine (à l'usage seulement). */
    #[ORM\Column(name: 'usage_mois', nullable: true)]
    private ?float $usage = null;

    /** 'usage' ou 'lineaire'. */
    #[ORM\Column(length: 10)]
    private string $methode;

    /** Compte crédité (28x de la catégorie au moment de la dotation). */
    #[ORM\Column(length: 8)]
    private string $compte;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    public function __construct(Immobilisation $immobilisation, \DateTimeImmutable $mois, int $montant, int $cumul, string $methode, ?float $usage, \DateTimeImmutable $le, ?User $par = null)
    {
        $this->immobilisation = $immobilisation;
        $this->mois = $mois->modify('first day of this month')->setTime(0, 0);
        $this->montant = max(0, $montant);
        $this->cumul = max(0, $cumul);
        $this->methode = $methode;
        $this->usage = $usage;
        $this->compte = $immobilisation->compteAmortissement();
        $this->creeLe = $le;
        $this->creePar = $par;
    }

    public function getId(): ?int { return $this->id; }
    public function getImmobilisation(): Immobilisation { return $this->immobilisation; }
    public function getMois(): \DateTimeImmutable { return $this->mois; }
    public function getMontant(): int { return $this->montant; }
    public function getCumul(): int { return $this->cumul; }
    public function getUsage(): ?float { return $this->usage; }
    public function getMethode(): string { return $this->methode; }
    public function getCompte(): string { return $this->compte; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCreePar(): ?User { return $this->creePar; }

    /** Pièce comptable : « DOT-2026-10 ». */
    public function piece(): string
    {
        return 'DOT-'.$this->mois->format('Y-m');
    }
}
