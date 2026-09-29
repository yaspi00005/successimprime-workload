<?php

namespace App\Entity;

use App\Repository\JournalActiviteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'activité : trace création, modification et suppression
 * des entités métier principales, avec les valeurs avant/après en JSON.
 *
 * Alimenté automatiquement par App\EventSubscriber\AuditSubscriber.
 */
#[ORM\Entity(repositoryClass: JournalActiviteRepository::class)]
class JournalActivite
{
    public const ACTION_CREATION = 'creation';
    public const ACTION_MODIFICATION = 'modification';
    public const ACTION_SUPPRESSION = 'suppression';
    public const ACTION_DOUBLON_BLOQUE = 'doublon_bloque';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Nom court de l'entité (ex : "Commandes", "Clients").
     */
    #[ORM\Column(length: 100)]
    private string $entite = '';

    #[ORM\Column]
    private int $entiteId = 0;

    /**
     * creation | modification | suppression.
     */
    #[ORM\Column(length: 20)]
    private string $action = '';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $donneesAvant = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $donneesApres = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $utilisateur = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntite(): string
    {
        return $this->entite;
    }

    public function setEntite(string $entite): static
    {
        $this->entite = $entite;

        return $this;
    }

    public function getEntiteId(): int
    {
        return $this->entiteId;
    }

    public function setEntiteId(int $entiteId): static
    {
        $this->entiteId = $entiteId;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getDonneesAvant(): ?array
    {
        return $this->donneesAvant;
    }

    public function setDonneesAvant(?array $donneesAvant): static
    {
        $this->donneesAvant = $donneesAvant;

        return $this;
    }

    public function getDonneesApres(): ?array
    {
        return $this->donneesApres;
    }

    public function setDonneesApres(?array $donneesApres): static
    {
        $this->donneesApres = $donneesApres;

        return $this;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * Champs modifiés entre avant et après (utile pour l'affichage).
     *
     * @return string[]
     */
    public function getChampsModifies(): array
    {
        if ($this->donneesAvant === null || $this->donneesApres === null) {
            return [];
        }

        $champs = [];

        foreach ($this->donneesApres as $champ => $valeur) {
            $ancienneValeur = $this->donneesAvant[$champ] ?? null;

            if ($ancienneValeur !== $valeur) {
                $champs[] = $champ;
            }
        }

        return $champs;
    }
}
