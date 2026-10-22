<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Exception B2B (docs/promotions.md) : un agent demande d'appliquer une
 * promotion à une ligne d'un client au prix B2B. La ligne reste au prix B2B
 * tant qu'un responsable (droit Promotions) n'a pas accepté ; la demande et
 * la décision sont inscrites au journal d'activité.
 *
 * Une ligne de commande ou une ligne de devis, jamais les deux. Reste au
 * local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'promotion_exception')]
#[ORM\Index(name: 'idx_promotion_exception_statut', columns: ['statut', 'demande_le'])]
class DemandeExceptionPromotion
{
    public const EN_ATTENTE = 'en_attente';
    public const ACCEPTEE = 'acceptee';
    public const REFUSEE = 'refusee';

    public const STATUTS = [
        self::EN_ATTENTE => 'En attente',
        self::ACCEPTEE => 'Acceptée',
        self::REFUSEE => 'Refusée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Promotion $promotion = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CommandesDetails $ligneCommande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DevisDetails $ligneDevis = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 12)]
    private string $statut = self::EN_ATTENTE;

    /** Remise par unité que donnerait la promotion, au moment de la demande. */
    #[ORM\Column]
    private int $remiseUnitaire = 0;

    /** Remise B2B déjà sur la ligne (par unité), pour comparer. */
    #[ORM\Column]
    private int $remiseB2b = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $demandePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $demandeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decideLe = null;

    /** Motif du refus, ou note de l'acceptation. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reponse = null;

    public function __construct(Promotion $promotion, Clients $client, CommandesDetails|DevisDetails $ligne, int $remiseUnitaire, int $remiseB2b, ?User $par, \DateTimeImmutable $le)
    {
        $this->promotion = $promotion;
        $this->client = $client;
        if ($ligne instanceof CommandesDetails) {
            $this->ligneCommande = $ligne;
        } else {
            $this->ligneDevis = $ligne;
        }
        $this->remiseUnitaire = max(0, $remiseUnitaire);
        $this->remiseB2b = max(0, $remiseB2b);
        $this->demandePar = $par;
        $this->demandeLe = $le;
    }

    /** Devis transformé en commande : l'exception acceptée suit la ligne. */
    public function reprisePour(CommandesDetails $ligne): self
    {
        $copie = new self($this->promotion, $this->client, $ligne, $this->remiseUnitaire, $this->remiseB2b, $this->demandePar, $this->demandeLe);
        $copie->statut = $this->statut;
        $copie->decidePar = $this->decidePar;
        $copie->decideLe = $this->decideLe;
        $copie->reponse = $this->reponse;

        return $copie;
    }

    /** Demande encore en attente sur un devis transformé : elle passe sur la ligne de la commande. */
    public function transfererVers(CommandesDetails $ligne): static
    {
        $this->ligneCommande = $ligne;
        $this->ligneDevis = null;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getPromotion(): ?Promotion { return $this->promotion; }
    public function getLigneCommande(): ?CommandesDetails { return $this->ligneCommande; }
    public function getLigneDevis(): ?DevisDetails { return $this->ligneDevis; }
    public function getLigne(): CommandesDetails|DevisDetails|null { return $this->ligneCommande ?? $this->ligneDevis; }
    public function getClient(): ?Clients { return $this->client; }
    public function getStatut(): string { return $this->statut; }
    public function getStatutLabel(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function estEnAttente(): bool { return self::EN_ATTENTE === $this->statut; }
    public function estAcceptee(): bool { return self::ACCEPTEE === $this->statut; }
    public function estRefusee(): bool { return self::REFUSEE === $this->statut; }
    public function getRemiseUnitaire(): int { return $this->remiseUnitaire; }
    public function getRemiseB2b(): int { return $this->remiseB2b; }
    public function getDemandePar(): ?User { return $this->demandePar; }
    public function getDemandeLe(): \DateTimeImmutable { return $this->demandeLe; }
    public function getDecidePar(): ?User { return $this->decidePar; }
    public function getDecideLe(): ?\DateTimeImmutable { return $this->decideLe; }
    public function getReponse(): ?string { return $this->reponse; }

    /** Numéro du devis ou de la commande. */
    public function getDocument(): ?string
    {
        return $this->ligneCommande?->getCommande()?->getNumero() ?? $this->ligneDevis?->getDevis()?->getNumero();
    }

    public function decider(bool $accepter, ?string $reponse, User $par, \DateTimeImmutable $le): static
    {
        if (!$this->estEnAttente()) {
            throw new \DomainException('Cette demande a déjà été traitée.');
        }
        $reponse = null !== $reponse && '' !== trim($reponse) ? mb_substr(trim($reponse), 0, 255) : null;
        if (!$accepter && null === $reponse) {
            throw new \DomainException('Le motif du refus est obligatoire.');
        }
        $this->statut = $accepter ? self::ACCEPTEE : self::REFUSEE;
        $this->reponse = $reponse;
        $this->decidePar = $par;
        $this->decideLe = $le;

        return $this;
    }
}
