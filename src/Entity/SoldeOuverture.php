<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Solde d'ouverture repris au démarrage (docs/import-donnees.md) : ce que
 * le client doit, l'avance qu'il a chez nous, l'argent d'un compte de
 * trésorerie, ce que l'imprimerie doit à un fournisseur.
 *
 * Ce n'est jamais une vente ni une dépense du mois : la ligne dit où le
 * montant a été posé (commande « Solde d'ouverture » sans ligne ni
 * facture, solde client, solde de départ du compte, dépense à payer
 * marquée solde d'ouverture) et sert aux à-nouveaux de la comptabilité.
 *
 * Table locale, non synchronisée avec le site en ligne.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_solde_ouverture_date', columns: ['date_ouverture'])]
class SoldeOuverture
{
    /** Le client nous doit ce montant. */
    public const CLIENT = 'client';
    /** Le client a cette avance chez nous (solde client). */
    public const AVANCE_CLIENT = 'avance_client';
    /** Argent d'un compte de trésorerie au démarrage. */
    public const COMPTE = 'compte';
    /** L'imprimerie doit ce montant au fournisseur. */
    public const FOURNISSEUR = 'fournisseur';

    public const TYPES = [
        self::CLIENT => 'Dû par le client',
        self::AVANCE_CLIENT => 'Avance du client',
        self::COMPTE => 'Compte de trésorerie',
        self::FOURNISSEUR => 'Dû au fournisseur',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportDonnees $import = null;

    #[ORM\Column(length: 20)]
    private string $type;

    /** Toujours positif ; le type dit dans quel sens. */
    #[ORM\Column]
    private int $montant;

    /** Jour des soldes : date des à-nouveaux. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateOuverture;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** Client, compte ou fournisseur, en clair (gardé si la fiche disparaît). */
    #[ORM\Column(length: 150)]
    private string $tiers = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    /** Commande « Solde d'ouverture » qui porte ce que le client doit. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteTresorerie $compte = null;

    /** Solde de départ du compte avant l'import, remis en cas d'annulation. */
    #[ORM\Column(nullable: true)]
    private ?int $ancienSoldeInitial = null;

    /** Dépense « à payer » qui porte la dette fournisseur. */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Depense $depense = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    /** Import annulé : la ligne reste pour l'historique, sans effet. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    public function __construct(string $type, int $montant, \DateTimeImmutable $dateOuverture, string $tiers, ?User $par, \DateTimeImmutable $le)
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Type de solde d\'ouverture inconnu.');
        }
        // 0 seulement pour un compte de trésorerie remis à zéro (pas d'écriture).
        if ($montant < 0 || (0 === $montant && self::COMPTE !== $type)) {
            throw new \InvalidArgumentException('Un solde d\'ouverture est un montant positif.');
        }
        $this->type = $type;
        $this->montant = $montant;
        $this->dateOuverture = $dateOuverture->setTime(0, 0);
        $this->tiers = mb_substr(trim($tiers), 0, 150);
        $this->creePar = $par;
        $this->creeLe = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getImport(): ?ImportDonnees { return $this->import; }
    public function setImport(?ImportDonnees $i): static { $this->import = $i; return $this; }
    public function getType(): string { return $this->type; }
    public function getTypeLibelle(): string { return self::TYPES[$this->type]; }
    public function getMontant(): int { return $this->montant; }
    public function getDateOuverture(): \DateTimeImmutable { return $this->dateOuverture; }
    public function getEcheance(): ?\DateTimeImmutable { return $this->echeance; }
    public function setEcheance(?\DateTimeImmutable $e): static { $this->echeance = $e?->setTime(0, 0); return $this; }
    public function getTiers(): string { return $this->tiers; }
    public function getClient(): ?Clients { return $this->client; }
    public function setClient(?Clients $c): static { $this->client = $c; return $this; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $c): static { $this->commande = $c; return $this; }
    public function getCompte(): ?CompteTresorerie { return $this->compte; }
    public function setCompte(?CompteTresorerie $c): static { $this->compte = $c; return $this; }
    public function getAncienSoldeInitial(): ?int { return $this->ancienSoldeInitial; }
    public function setAncienSoldeInitial(?int $s): static { $this->ancienSoldeInitial = $s; return $this; }
    public function getDepense(): ?Depense { return $this->depense; }
    public function setDepense(?Depense $d): static { $this->depense = $d; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $n): static { $n = trim((string) $n); $this->note = '' === $n ? null : $n; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function getAnnuleLe(): ?\DateTimeImmutable { return $this->annuleLe; }
    public function estAnnule(): bool { return null !== $this->annuleLe; }
    public function annuler(\DateTimeImmutable $le): static { $this->annuleLe = $le; return $this; }
}
