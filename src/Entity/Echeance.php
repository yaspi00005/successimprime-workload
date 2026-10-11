<?php

namespace App\Entity;

use App\Service\Planning\Calendrier;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règle qui crée les tâches du planning (T02) : « Impôts le 25 de chaque
 * mois ». Les occurrences sont créées à l'avance ; modifier la règle met
 * à jour les occurrences futures pas encore commencées.
 */
#[ORM\Entity]
class Echeance
{
    /** Catégorie => [libellé, couleur]. */
    public const CATEGORIES = [
        'fiscal' => ['Fiscal', '#DC2626'],
        'fournisseurs' => ['Fournisseurs', '#EA580C'],
        'paie' => ['Personnel et paie', '#DB2777'],
        'atelier' => ['Atelier', '#1E3A8A'],
        'stock' => ['Stock', '#0EA5E9'],
        'livraisons' => ['Livraisons', '#0D9488'],
        'commercial' => ['Commercial', '#16A34A'],
        'entretien' => ['Entretien', '#7C3AED'],
        'administratif' => ['Administratif', '#64748B'],
    ];

    /** Catégories visibles seulement de la direction, du comptable et du responsable désigné. */
    public const CONFIDENTIELLES = ['fiscal', 'paie'];

    public const RAPPELS = [
        'j5' => ['5 jours avant', 'Notification au responsable (WhatsApp plus tard)'],
        'veille' => ['1 jour avant', 'Notification au responsable'],
        'jour' => ['Le jour même 8 h', 'Notification à la direction'],
        'retard' => ['En retard', 'Alerte rouge sur le tableau de bord'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $titre = '';

    #[ORM\Column(length: 20)]
    private string $categorie = 'administratif';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $responsable = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $copie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entreprise $entreprise = null;

    #[ORM\Column(length: 20)]
    private string $frequence = Calendrier::MOIS;

    /** Jour du mois (1-31), 0 = dernier jour. */
    #[ORM\Column(nullable: true)]
    private ?int $jourMois = null;

    /** @var list<int> 1 = lundi … 7 = dimanche */
    #[ORM\Column(type: Types::JSON)]
    private array $joursSemaine = [];

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heure = null;

    /** Fin du créneau (tâches d'entretien : « 13:00 – 13:30 »). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heureFin = null;

    /** Checklist de fermeture (points du barème Entretien). */
    #[ORM\Column]
    private bool $fermeture = false;

    #[ORM\Column(length: 10)]
    private string $decalage = Calendrier::AVANT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finLe = null;

    #[ORM\Column(nullable: true)]
    private ?int $finNombre = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $etapes = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $rappels = ['j5' => true, 'veille' => true, 'jour' => true, 'retard' => true];

    #[ORM\Column(nullable: true)]
    private ?int $montantEstime = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    public function __construct(?User $par = null, ?\DateTimeImmutable $le = null)
    {
        $this->creeLe = $le ?? new \DateTimeImmutable();
        $this->debut = $this->creeLe->setTime(0, 0);
        $this->creePar = $par;
    }

    /** Règle passée au calendrier. @return array<string, mixed> */
    public function regle(): array
    {
        return [
            'frequence' => $this->frequence,
            'jourMois' => $this->jourMois ?? (int) $this->debut->format('j'),
            'joursSemaine' => $this->joursSemaine,
            'debut' => $this->debut->format('Y-m-d'),
            'decalage' => $this->decalage,
            'finLe' => $this->finLe?->format('Y-m-d'),
            'finNombre' => $this->finNombre,
        ];
    }

    public function rappel(string $cle): bool { return (bool) ($this->rappels[$cle] ?? false); }

    public function getId(): ?int { return $this->id; }
    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $titre): static { $this->titre = mb_substr(trim($titre), 0, 150); return $this; }
    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $c): static { $this->categorie = isset(self::CATEGORIES[$c]) ? $c : 'administratif'; return $this; }
    public function getResponsable(): ?User { return $this->responsable; }
    public function setResponsable(?User $u): static { $this->responsable = $u; return $this; }
    public function getCopie(): ?User { return $this->copie; }
    public function setCopie(?User $u): static { $this->copie = $u; return $this; }
    public function getEntreprise(): ?Entreprise { return $this->entreprise; }
    public function setEntreprise(?Entreprise $e): static { $this->entreprise = $e; return $this; }
    public function getFrequence(): string { return $this->frequence; }
    public function setFrequence(string $f): static { $this->frequence = isset(Calendrier::FREQUENCES[$f]) ? $f : Calendrier::UNE_FOIS; return $this; }
    public function getJourMois(): ?int { return $this->jourMois; }
    public function setJourMois(?int $j): static { $this->jourMois = $j === null ? null : max(0, min(31, $j)); return $this; }
    /** @return list<int> */
    public function getJoursSemaine(): array { return $this->joursSemaine; }
    /** @param list<int|string> $jours */
    public function setJoursSemaine(array $jours): static { $this->joursSemaine = array_values(array_unique(array_filter(array_map('intval', $jours), static fn (int $j): bool => $j >= 1 && $j <= 7))); sort($this->joursSemaine); return $this; }
    public function getHeure(): ?string { return $this->heure; }
    public function setHeure(?string $h): static { $this->heure = $h !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : null; return $this; }
    public function getHeureFin(): ?string { return $this->heureFin; }
    public function setHeureFin(?string $h): static { $this->heureFin = $h !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : null; return $this; }
    public function isFermeture(): bool { return $this->fermeture; }
    public function setFermeture(bool $f): static { $this->fermeture = $f; return $this; }
    public function getDecalage(): string { return $this->decalage; }
    public function setDecalage(string $d): static { $this->decalage = isset(Calendrier::DECALAGES[$d]) ? $d : Calendrier::AVANT; return $this; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function setDebut(\DateTimeImmutable $d): static { $this->debut = $d->setTime(0, 0); return $this; }
    public function getFinLe(): ?\DateTimeImmutable { return $this->finLe; }
    public function setFinLe(?\DateTimeImmutable $d): static { $this->finLe = $d?->setTime(0, 0); return $this; }
    public function getFinNombre(): ?int { return $this->finNombre; }
    public function setFinNombre(?int $n): static { $this->finNombre = $n !== null && $n > 0 ? $n : null; return $this; }
    /** @return list<string> */
    public function getEtapes(): array { return $this->etapes; }
    /** @param list<string> $etapes */
    public function setEtapes(array $etapes): static { $this->etapes = array_values(array_slice(array_filter(array_map(static fn ($e): string => mb_substr(trim((string) $e), 0, 150), $etapes)), 0, 20)); return $this; }
    /** @return array<string, bool> */
    public function getRappels(): array { return $this->rappels; }
    /** @param array<string, mixed> $rappels */
    public function setRappels(array $rappels): static { $this->rappels = array_map(static fn (string $k): bool => !empty($rappels[$k]), array_combine(array_keys(self::RAPPELS), array_keys(self::RAPPELS))); return $this; }
    public function getMontantEstime(): ?int { return $this->montantEstime; }
    public function setMontantEstime(?int $m): static { $this->montantEstime = $m !== null && $m > 0 ? $m : null; return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $a): static { $this->actif = $a; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCreePar(): ?User { return $this->creePar; }
}
