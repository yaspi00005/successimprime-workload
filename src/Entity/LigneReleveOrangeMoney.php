<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Une transaction reçue sur le compte Orange Money marchand, importée du
 * relevé (Finances › Encaissements, maquette F02). Elle sert de preuve :
 * - « rapprochée » : un paiement déjà saisi porte la même référence ;
 * - « associée » : une personne l'a associée à une commande, ce qui a
 *   créé un paiement en attente, validé ensuite dans À valider ;
 * - « ignorée » : ce n'est pas un paiement client (direction).
 * Rien n'est validé automatiquement. Table locale, non synchronisée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ligne_releve_orange_money')]
#[ORM\UniqueConstraint(name: 'uniq_releve_om_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_releve_om_recu_le', columns: ['recu_le'])]
class LigneReleveOrangeMoney
{
    public const A_ASSOCIER = 'a_associer';
    public const RAPPROCHEE = 'rapprochee';
    public const ASSOCIEE = 'associee';
    public const IGNOREE = 'ignoree';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence de la transaction chez Orange Money (MP…). */
    #[ORM\Column(length: 60)]
    private string $reference;

    #[ORM\Column]
    private \DateTimeImmutable $recuLe;

    #[ORM\Column]
    private int $montant;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $expediteur = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nomExpediteur = null;

    /** Message ou motif saisi par le client, s'il y en a un. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $libelle = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::A_ASSOCIER;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    /** Score de la suggestion retenue, pour suivre la qualité des propositions. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifIgnoree = null;

    #[ORM\Column]
    private \DateTimeImmutable $importeeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $importeePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traiteePar = null;

    public function __construct(string $reference, \DateTimeImmutable $recuLe, int $montant, ?User $importeePar, \DateTimeImmutable $importeeLe)
    {
        $this->reference = $reference;
        $this->recuLe = $recuLe;
        $this->montant = $montant;
        $this->importeePar = $importeePar;
        $this->importeeLe = $importeeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getRecuLe(): \DateTimeImmutable { return $this->recuLe; }
    public function getMontant(): int { return $this->montant; }
    public function getExpediteur(): ?string { return $this->expediteur; }
    public function getNomExpediteur(): ?string { return $this->nomExpediteur; }
    public function getLibelle(): ?string { return $this->libelle; }
    public function getStatut(): string { return $this->statut; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
    public function getScore(): ?int { return $this->score; }
    public function getMotifIgnoree(): ?string { return $this->motifIgnoree; }
    public function getImporteeLe(): \DateTimeImmutable { return $this->importeeLe; }
    public function getImporteePar(): ?User { return $this->importeePar; }
    public function getTraiteeLe(): ?\DateTimeImmutable { return $this->traiteeLe; }
    public function getTraiteePar(): ?User { return $this->traiteePar; }

    public function decrire(?string $expediteur, ?string $nom, ?string $libelle): static
    {
        $this->expediteur = null === $expediteur || '' === trim($expediteur) ? null : mb_substr(trim($expediteur), 0, 30);
        $this->nomExpediteur = null === $nom || '' === trim($nom) ? null : mb_substr(trim($nom), 0, 120);
        $this->libelle = null === $libelle || '' === trim($libelle) ? null : mb_substr(trim($libelle), 0, 255);

        return $this;
    }

    /**
     * Reste à associer : jamais traitée, ou son paiement a été rejeté ou
     * annulé dans À valider (l'argent reste à rattacher).
     */
    public function estAAssocier(): bool
    {
        return match ($this->statut) {
            self::A_ASSOCIER => true,
            self::ASSOCIEE, self::RAPPROCHEE => null === $this->paiement || \in_array($this->paiement->getStatut(), [Paiements::STATUT_REJETE, Paiements::STATUT_ANNULE], true),
            default => false,
        };
    }

    /** Même référence qu'un paiement déjà saisi : rien n'est créé ni validé. */
    public function rapprocher(Paiements $paiement, \DateTimeImmutable $le): static
    {
        $this->statut = self::RAPPROCHEE;
        $this->paiement = $paiement;
        $this->traiteeLe = $le;
        $this->traiteePar = null;

        return $this;
    }

    /** Associée par une personne, à un paiement nouveau ou déjà saisi. */
    public function associer(Paiements $paiement, ?int $score, User $par, \DateTimeImmutable $le): static
    {
        $this->statut = self::ASSOCIEE;
        $this->paiement = $paiement;
        $this->score = $score;
        $this->traiteeLe = $le;
        $this->traiteePar = $par;

        return $this;
    }

    public function ignorer(string $motif, User $par, \DateTimeImmutable $le): static
    {
        $this->statut = self::IGNOREE;
        $this->motifIgnoree = mb_substr(trim($motif), 0, 255);
        $this->traiteeLe = $le;
        $this->traiteePar = $par;

        return $this;
    }
}
