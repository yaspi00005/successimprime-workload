<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne d'une opération diverse passée une fois pour toutes (journal OD de
 * la comptabilité). Le journal se déduit des opérations ; ces lignes ne
 * servent qu'aux reprises qui n'ont pas d'opération d'origine, comme les
 * paiements avec le solde du client faits avant le relevé du 18/10/2026
 * (commande app:solde-client:aligner-4191, docs/finances.md). Elles ne se
 * modifient pas à l'écran. Table locale (ERP de la boutique).
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_ecriture_diverse_piece_compte', columns: ['piece', 'compte'])]
#[ORM\Index(name: 'idx_ecriture_diverse_date', columns: ['date_ecriture'])]
class EcritureDiverse implements AppartientASociete
{
    use SocieteTrait;

    /** Reprise des paiements avec le solde d'avant le relevé (Yaya, 18/10/2026, Q10). */
    public const ORIGINE_SOLDE_CLIENT = 'solde_client_avant_releve';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $piece;

    #[ORM\Column(name: 'date_ecriture')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 8)]
    private string $compte;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $tiers;

    #[ORM\Column(length: 200)]
    private string $libelle;

    #[ORM\Column]
    private int $debit;

    #[ORM\Column]
    private int $credit;

    #[ORM\Column(length: 40)]
    private string $origine;

    /** Commande réglée (lettrage du compte client). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Paiement d'origine (reprise des paiements avec le solde). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(string $piece, \DateTimeImmutable $date, string $compte, ?string $tiers, string $libelle, int $debit, int $credit, string $origine)
    {
        $this->piece = $piece;
        $this->date = $date;
        $this->compte = $compte;
        $this->tiers = null !== $tiers ? mb_substr($tiers, 0, 150) : null;
        $this->libelle = mb_substr($libelle, 0, 200);
        $this->debit = max(0, $debit);
        $this->credit = max(0, $credit);
        $this->origine = $origine;
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPiece(): string { return $this->piece; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function getCompte(): string { return $this->compte; }
    public function getTiers(): ?string { return $this->tiers; }
    public function getLibelle(): string { return $this->libelle; }
    public function getDebit(): int { return $this->debit; }
    public function getCredit(): int { return $this->credit; }
    public function getOrigine(): string { return $this->origine; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }

    public function rattacher(?Commandes $commande, ?Paiements $paiement): static
    {
        $this->commande = $commande;
        $this->paiement = $paiement;

        return $this;
    }
}
