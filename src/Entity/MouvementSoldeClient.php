<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relevé du compte d'un client à l'imprimerie (docs/finances.md, « Compte
 * du client ») : chaque entrée ou sortie de son solde (Clients::soldeCredit),
 * avec la date, le montant, le solde après, qui l'a fait et la référence
 * (reçu, commande, transaction Orange Money).
 *
 * Le solde ne bouge que par App\Service\Paiement\SoldeClient, qui écrit
 * la ligne en même temps : la somme des lignes d'un client est son solde.
 * Une ligne n'est jamais modifiée ; une erreur se corrige par une autre
 * ligne (paiement annulé : « retour » au compte).
 *
 * Écrit au local seulement ; il descend au site en lecture seule (relevé
 * « Mon solde » de l'espace client, payeur seulement). Le motif, l'employé
 * et le mouvement de trésorerie restent au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mouvement_solde_client')]
#[ORM\Index(name: 'idx_mouvement_solde_client_date', columns: ['client_id', 'cree_le'])]
class MouvementSoldeClient
{
    use PublicIdTrait;

    /** Monnaie que le client a laissée à la caisse au lieu de la reprendre. */
    public const MONNAIE = 'monnaie';
    /** Argent déposé pour plus tard (espèces ou Orange Money à l'imprimerie, Orange Money depuis l'espace client). */
    public const DEPOT = 'depot';
    /** Solde utilisé pour payer une commande ou une facture. */
    public const UTILISATION = 'utilisation';
    /** Argent rendu au client (sortie de caisse). */
    public const REMBOURSEMENT = 'remboursement';
    /** Paiement par le solde annulé : le montant revient au compte. */
    public const RETOUR = 'retour';
    /** Avance reprise par l'import des soldes d'ouverture (négative si l'import est annulé). */
    public const OUVERTURE = 'ouverture';
    /** Solde déjà sur la fiche avant le relevé (reprise de la migration). */
    public const REPRISE = 'reprise';

    public const TYPES = [
        self::MONNAIE => 'Monnaie gardée',
        self::DEPOT => 'Dépôt',
        self::UTILISATION => 'Paiement avec le solde',
        self::REMBOURSEMENT => 'Argent rendu',
        self::RETOUR => 'Paiement annulé, remis au solde',
        self::OUVERTURE => 'Solde d\'ouverture',
        self::REPRISE => 'Solde déjà sur la fiche',
    ];

    public const MODES = [
        Paiements::MODE_ESPECES => 'Espèces',
        Paiements::MODE_ORANGE_MONEY => 'Orange Money',
        Paiements::MODE_WAVE => 'Wave',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 14)]
    private string $type = self::DEPOT;

    /** En F : positif quand le solde monte, négatif quand il baisse. */
    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column]
    private int $soldeApres = 0;

    /** Espèces, Orange Money, Wave (entrées et sorties d'argent) ; vide pour une utilisation. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mode = null;

    /** Reçu (R-000123), dépôt (D-000045), remboursement (RB-000012), commande, transaction Orange Money. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $reference = null;

    /** Note interne (motif d'un remboursement, d'une annulation) : reste au local. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    /** Entrée ou sortie d'argent de la caisse (catégorie « avance client ») ; vide pour une utilisation. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MouvementTresorerie $mouvementTresorerie = null;

    /** Dépôt Orange Money fait depuis l'espace client, validé à l'imprimerie. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DepotSoldeClient $depot = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par = null;

    /** Nom de l'employé, gardé si son compte est supprimé (ou « import », « migration »). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $parNom = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouveau(Clients $client, string $type, int $montant, int $soldeApres, ?User $par, \DateTimeImmutable $le, ?string $parNom = null): self
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Type de mouvement de solde inconnu.');
        }
        $m = new self();
        $m->client = $client;
        $m->type = $type;
        $m->montant = $montant;
        $m->soldeApres = $soldeApres;
        $m->par = $par;
        $m->parNom = null !== ($parNom ?? $par?->getUserIdentifier()) ? mb_substr((string) ($parNom ?? $par?->getUserIdentifier()), 0, 80) : null;
        $m->creeLe = $le;

        return $m;
    }

    /** Ce que le client lit dans son relevé : le type, et la référence quand il y en a une. */
    public function getLibelle(): string
    {
        $libelle = match (true) {
            self::DEPOT === $this->type && null !== $this->mode => 'Dépôt '.(Paiements::MODE_ESPECES === $this->mode ? 'en espèces' : (self::MODES[$this->mode] ?? $this->mode)),
            self::UTILISATION === $this->type && null !== $this->commande => 'Paiement de la commande '.$this->commande->getNumero(),
            self::OUVERTURE === $this->type && $this->montant < 0 => 'Solde d\'ouverture annulé',
            default => self::TYPES[$this->type] ?? $this->type,
        };

        return $libelle;
    }

    public function estCredit(): bool { return $this->montant > 0; }
    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getType(): string { return $this->type; }
    public function getLibelleType(): string { return self::TYPES[$this->type] ?? $this->type; }
    public function getMontant(): int { return $this->montant; }
    public function getSoldeApres(): int { return $this->soldeApres; }
    public function getMode(): ?string { return $this->mode; }
    public function getModeLabel(): ?string { return null !== $this->mode ? (self::MODES[$this->mode] ?? $this->mode) : null; }
    public function setMode(?string $mode): static { $this->mode = $mode; return $this; }
    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = null !== $reference && '' !== trim($reference) ? mb_substr(trim($reference), 0, 60) : null; return $this; }
    public function getMotif(): ?string { return $this->motif; }
    public function setMotif(?string $motif): static { $this->motif = null !== $motif && '' !== trim($motif) ? mb_substr(trim($motif), 0, 255) : null; return $this; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $commande): static { $this->commande = $commande; return $this; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
    public function setPaiement(?Paiements $paiement): static { $this->paiement = $paiement; return $this; }
    public function getMouvementTresorerie(): ?MouvementTresorerie { return $this->mouvementTresorerie; }
    public function setMouvementTresorerie(?MouvementTresorerie $mouvement): static { $this->mouvementTresorerie = $mouvement; return $this; }
    public function getDepot(): ?DepotSoldeClient { return $this->depot; }
    public function setDepot(?DepotSoldeClient $depot): static { $this->depot = $depot; return $this; }
    public function getPar(): ?User { return $this->par; }
    public function getParNom(): ?string { return $this->parNom; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
}
