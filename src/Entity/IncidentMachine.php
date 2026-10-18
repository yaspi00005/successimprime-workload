<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Panne ou incident d'une machine (maquette AT04). Une panne arrête la
 * machine : elle passe « En panne » dans la vue en direct, le planning et
 * la file. Un incident « surveillé » laisse la machine tourner.
 * À ne pas confondre avec les alertes système (pannes du logiciel).
 */
#[ORM\Entity]
#[ORM\Table(name: 'incident_machine')]
#[ORM\Index(name: 'idx_incident_machine_etat', columns: ['etat'])]
class IncidentMachine
{
    public const OUVERT = 'ouvert';
    public const EN_REPARATION = 'en_reparation';
    public const SURVEILLE = 'surveille';
    public const RESOLU = 'resolu';

    public const ETATS = [
        self::OUVERT => 'Signalé',
        self::EN_REPARATION => 'En réparation',
        self::SURVEILLE => 'Surveillé',
        self::RESOLU => 'Résolu',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machines $machine;

    #[ORM\Column(length: 160)]
    private string $titre;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column(length: 20)]
    private string $etat = self::OUVERT;

    /** La machine est arrêtée (panne) : elle ne prend plus de travail. */
    #[ORM\Column]
    private bool $arret = true;

    #[ORM\Column]
    private \DateTimeImmutable $signaleLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $signalePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OrdreProduction $ordre = null;

    /** Suite prévue : « technicien mardi 20/10 », « test des buses à 12:00 ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $suite = null;

    /** Devis de réparation en francs : montré seulement avec le droit Marges et coûts. */
    #[ORM\Column(nullable: true)]
    private ?int $devis = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resoluLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $resoluPar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $noteResolution = null;

    public function __construct(Machines $machine, string $titre, bool $arret, \DateTimeImmutable $signaleLe, ?User $signalePar)
    {
        $this->machine = $machine;
        $this->titre = mb_substr(trim($titre), 0, 160);
        $this->arret = $arret;
        $this->etat = $arret ? self::OUVERT : self::SURVEILLE;
        $this->signaleLe = $signaleLe;
        $this->signalePar = $signalePar;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachine(): Machines
    {
        return $this->machine;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): static
    {
        $detail = null !== $detail ? trim($detail) : null;
        $this->detail = '' === $detail ? null : mb_substr((string) $detail, 0, 2000);

        return $this;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function getEtatLibelle(): string
    {
        return self::ETATS[$this->etat] ?? $this->etat;
    }

    public function changerEtat(string $etat): static
    {
        if (!isset(self::ETATS[$etat]) || self::RESOLU === $etat) {
            throw new \InvalidArgumentException('État inconnu.');
        }
        $this->etat = $etat;
        // « Surveillé » : la machine tourne de nouveau.
        $this->arret = self::SURVEILLE !== $etat;

        return $this;
    }

    public function estArret(): bool
    {
        return $this->arret && !$this->estResolu();
    }

    public function estResolu(): bool
    {
        return self::RESOLU === $this->etat;
    }

    public function getSignaleLe(): \DateTimeImmutable
    {
        return $this->signaleLe;
    }

    public function getSignalePar(): ?User
    {
        return $this->signalePar;
    }

    public function getOrdre(): ?OrdreProduction
    {
        return $this->ordre;
    }

    public function setOrdre(?OrdreProduction $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getSuite(): ?string
    {
        return $this->suite;
    }

    public function setSuite(?string $suite): static
    {
        $suite = null !== $suite ? trim($suite) : null;
        $this->suite = '' === $suite ? null : mb_substr((string) $suite, 0, 255);

        return $this;
    }

    public function getDevis(): ?int
    {
        return $this->devis;
    }

    public function setDevis(?int $devis): static
    {
        $this->devis = null !== $devis && $devis > 0 ? $devis : null;

        return $this;
    }

    public function resoudre(User $par, \DateTimeImmutable $le, ?string $note): static
    {
        $this->etat = self::RESOLU;
        $this->resoluLe = $le;
        $this->resoluPar = $par;
        $note = null !== $note ? trim($note) : null;
        $this->noteResolution = '' === $note ? null : mb_substr((string) $note, 0, 255);

        return $this;
    }

    public function getResoluLe(): ?\DateTimeImmutable
    {
        return $this->resoluLe;
    }

    public function getResoluPar(): ?User
    {
        return $this->resoluPar;
    }

    public function getNoteResolution(): ?string
    {
        return $this->noteResolution;
    }
}
