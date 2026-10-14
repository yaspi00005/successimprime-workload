<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relevé du crédit IA d'un client : achat d'un pack, image générée,
 * remboursement d'une génération échouée, paiement rejeté par la caisse.
 * Écrit en ligne, jamais modifié une fois acquis ; il monte au local.
 *
 * Un achat payé au numéro marchand (sans les clés Orange Money) reste
 * « en attente » et ne compte pas tant que la caisse n'a pas validé le
 * paiement de sa commande.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mouvement_credit_ia')]
#[ORM\UniqueConstraint(name: 'uniq_mouvement_credit_ia_cle', columns: ['cle'])]
#[ORM\Index(name: 'idx_mouvement_credit_ia_etat', columns: ['etat'])]
class MouvementCreditIa
{
    use PublicIdTrait;

    public const ACHAT = 'achat';
    public const DEBIT = 'debit';
    public const REMBOURSEMENT = 'remboursement';
    public const REJET = 'rejet';

    public const TYPES = [
        self::ACHAT => 'Achat de crédit',
        self::DEBIT => 'Image générée',
        self::REMBOURSEMENT => 'Génération échouée : remboursée',
        self::REJET => 'Paiement rejeté par la caisse',
    ];

    public const EN_ATTENTE = 'en_attente';
    public const ACQUIS = 'acquis';
    public const ANNULE = 'annule';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 14)]
    private string $type = self::ACHAT;

    /** En F : positif pour un achat ou un remboursement, négatif pour une image ou un rejet. */
    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column]
    private int $images = 0;

    #[ORM\Column(length: 120)]
    private string $libelle = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(length: 10)]
    private string $etat = self::ACQUIS;

    /** Empêche d'écrire deux fois le même mouvement (achat d'une transaction, rejet d'un paiement…). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $cle = null;

    /** Commande du pack de crédit. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouveau(Clients $client, string $type, int $montant, int $images, string $libelle, ?string $auteur, ?string $cle, \DateTimeImmutable $le, string $etat = self::ACQUIS): self
    {
        $m = new self();
        $m->client = $client;
        $m->type = $type;
        $m->montant = $montant;
        $m->images = $images;
        $m->libelle = mb_substr($libelle, 0, 120);
        $m->auteur = null !== $auteur ? mb_substr($auteur, 0, 80) : null;
        $m->cle = null !== $cle ? mb_substr($cle, 0, 60) : null;
        $m->creeLe = $le;
        $m->etat = $etat;

        return $m;
    }

    public function acquerir(): void
    {
        if (self::EN_ATTENTE === $this->etat) {
            $this->etat = self::ACQUIS;
        }
    }

    public function annuler(): void
    {
        if (self::EN_ATTENTE === $this->etat) {
            $this->etat = self::ANNULE;
        }
    }

    public function estAcquis(): bool { return self::ACQUIS === $this->etat; }
    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getType(): string { return $this->type; }
    public function getLibelleType(): string { return self::TYPES[$this->type] ?? $this->type; }
    public function getMontant(): int { return $this->montant; }
    public function getImages(): int { return $this->images; }
    public function getLibelle(): string { return $this->libelle; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getEtat(): string { return $this->etat; }
    public function getCle(): ?string { return $this->cle; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $commande): static { $this->commande = $commande; return $this; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
    public function setPaiement(?Paiements $paiement): static { $this->paiement = $paiement; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
}
