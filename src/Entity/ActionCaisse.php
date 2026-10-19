<?php

namespace App\Entity;

use App\Repository\ActionCaisseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne d'action pour la caisse (docs/actions-caisse.md) : relancer une
 * facture, rappeler un client… Faite dans le délai (heures de travail) :
 * points gagnés ; au-delà : pénalité appliquée une fois, la ligne reste à
 * faire.
 */
#[ORM\Entity(repositoryClass: ActionCaisseRepository::class)]
#[ORM\Table(name: 'action_caisse')]
#[ORM\Index(name: 'idx_action_caisse_cause', columns: ['cause'])]
#[ORM\Index(name: 'idx_action_caisse_etat', columns: ['etat', 'echeance'])]
class ActionCaisse
{
    public const A_FAIRE = 'a_faire';
    public const FAITE = 'faite';
    public const SANS_OBJET = 'sans_objet';

    /** Type => [libellé, lettre de la pastille]. */
    public const TYPES = [
        'facture' => ['Relance facture', 'F'],
        'verifier' => ['Vérifier le paiement', 'F'],
        'devis' => ['Relance devis', 'D'],
        'commande' => ['Appel client', '☎'],
        'prospect' => ['Prospection', 'P'],
        'inactif' => ['Client inactif', 'i'],
        'avis' => ['Avis client', '★'],
        'manuelle' => ['Action', 'M'],
        // Dossiers clients (docs/dossiers-clients.md, DS03) : fichier déposé dans « À classer » et pas classé depuis 24 h.
        'classer' => ['Fichier à classer', 'C'],
    ];

    public const RESULTATS = [
        'promesse' => 'Promesse de paiement',
        'paye' => 'Payé',
        'accord' => 'Accord · commande',
        'pas_de_reponse' => 'Pas de réponse',
        'a_rappeler' => 'À rappeler',
        'litige' => 'Litige',
        'refus' => 'Refus',
        'fait' => 'Fait',
    ];

    /** Résultats qui n'ont de sens que pour un paiement attendu. */
    public const RESULTATS_PAIEMENT = ['promesse', 'paye'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $type;

    /** Ce qui a créé la ligne (« facture:12 ») : une seule ligne ouverte par cause. */
    #[ORM\Column(length: 60)]
    private string $cause;

    #[ORM\Column(length: 160)]
    private string $libelle;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detail = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Prospect $prospect = null;

    /** Pièce liée : facture, devis, commande ou bon de livraison. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cibleType = null;

    #[ORM\Column(nullable: true)]
    private ?int $cibleId = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    /** Montant dû au moment de la création (relances de facture). */
    #[ORM\Column(nullable: true)]
    private ?int $montant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $assigneA = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Départ du délai : la création, ou la réattribution. */
    #[ORM\Column]
    private \DateTimeImmutable $departLe;

    #[ORM\Column]
    private \DateTimeImmutable $echeance;

    #[ORM\Column(length: 12)]
    private string $etat = self::A_FAIRE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faiteLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $faitePar = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $promesseLe = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $promesseMoyen = null;

    /** Heure où « Appel » ou « WhatsApp » a été touché dans l'application. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contactLe = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $contactCanal = null;

    #[ORM\Column]
    private int $points = 0;

    /** Points retirés (positif), appliqués une seule fois. */
    #[ORM\Column]
    private int $penalite = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $controleLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $controlePar = null;

    #[ORM\Column]
    private bool $pointsAnnules = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifControle = null;

    public function __construct(string $type, string $cause, string $libelle, \DateTimeImmutable $creeLe, \DateTimeImmutable $echeance)
    {
        $this->type = isset(self::TYPES[$type]) ? $type : 'manuelle';
        $this->cause = mb_substr($cause, 0, 60);
        $this->libelle = mb_substr($libelle, 0, 160);
        $this->creeLe = $creeLe;
        $this->departLe = $creeLe;
        $this->echeance = $echeance;
    }

    public function faire(string $resultat, ?string $note, ?User $par, \DateTimeImmutable $maintenant, int $pointsATemps): void
    {
        $this->etat = self::FAITE;
        $this->resultat = $resultat;
        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->faitePar = $par;
        $this->faiteLe = $maintenant;
        $this->points = $maintenant <= $this->echeance ? $pointsATemps : 0;
    }

    public function promettre(\DateTimeImmutable $le, ?string $moyen): void
    {
        $this->promesseLe = $le;
        $this->promesseMoyen = $moyen;
    }

    /** Pénalité de retard, une seule fois. */
    public function penaliser(int $points): bool
    {
        if ($this->etat !== self::A_FAIRE || $this->penalite > 0 || $points <= 0) {
            return false;
        }
        $this->penalite = $points;

        return true;
    }

    /** La cause a disparu (facture payée, devis accepté) : ni points ni pénalité. */
    public function sansObjet(\DateTimeImmutable $maintenant): void
    {
        $this->etat = self::SANS_OBJET;
        $this->faiteLe = $maintenant;
        if ($this->penalite > 0 && $maintenant <= $this->echeance) {
            $this->penalite = 0;
        }
    }

    public function attribuer(?User $a, \DateTimeImmutable $depart, \DateTimeImmutable $echeance): void
    {
        $this->assigneA = $a;
        $this->departLe = $depart;
        $this->echeance = $echeance;
    }

    public function noterContact(string $canal, \DateTimeImmutable $le): void
    {
        $this->contactCanal = $canal === 'whatsapp' ? 'whatsapp' : 'appel';
        $this->contactLe = $le;
    }

    public function controler(User $par, \DateTimeImmutable $le, bool $annuler, ?string $motif): void
    {
        $this->controlePar = $par;
        $this->controleLe = $le;
        $this->pointsAnnules = $annuler;
        $this->motifControle = $motif;
    }

    public function estEnRetard(\DateTimeImmutable $maintenant): bool
    {
        return $this->etat === self::A_FAIRE && $maintenant > $this->echeance;
    }

    /** Points de la ligne : gagnés (sauf annulés au contrôle) moins la pénalité. */
    public function total(): int
    {
        return ($this->pointsAnnules ? 0 : $this->points) - $this->penalite;
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type][0];
    }

