<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Tâche d'entretien préventif d'une machine (maquette AT04) : « Nettoyage
 * des têtes, chaque jour », « Changement des filtres, tous les 3 mois »,
 * « Lame, toutes les 50 h ». Chaque passage noté ajoute une intervention
 * préventive à l'historique de la machine (Maintenance).
 */
#[ORM\Entity]
#[ORM\Table(name: 'entretien_machine')]
class EntretienMachine
{
    public const JOUR = 'jour';
    public const SEMAINE = 'semaine';
    public const MOIS = 'mois';
    public const HEURES = 'heures';
    public const M2 = 'm2';

    public const FREQUENCES = [
        self::JOUR => 'jour',
        self::SEMAINE => 'semaine',
        self::MOIS => 'mois',
        self::HEURES => 'heures de production',
        self::M2 => 'm² imprimés',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machines $machine;

    #[ORM\Column(length: 120)]
    private string $tache;

    #[ORM\Column(length: 10)]
    private string $frequence;

    #[ORM\Column]
    private int $intervalle = 1;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $dernierePar = null;

    #[ORM\Column]
    private bool $actif = true;

    public function __construct(Machines $machine, string $tache, string $frequence, int $intervalle = 1)
    {
        if (!isset(self::FREQUENCES[$frequence])) {
            throw new \InvalidArgumentException('Fréquence inconnue.');
        }
        $this->machine = $machine;
        $this->tache = mb_substr(trim($tache), 0, 120);
        $this->frequence = $frequence;
        $this->intervalle = max(1, $intervalle);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachine(): Machines
    {
        return $this->machine;
    }

    public function getTache(): string
    {
        return $this->tache;
    }

    public function getFrequence(): string
    {
        return $this->frequence;
    }

    public function getIntervalle(): int
    {
        return $this->intervalle;
    }

    /** « chaque jour », « tous les 3 mois », « toutes les 50 h ». */
    public function getFrequenceLibelle(): string
    {
        $n = $this->intervalle;

        return match ($this->frequence) {
            self::JOUR => 1 === $n ? 'chaque jour' : "tous les $n jours",
            self::SEMAINE => 1 === $n ? 'chaque semaine' : "toutes les $n semaines",
            self::MOIS => 1 === $n ? 'chaque mois' : "tous les $n mois",
            self::HEURES => "toutes les $n h",
            default => 'tous les '.number_format($n, 0, ',', ' ').' m²',
        };
    }

    public function getDerniereLe(): ?\DateTimeImmutable
    {
        return $this->derniereLe;
    }

    public function getDernierePar(): ?User
    {
        return $this->dernierePar;
    }

    public function noterFait(User $par, \DateTimeImmutable $le): static
    {
        $this->derniereLe = $le;
        $this->dernierePar = $par;

        return $this;
    }

    public function estActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }
}
