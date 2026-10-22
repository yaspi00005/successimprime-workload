<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Avoir sur une facture validée (maquette FD04, règle de Yaya du
 * 19/10/2026) : une facture validée ne se modifie jamais ; toute
 * correction passe par un avoir numéroté (AV-2026-0032), validé par la
 * trésorière ou la direction.
 *
 * Il crédite des lignes de la facture (quantités), avec la part de la
 * remise de la facture, et va au compte du client (Clients::soldeCredit) :
 * déduit tout de suite de cette facture, gardé en crédit pour une
 * prochaine commande, ou rendu au client. Il n'est jamais modifié.
 */
#[ORM\Entity]
#[ORM\Table(name: 'avoir')]
#[ORM\Index(name: 'idx_avoir_cree_le', columns: ['cree_le'])]
class Avoir
{
    use PublicIdTrait;

    public const MOTIFS = [
        'defaut_fabrication' => 'Défaut de fabrication',
        'erreur_prix' => 'Erreur de prix',
        'retour' => 'Retour',
        'geste_commercial' => 'Geste commercial',
        'annulation_totale' => 'Annulation totale',
    ];

    public const DEDUIRE = 'deduire';
    public const CREDIT = 'credit';
    public const REMBOURSER = 'rembourser';

    public const SUITES = [
        self::DEDUIRE => 'Déduit de la facture',
        self::CREDIT => 'Gardé en crédit',
        self::REMBOURSER => 'Remboursé',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30, unique: true)]
    private string $numero = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Factures $facture = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Clients $client = null;

    #[ORM\Column(length: 30)]
    private string $motif = 'defaut_fabrication';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ReclamationClient $reclamation = null;

    /**
     * Lignes créditées, figées à la validation.
     *
     * @var list<array{ligne: ?int, designation: string, quantite: float, facture: float, prixUnitaire: int, montant: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $lignes = [];

    /** Part de la remise de la facture rendue aussi (en moins). */
    #[ORM\Column]
    private int $remise = 0;

    #[ORM\Column]
    private int $total = 0;

    #[ORM\Column(length: 12)]
    private string $suite = self::DEDUIRE;

    /** Ce qui a été déduit de la facture (le reste reste en crédit). */
    #[ORM\Column]
    private int $deduit = 0;

    /** Ce qui a été rendu au client. */
    #[ORM\Column]
    private int $rembourse = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteTresorerie $compteRemboursement = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getFacture(): ?Factures
    {
        return $this->facture;
    }

    public function setFacture(Factures $facture): static
    {
        $this->facture = $facture;
        $this->client = $facture->getCommande()?->getClients() ?? $this->client;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function getMotifLibelle(): string
    {
        return self::MOTIFS[$this->motif] ?? $this->motif;
    }

    public function setMotif(string $motif): static
    {
        if (!isset(self::MOTIFS[$motif])) {
            throw new \DomainException('Choisissez pourquoi l\'avoir est fait.');
        }
        $this->motif = $motif;

        return $this;
    }

    public function getReclamation(): ?ReclamationClient
    {
        return $this->reclamation;
    }

    public function setReclamation(?ReclamationClient $reclamation): static
    {
        $this->reclamation = $reclamation;

        return $this;
    }

    /** @return list<array{ligne: ?int, designation: string, quantite: float, facture: float, prixUnitaire: int, montant: int}> */
    public function getLignes(): array
    {
        return $this->lignes;
    }

    /** @param list<array{ligne: ?int, designation: string, quantite: float, facture: float, prixUnitaire: int, montant: int}> $lignes */
    public function setLignes(array $lignes, int $remise): static
    {
        $this->lignes = $lignes;
        $this->remise = max(0, $remise);
        $this->total = max(0, array_sum(array_column($lignes, 'montant')) - $this->remise);

        return $this;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getSuite(): string
    {
        return $this->suite;
    }

    public function getSuiteLibelle(): string
    {
        return self::SUITES[$this->suite] ?? $this->suite;
    }

    public function setSuite(string $suite): static
    {
        if (!isset(self::SUITES[$suite])) {
            throw new \DomainException('Choisissez ce que devient l\'avoir.');
        }
        $this->suite = $suite;

        return $this;
    }

    public function getDeduit(): int
    {
        return $this->deduit;
    }

    public function setDeduit(int $deduit): static
    {
        $this->deduit = max(0, $deduit);

        return $this;
    }

    public function getRembourse(): int
    {
        return $this->rembourse;
    }

    public function setRembourse(int $rembourse): static
    {
        $this->rembourse = max(0, $rembourse);

        return $this;
    }

    /** Ce qui reste en crédit sur le compte du client. */
    public function getEnCredit(): int
    {
        return max(0, $this->total - $this->deduit - $this->rembourse);
    }

    public function getCompteRemboursement(): ?CompteTresorerie
    {
        return $this->compteRemboursement;
    }

    public function setCompteRemboursement(?CompteTresorerie $compte): static
    {
        $this->compteRemboursement = $compte;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $observation = null !== $observation ? trim($observation) : null;
        $this->observation = '' === $observation ? null : mb_substr((string) $observation, 0, 1000);

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $le): static
    {
        $this->creeLe = $le;

        return $this;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function setValidePar(?User $par): static
    {
        $this->validePar = $par;

        return $this;
    }
}