    public function lettre(): string
    {
        return self::TYPES[$this->type][1];
    }

    public function libelleResultat(): ?string
    {
        return $this->resultat !== null ? (self::RESULTATS[$this->resultat] ?? $this->resultat) : null;
    }

    /** Preuve affichée au journal (AC04). */
    public function preuve(): string
    {
        return match (true) {
            $this->etat !== self::FAITE => '—',
            $this->contactCanal === 'appel' => 'appel + résultat',
            $this->contactCanal === 'whatsapp' => 'WhatsApp + résultat',
            default => 'résultat seul',
        };
    }

    public function attendPaiement(): bool
    {
        return \in_array($this->type, ['facture', 'verifier'], true);
    }

    public function setDetail(?string $detail): static
    {
        $this->detail = $detail !== null ? mb_substr($detail, 0, 255) : null;

        return $this;
    }

    public function lier(?Clients $client, ?Prospect $prospect = null, ?string $cibleType = null, ?int $cibleId = null): static
    {
        $this->client = $client;
        $this->prospect = $prospect;
        $this->cibleType = $cibleType;
        $this->cibleId = $cibleId;
        $telephone = trim((string) ($client?->getTelephone() ?? $prospect?->getTelephone()));
        $this->telephone = $telephone !== '' ? mb_substr($telephone, 0, 30) : null;

        return $this;
    }

    public function setMontant(?int $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCause(): string
    {
        return $this->cause;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function getCibleType(): ?string
    {
        return $this->cibleType;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function getAssigneA(): ?User
    {
        return $this->assigneA;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDepartLe(): \DateTimeImmutable
    {
        return $this->departLe;
    }

    public function getEcheance(): \DateTimeImmutable
    {
        return $this->echeance;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function getFaiteLe(): ?\DateTimeImmutable
    {
        return $this->faiteLe;
    }

    public function getFaitePar(): ?User
    {
        return $this->faitePar;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getPromesseLe(): ?\DateTimeImmutable
    {
        return $this->promesseLe;
    }

    public function getPromesseMoyen(): ?string
    {
        return $this->promesseMoyen;
    }

    public function getContactLe(): ?\DateTimeImmutable
    {
        return $this->contactLe;
    }

    public function getContactCanal(): ?string
    {
        return $this->contactCanal;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function getPenalite(): int
    {
        return $this->penalite;
    }

    public function getControleLe(): ?\DateTimeImmutable
    {
        return $this->controleLe;
    }

    public function getControlePar(): ?User
    {
        return $this->controlePar;
    }

    public function isPointsAnnules(): bool
    {
        return $this->pointsAnnules;
    }

    public function getMotifControle(): ?string
    {
        return $this->motifControle;
    }
}
