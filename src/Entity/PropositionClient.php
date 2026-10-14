<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Changement des informations de l'entreprise demandé en ligne (raison
 * sociale, NIF : Mon compte, maquette EC04) par une personne qui gère le
 * compte. Il n'est jamais appliqué tout seul : il monte au local, où le
 * droit Clients l'applique ou le refuse depuis la fiche ; la décision
 * redescend, et la fiche modifiée avec elle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'proposition_client')]
#[ORM\Index(name: 'idx_proposition_client_statut', columns: ['statut'])]
class PropositionClient
{
    use PublicIdTrait;

    public const EN_ATTENTE = 'en_attente';
    public const APPLIQUEE = 'appliquee';
    public const REFUSEE = 'refusee';

    public const STATUTS = [
        self::EN_ATTENTE => 'En attente',
        self::APPLIQUEE => 'Appliquée',
        self::REFUSEE => 'Refusée',
    ];

    /** Champs de la fiche qui se proposent en ligne, avec leur libellé et leur longueur. */
    public const CHAMPS = [
        'raisonSociale' => ['Raison sociale', 100],
        'nif' => ['NIF', 30],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** @var array<string, array{avant: ?string, apres: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $champs = [];

    /** Personne qui l'a demandée (son nom dans le compte). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(length: 12)]
    private string $statut = self::EN_ATTENTE;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    /** Motif d'un refus, lu par le client. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    /** Employé qui l'a traitée : reste au local. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traitePar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    /** @param array<string, array{avant: ?string, apres: ?string}> $champs */
    public static function nouvelle(Clients $client, array $champs, ?string $auteur, \DateTimeImmutable $le): self
    {
        $p = new self();
        $p->client = $client;
        $p->champs = $champs;
        $p->auteur = null !== $auteur ? mb_substr($auteur, 0, 80) : null;
        $p->creeLe = $le;

        return $p;
    }

    public function traiter(string $statut, ?User $par, ?string $motif, \DateTimeImmutable $le): void
    {
        $this->statut = $statut;
        $this->traitePar = $par;
        $motif = null !== $motif ? mb_substr(trim($motif), 0, 255) : null;
        $this->motif = '' === $motif ? null : $motif;
        $this->traiteLe = $le;
    }

    public function estEnAttente(): bool
    {
        return self::EN_ATTENTE === $this->statut;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    /** @return array<string, array{avant: ?string, apres: ?string}> */
    public function getChamps(): array { return $this->champs; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getStatut(): string { return $this->statut; }
    public function getStatutLibelle(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function getMotif(): ?string { return $this->motif; }
    public function getTraitePar(): ?User { return $this->traitePar; }
}
