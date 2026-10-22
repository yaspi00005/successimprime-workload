<?php

namespace App\Entity;

use App\Repository\CongeEmployeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Droit aux congés d'un employé : activé par la RH, avec son solde de
 * jours. La RH peut demander de choisir ses dates dans une plage, sans
 * dépasser un nombre de jours.
 */
#[ORM\Entity(repositoryClass: CongeEmployeRepository::class)]
#[ORM\Table(name: 'conge_employe')]
#[ORM\UniqueConstraint(name: 'uniq_conge_employe_utilisateur', columns: ['utilisateur_id'])]
class CongeEmploye
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column]
    private bool $actif = false;

    #[ORM\Column]
    private float $solde = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plageDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plageFin = null;

    #[ORM\Column(nullable: true)]
    private ?float $plageJours = null;

    public function __construct(User $utilisateur)
    {
        $this->utilisateur = $utilisateur;
    }

    public function regler(bool $actif, float $solde, ?\DateTimeImmutable $plageDebut, ?\DateTimeImmutable $plageFin, ?float $plageJours): void
    {
        $this->actif = $actif;
        $this->solde = max(0, round($solde * 2) / 2);
        $complete = $plageDebut !== null && $plageFin !== null && $plageFin >= $plageDebut;
        $this->plageDebut = $complete ? $plageDebut : null;
        $this->plageFin = $complete ? $plageFin : null;
        $this->plageJours = $complete && $plageJours !== null && $plageJours > 0 ? $plageJours : null;
    }

    public function debiter(float $jours): void
    {
        $this->solde = max(0, $this->solde - $jours);
    }

    /**
     * Pourquoi une période ne peut pas être demandée, ou null.
     */
    public function refus(\DateTimeImmutable $du, \DateTimeImmutable $au, float $jours): ?string
    {
        return match (true) {
            !$this->actif => 'Le droit aux congés n\'est pas encore activé pour vous. Voyez la RH.',
            $jours <= 0 => 'Cette période ne compte aucun jour travaillé.',
            $jours > $this->solde => \sprintf('La période compte %s jour%s ; votre solde est de %s.', self::nombre($jours), $jours > 1 ? 's' : '', self::nombre($this->solde)),
            $this->plageDebut !== null && ($du < $this->plageDebut || $au > $this->plageFin) => \sprintf('Choisissez vos dates entre le %s et le %s.', $this->plageDebut->format('d/m'), $this->plageFin->format('d/m')),
            $this->plageJours !== null && $jours > $this->plageJours => \sprintf('Pas plus de %s jours sur cette plage.', self::nombre($this->plageJours)),
            default => null,
        };
    }

    public static function nombre(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ''), '0'), ',');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): User
    {
        return $this->utilisateur;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function getSolde(): float
    {
        return $this->solde;
    }

    public function getPlageDebut(): ?\DateTimeImmutable
    {
        return $this->plageDebut;
    }

    public function getPlageFin(): ?\DateTimeImmutable
    {
        return $this->plageFin;
    }

    public function getPlageJours(): ?float
    {
        return $this->plageJours;
    }
}
