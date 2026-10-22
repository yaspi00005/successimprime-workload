<?php

namespace App\Entity;

use App\Repository\PrimeAgentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage de prime propre à une personne (N03 « Prime cible fixée par
 * rôle et par personne ») : son rôle de prime quand le profil ne suffit
 * pas, et sa prime cible quand elle diffère de celle du rôle. Table
 * locale, non synchronisée.
 */
#[ORM\Entity(repositoryClass: PrimeAgentRepository::class)]
class PrimeAgent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $agent;

    /** Rôle de prime imposé (null = déduit du profil, « aucun » = pas de prime). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $role = null;

    /** Prime cible de la personne (null = celle du rôle). */
    #[ORM\Column(nullable: true)]
    private ?int $primeCible = null;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    public function __construct(User $agent)
    {
        $this->agent = $agent;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAgent(): User { return $this->agent; }
    public function getRole(): ?string { return $this->role; }
    public function getPrimeCible(): ?int { return $this->primeCible; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function getModifiePar(): ?User { return $this->modifiePar; }

    public function modifier(?string $role, ?int $primeCible, ?User $par, \DateTimeImmutable $le): static
    {
        $this->role = $role;
        $this->primeCible = null !== $primeCible ? max(0, $primeCible) : null;
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }
}
