<?php

namespace App\Entity;

use App\Repository\ReglageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Valeurs d'une tuile de l'écran Paramètres (une ligne par tuile).
 *
 * Depuis l'admin en ligne, un changement n'est pas appliqué tout de
 * suite : il attend dans « proposition » qu'un administrateur le valide
 * sur l'ERP local.
 */
#[ORM\Entity(repositoryClass: ReglageRepository::class)]
#[ORM\Table(name: 'reglage')]
#[ORM\UniqueConstraint(name: 'uniq_reglage_cle', columns: ['cle'])]
class Reglage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $cle;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $valeurs = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $proposition = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $proposePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $proposeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    public function __construct(string $cle)
    {
        $this->cle = $cle;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    /** @return array<string, mixed> */
    public function getValeurs(): array
    {
        return $this->valeurs;
    }

    /** @param array<string, mixed> $valeurs */
    public function modifier(array $valeurs, ?User $par, \DateTimeImmutable $le): static
    {
        $this->valeurs = $valeurs;
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getProposition(): ?array
    {
        return $this->proposition;
    }

    /** @param array<string, mixed> $valeurs */
    public function proposer(array $valeurs, ?User $par, \DateTimeImmutable $le): static
    {
        $this->proposition = $valeurs;
        $this->proposePar = $par;
        $this->proposeLe = $le;

        return $this;
    }

    public function retirerProposition(): static
    {
        $this->proposition = null;
        $this->proposePar = null;
        $this->proposeLe = null;

        return $this;
    }

    public function getProposePar(): ?User
    {
        return $this->proposePar;
    }

    public function getProposeLe(): ?\DateTimeImmutable
    {
        return $this->proposeLe;
    }

    public function getModifiePar(): ?User
    {
        return $this->modifiePar;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
