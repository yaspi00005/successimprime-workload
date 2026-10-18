<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dépôt Orange Money fait par le client depuis son espace (« Mon solde »)
 * pour le laisser sur son compte à la boutique (docs/espace-client.md).
 *
 * Créé en ligne au départ chez Orange (« initié »), « reçu » quand Orange
 * confirme : il monte alors au local, où la caisse le valide après avoir
 * vu l'argent sur le compte « Orange Money en ligne » (le solde du client
 * monte à ce moment-là, jamais avant) ou le refuse. La décision redescend.
 *
 * Le montant est ce qui va au solde ; le fonds de soutien et le frais de
 * retrait sont payés en plus et ne vont jamais au solde (comme pour le
 * paiement d'une commande).
 */
#[ORM\Entity]
#[ORM\Table(name: 'depot_solde_client')]
#[ORM\UniqueConstraint(name: 'uniq_depot_solde_client_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_depot_solde_client_statut', columns: ['statut'])]
class DepotSoldeClient
{
    use PublicIdTrait;

    public const INITIE = 'initie';
    public const RECU = 'recu';
    public const VALIDE = 'valide';
    public const REJETE = 'rejete';
    public const ECHOUE = 'echoue';

    public const STATUTS = [
        self::INITIE => 'Parti chez Orange Money',
        self::RECU => 'Reçu, en attente de validation',
        self::VALIDE => 'Ajouté au solde',
        self::REJETE => 'Refusé par la boutique',
        self::ECHOUE => 'Non abouti',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** Personne de l'espace client qui a déposé (nom affiché) : monte au local. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    /** Ce qui va au solde, sans les frais. */
    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $fondsSoutien = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $fraisRetrait = 0;

    /** Notre référence chez Orange (order_id). */
    #[ORM\Column(length: 40)]
    private string $reference = '';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $txnid = null;

    #[ORM\Column(length: 10)]
    private string $statut = self::INITIE;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recuLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    /** Raison d'un refus : note interne, reste au local. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRejet = null;

    /** Employé qui a validé ou refusé : reste au local. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traitePar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouveau(Clients $client, int $montant, int $fondsSoutien, int $fraisRetrait, string $reference, ?string $auteur, \DateTimeImmutable $le): self
    {
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Le montant du dépôt doit être positif.');
        }
        $d = new self();
        $d->client = $client;
        $d->montant = $montant;
        $d->fondsSoutien = max(0, $fondsSoutien);
        $d->fraisRetrait = max(0, $fraisRetrait);
        $d->reference = mb_substr($reference, 0, 40);
        $d->auteur = null !== $auteur ? mb_substr($auteur, 0, 80) : null;
        $d->creeLe = $le;

        return $d;
    }

    /** Orange a confirmé : à valider par la boutique. */
    public function recevoir(?string $txnid, \DateTimeImmutable $le): void
    {
        if (self::INITIE === $this->statut) {
            $this->statut = self::RECU;
            $this->txnid = null !== $txnid ? mb_substr($txnid, 0, 60) : null;
            $this->recuLe = $le;
        }
    }

    /** Paiement refusé ou expiré chez Orange. */
    public function echouer(\DateTimeImmutable $le): void
    {
        if (self::INITIE === $this->statut) {
            $this->statut = self::ECHOUE;
            $this->traiteLe = $le;
        }
    }

    public function valider(User $par, \DateTimeImmutable $le): void
    {
        if (self::RECU !== $this->statut) {
            throw new \LogicException('Seul un dépôt reçu et en attente peut être validé.');
        }
        $this->statut = self::VALIDE;
        $this->traitePar = $par;
        $this->traiteLe = $le;
    }

    public function rejeter(string $motif, User $par, \DateTimeImmutable $le): void
    {
        if (self::RECU !== $this->statut) {
            throw new \LogicException('Seul un dépôt reçu et en attente peut être refusé.');
        }
        $this->statut = self::REJETE;
        $this->motifRejet = mb_substr(trim($motif), 0, 255);
        $this->traitePar = $par;
        $this->traiteLe = $le;
    }

    /** Ce qu'Orange a débité au client : le dépôt et les frais. */
    public function getTotal(): int
    {
        return $this->montant + $this->fondsSoutien + $this->fraisRetrait;
    }

    public function estEnAttente(): bool { return self::RECU === $this->statut; }
    /** Parti au local : reçu chez Orange (ou déjà traité). */
    public function estPret(): bool { return !\in_array($this->statut, [self::INITIE, self::ECHOUE], true); }
    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getMontant(): int { return $this->montant; }
    public function getFondsSoutien(): int { return $this->fondsSoutien; }
    public function getFraisRetrait(): int { return $this->fraisRetrait; }
    public function getReference(): string { return $this->reference; }
    public function getTxnid(): ?string { return $this->txnid; }
    public function getStatut(): string { return $this->statut; }
    public function getStatutLabel(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getRecuLe(): ?\DateTimeImmutable { return $this->recuLe; }
    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function getMotifRejet(): ?string { return $this->motifRejet; }
    public function getTraitePar(): ?User { return $this->traitePar; }
}
