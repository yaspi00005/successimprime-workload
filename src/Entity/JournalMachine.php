<?php

namespace App\Entity;

use App\Repository\JournalMachineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que le machiniste signale sur sa machine (maquette W03) : entretien
 * des têtes, gâche, incident ou panne, réimpression due à l'opérateur.
 * Sert aux points du barème « Opérateur production ».
 */
#[ORM\Entity(repositoryClass: JournalMachineRepository::class)]
#[ORM\Table(name: 'journal_machine')]
#[ORM\Index(name: 'idx_journal_machine_le', columns: ['le'])]
class JournalMachine
{
    public const TETES = 'tetes';
    public const GACHE = 'gache';
    public const INCIDENT = 'incident';
    public const REIMPRESSION = 'reimpression';

    public const TYPES = [
        self::TETES => 'Entretien des têtes',
        self::GACHE => 'Gâche',
        self::INCIDENT => 'Incident ou panne',
        self::REIMPRESSION => 'Réimpression due à l\'opérateur',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machines $machine;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $operateur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OrdreProduction $ordre = null;

    #[ORM\Column(length: 15)]
    private string $type;

    /** m² pour une gâche ou une réimpression. */
    #[ORM\Column(nullable: true)]
    private ?float $quantite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $le;

    public function __construct(Machines $machine, User $operateur, string $type, \DateTimeImmutable $le, ?OrdreProduction $ordre = null, ?float $quantite = null, ?string $note = null)
    {
        $this->machine = $machine;
        $this->operateur = $operateur;
        $this->type = isset(self::TYPES[$type]) ? $type : self::INCIDENT;
        $this->le = $le;
        $this->ordre = $ordre;
        $this->quantite = $quantite !== null && $quantite > 0 ? round($quantite, 2) : null;
        $this->note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachine(): Machines
    {
        return $this->machine;
    }

    public function getOperateur(): User
    {
        return $this->operateur;
    }

    public function getOrdre(): ?OrdreProduction
    {
        return $this->ordre;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function libelle(): string
    {
        return self::TYPES[$this->type];
    }

    public function getQuantite(): ?float
    {
        return $this->quantite;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getLe(): \DateTimeImmutable
    {
        return $this->le;
    }
}
