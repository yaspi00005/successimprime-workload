<?php

namespace App\Entity;

use App\Repository\BaremePrimeRepository;
use App\Service\Primes\CalculPrime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Barème des primes d'un rôle (maquette N03) : prime cible et critères
 * (clé, poids en %, objectif). Sans ligne en base, le barème par défaut
 * de CalculPrime::BAREMES s'applique. Table locale, non synchronisée.
 */
#[ORM\Entity(repositoryClass: BaremePrimeRepository::class)]
class BaremePrime
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Clé de CalculPrime::ROLES (caisse, graphiste, production…). */
    #[ORM\Column(length: 20, unique: true)]
    private string $role;

    /** Prime cible du rôle, F par mois. */
    #[ORM\Column]
    private int $primeCible = 0;

    /** @var list<array{cle: string, poids: int, objectif: ?float}> */
    #[ORM\Column(type: Types::JSON)]
    private array $criteres = [];

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    public function __construct(string $role)
    {
        $this->role = $role;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRole(): string { return $this->role; }
    public function getPrimeCible(): int { return $this->primeCible; }
    /** @return list<array{cle: string, poids: int, objectif: ?float}> */
    public function getCriteres(): array { return $this->criteres; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function getModifiePar(): ?User { return $this->modifiePar; }

    /**
     * @param list<array{cle: string, poids: int, objectif: ?float}> $criteres
     */
    public function modifier(int $primeCible, array $criteres, ?User $par, \DateTimeImmutable $le): static
    {
        $this->primeCible = max(0, $primeCible);
        $this->criteres = array_values(array_filter($criteres, static fn (array $c): bool => isset(CalculPrime::CRITERES[$c['cle']]) && $c['poids'] > 0));
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }
}
