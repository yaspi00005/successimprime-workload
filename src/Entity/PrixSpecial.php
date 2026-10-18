<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\PrixSpecialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Prix négocié avec un client pour une configuration de produit (maquette
 * PR03 du 18/10/2026, docs/produits.md).
 *
 * Il remplace la grille du client (Standard ou Revendeur) jusqu'à sa date
 * de fin. Au comptoir comme en ligne, il est posé en remise sur la ligne
 * (prix du catalogue moins prix négocié) : la facture garde le prix
 * normal, et une promotion ne s'y ajoute pas (une seule remise par ligne).
 *
 * Un prix qui dépasse les garde-fous (remise maximale sans accord, marge
 * minimale) attend l'accord de la direction avant de s'appliquer.
 */
#[ORM\Entity(repositoryClass: PrixSpecialRepository::class)]
#[ORM\Table(name: 'prix_special')]
#[ORM\Index(name: 'idx_prix_special_client', columns: ['client_id', 'statut'])]
class PrixSpecial
{
    use PublicIdTrait;

    public const ACTIF = 'actif';
    public const ATTENTE = 'attente';
    public const REFUSE = 'refuse';
    public const ARRETE = 'arrete';

    public const STATUTS = [
        self::ACTIF => 'Appliqué',
        self::ATTENTE => 'Accord demandé',
        self::REFUSE => 'Refusé',
        self::ARRETE => 'Arrêté',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProduitConfiguration $configuration = null;

    /** Prix négocié par unité de vente (m², pièce, lot…), en francs. */
    #[ORM\Column]
    private int $prix = 0;

    /** Dernier jour compris ; vide : sans limite. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finLe = null;

    #[ORM\Column(length: 10, options: ['default' => self::ACTIF])]
    private string $statut = self::ACTIF;

    /** Raison de l'accord ou de la demande. Reste à l'atelier. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decideLe = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getConfiguration(): ?ProduitConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(?ProduitConfiguration $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function getPrix(): int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = max(0, $prix);

        return $this;
    }

    public function getFinLe(): ?\DateTimeImmutable
    {
        return $this->finLe;
    }

    public function setFinLe(?\DateTimeImmutable $finLe): static
    {
        $this->finLe = $finLe;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = \array_key_exists($statut, self::STATUTS) ? $statut : self::ATTENTE;

        return $this;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $note = trim((string) $note);
        $this->note = '' === $note ? null : mb_substr($note, 0, 255);

        return $this;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDecidePar(): ?User
    {
        return $this->decidePar;
    }

    public function getDecideLe(): ?\DateTimeImmutable
    {
        return $this->decideLe;
    }

    public function decider(bool $accepte, ?User $par, \DateTimeImmutable $le): static
    {
        $this->statut = $accepte ? self::ACTIF : self::REFUSE;
        $this->decidePar = $par;
        $this->decideLe = $le;

        return $this;
    }

    /** Appliqué ce jour-là : accordé et pas encore échu. */
    public function estValable(\DateTimeInterface $jour): bool
    {
        return self::ACTIF === $this->statut
            && (null === $this->finLe || $this->finLe->format('Y-m-d') >= $jour->format('Y-m-d'));
    }

    public function estEchu(\DateTimeInterface $jour): bool
    {
        return null !== $this->finLe && $this->finLe->format('Y-m-d') < $jour->format('Y-m-d');
    }
}
