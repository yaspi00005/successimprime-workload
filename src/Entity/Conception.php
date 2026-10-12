<?php

namespace App\Entity;

use App\Repository\ConceptionRepository;
use App\Service\SuiviMetiers\Bareme;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Suivi d'un travail de conception PAO (maquettes W01 et W02) : graphiste,
 * difficulté, échéance du BAT, chronomètre, versions envoyées, retours du
 * client, frise horodatée et points du barème. L'étape du tableau suit le
 * contrôle prépresse du même travail.
 */
#[ORM\Entity(repositoryClass: ConceptionRepository::class)]
#[ORM\Table(name: 'conception')]
#[ORM\UniqueConstraint(name: 'uniq_conception_controle', columns: ['controle_id'])]
#[ORM\Index(name: 'idx_conception_valide_le', columns: ['valide_le'])]
class Conception
{
    public const A_FAIRE = 'a_faire';
    public const EN_CONCEPTION = 'en_conception';
    public const BAT_ENVOYE = 'bat_envoye';
    public const CORRECTIONS = 'corrections';
    public const VALIDE = 'valide';

    public const ETAPES = [
        self::A_FAIRE => 'À faire',
        self::EN_CONCEPTION => 'En conception',
        self::BAT_ENVOYE => 'BAT envoyé',
        self::CORRECTIONS => 'Corrections',
        self::VALIDE => 'Validé → production',
    ];

    /** Temps prévu par défaut selon la difficulté (minutes). */
    public const TEMPS_PREVU = ['simple' => 45, 'moyen' => 90, 'complexe' => 180];

    public const RETOUR_CLIENT = 'client';
    public const RETOUR_ERREUR = 'erreur';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ControlePrePresse $controle;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $graphiste = null;

    #[ORM\Column(length: 10)]
    private string $difficulte = 'simple';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $echeanceBat = null;

    #[ORM\Column]
    private int $tempsPrevu = self::TEMPS_PREVU['simple'];

    /** Début de la séance de travail en cours (null : chronomètre arrêté). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demarreLe = null;

    #[ORM\Column]
    private int $secondes = 0;

    #[ORM\Column]
    private int $versions = 0;

    #[ORM\Column]
    private int $erreurs = 0;

    #[ORM\Column]
    private int $changementsClient = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $premierBatLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dernierBatLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\Column(nullable: true)]
    private ?int $points = null;

    #[ORM\Column(nullable: true)]
    private ?int $pointsPossibles = null;

    /** @var array<string, int|float> règle du barème => quantité */
    #[ORM\Column(type: Types::JSON)]
    private array $faits = [];

    /** @var list<array{le: string, type: string, titre: string, detail: ?string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $evenements = [];

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(ControlePrePresse $controle, \DateTimeImmutable $creeLe, ?\DateTimeImmutable $echeanceBat)
    {
        $this->controle = $controle;
        $this->creeLe = $creeLe;
        $this->echeanceBat = $echeanceBat;
        $this->noter($creeLe, 'brief', 'Brief reçu', null);
    }

    /**
     * Étape du tableau d'après le statut prépresse (source de vérité).
     */
    public static function etapeDe(string $statut): string
    {
        return match ($statut) {
            ControlePrePresse::STATUT_EN_COURS => self::EN_CONCEPTION,
            ControlePrePresse::STATUT_EN_ATTENTE_BAT => self::BAT_ENVOYE,
            ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_REJETE => self::CORRECTIONS,
            ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => self::VALIDE,
            default => self::A_FAIRE,
        };
    }

    public function etape(): string
    {
        $etape = self::etapeDe($this->controle->getStatut());

        return $this->valideLe !== null && $etape !== self::CORRECTIONS ? self::VALIDE : $etape;
    }

    public function aEvenement(string $type): bool
    {
        return \in_array($type, array_column($this->evenements, 'type'), true);
    }

    public function regler(string $difficulte, ?\DateTimeImmutable $echeanceBat, ?int $tempsPrevu): void
    {
        $ancien = $this->difficulte;
        $this->difficulte = isset(Bareme::DIFFICULTES[$difficulte]) ? $difficulte : 'simple';
        $this->echeanceBat = $echeanceBat ?? $this->echeanceBat;
        if ($tempsPrevu !== null && $tempsPrevu > 0) {
            $this->tempsPrevu = min($tempsPrevu, 24 * 60);
        } elseif ($ancien !== $this->difficulte && $this->tempsPrevu === self::TEMPS_PREVU[$ancien]) {
            $this->tempsPrevu = self::TEMPS_PREVU[$this->difficulte];
        }
    }

    public function demarrer(User $graphiste, \DateTimeImmutable $le): void
    {
        if ($this->demarreLe !== null) {
            return;
        }
        $premiere = $this->graphiste === null && $this->secondes === 0;
        $this->graphiste ??= $graphiste;
        $this->demarreLe = $le;
        if ($premiere) {
            $this->noter($le, 'prise', 'Prise en charge', \sprintf('%s démarre le chronomètre', $graphiste->getEmploye()?->getPrenom() ?? $graphiste->getUsername()));
        }
    }

    /** Arrête le chronomètre (pause, attente du client). */
    public function arreter(\DateTimeImmutable $le): void
    {
        if ($this->demarreLe !== null) {
            $this->secondes += max(0, $le->getTimestamp() - $this->demarreLe->getTimestamp());
            $this->demarreLe = null;
        }
    }

    public function enCours(): bool
    {
        return $this->demarreLe !== null;
    }

    /** Temps de conception, chronomètre compris. */
    public function secondesA(\DateTimeImmutable $maintenant): int
    {
        return $this->secondes + ($this->demarreLe !== null ? max(0, $maintenant->getTimestamp() - $this->demarreLe->getTimestamp()) : 0);
    }

    public function envoyerBat(\DateTimeImmutable $le, ?string $canal = null): void
    {
        $this->arreter($le);
        ++$this->versions;
        $this->premierBatLe ??= $le;
        $this->dernierBatLe = $le;
        $avance = $this->echeanceBat !== null ? intdiv($this->echeanceBat->getTimestamp() - $le->getTimestamp(), 60) : null;
        $detail = array_filter([
            $canal,
            $avance === null ? null : ($avance >= 0 ? self::duree($avance * 60).' avant l\'échéance' : self::duree(-$avance * 60).' après l\'échéance'),
        ]);
        $this->noter($le, 'bat', \sprintf('BAT V%d envoyé', $this->versions), $detail !== [] ? implode(' · ', $detail) : null);
    }

    /**
     * Retour sur un BAT : un changement d'avis du client ne coûte rien,
     * une erreur du graphiste retire des points.
     */
    public function retour(string $raison, ?string $texte, \DateTimeImmutable $le): void
    {
        $erreur = $raison === self::RETOUR_ERREUR;
        $erreur ? ++$this->erreurs : ++$this->changementsClient;
        $texte = $texte !== null && trim($texte) !== '' ? '« '.mb_substr(trim($texte), 0, 160).' »' : null;
        $this->noter($le, $erreur ? 'erreur' : 'retour', $erreur ? 'Erreur à corriger' : 'Retour client', implode(' · ', array_filter([$texte, $erreur ? 'erreur du graphiste' : 'demande du client'])));
    }

    /**
     * BAT validé : calcule les points avec le barème.
     */
    public function valider(Bareme $bareme, \DateTimeImmutable $le, ?string $detail = null): void
    {
        $this->arreter($le);
        if ($this->valideLe !== null) {
            return;
        }
        $this->valideLe = $le;
        $this->faits = $this->faitsPour($le);
        $this->points = $bareme->points($this->faits, $this->difficulte);
        $this->pointsPossibles = $bareme->possibles(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1], $this->difficulte);
        $this->noter($le, 'valide', 'BAT validé', $detail);
    }

    public function noterMontage(\DateTimeImmutable $le, ?string $detail): void
    {
        $this->noter($le, 'montage', 'Montage d\'impression enregistré', $detail);
    }

    public function envoyeEnProduction(\DateTimeImmutable $le, ?string $detail): void
    {
        $this->noter($le, 'production', 'Envoyé en production', $detail);
    }

    /**
     * @return array<string, int>
     */
    public function faitsPour(\DateTimeImmutable $valideLe): array
    {
        $faits = [];
        $premier = $this->premierBatLe ?? $valideLe;
        if ($this->echeanceBat === null || $premier <= $this->echeanceBat) {
            $faits['g_bat_a_temps'] = 1;
        } else {
            $faits['g_retard_30'] = (int) ceil(($premier->getTimestamp() - $this->echeanceBat->getTimestamp()) / 1800);
        }
        if ($this->erreurs === 0) {
            $faits['g_sans_erreur'] = 1;
        } else {
            $faits['g_erreur'] = $this->erreurs;
        }
        if ($this->changementsClient > 0) {
            $faits['g_avis_client'] = $this->changementsClient;
        }

        return $faits;
    }

    /** Validé au premier envoi, sans retour. */
    public function valideAuPremierEnvoi(): bool
    {
        return $this->valideLe !== null && $this->versions <= 1 && $this->erreurs === 0 && $this->changementsClient === 0;
    }

    public function dansLesDelais(): ?bool
    {
        if ($this->echeanceBat === null || $this->premierBatLe === null) {
            return null;
        }

        return $this->premierBatLe <= $this->echeanceBat;
    }

    public static function duree(int $secondes): string
    {
        $minutes = intdiv(max(0, $secondes), 60);

        return $minutes >= 60 ? \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60) : $minutes.' min';
    }

    /**
     * Ce que le client peut voir de l'historique (site en ligne) : BAT
     * envoyés, ses propres demandes et la validation, sans les détails
     * internes (retard, nom du graphiste, erreurs).
     *
     * @param list<array{le: string, type: string, titre: string, detail: ?string}> $evenements
     *
     * @return list<array{le: string, type: string, titre: string, detail: ?string}>
     */
    public static function evenementsPublics(array $evenements): array
    {
        $publics = [];
        foreach ($evenements as $e) {
            if (\in_array($e['type'] ?? null, ['bat', 'retour', 'valide'], true)) {
                $publics[] = ['le' => $e['le'], 'type' => $e['type'], 'titre' => $e['titre'], 'detail' => 'retour' === $e['type'] ? $e['detail'] : null];
            }
        }

        return $publics;
    }

    private function noter(\DateTimeImmutable $le, string $type, string $titre, ?string $detail): void
    {
        $this->evenements[] = ['le' => $le->format(\DATE_ATOM), 'type' => $type, 'titre' => $titre, 'detail' => $detail];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getControle(): ControlePrePresse
    {
        return $this->controle;
    }

    public function getGraphiste(): ?User
    {
        return $this->graphiste;
    }

    public function setGraphiste(?User $graphiste): static
    {
        $this->graphiste = $graphiste;

        return $this;
    }

    public function getDifficulte(): string
    {
        return $this->difficulte;
    }

    public function getEcheanceBat(): ?\DateTimeImmutable
    {
        return $this->echeanceBat;
    }

    public function getTempsPrevu(): int
    {
        return $this->tempsPrevu;
    }

    public function getDemarreLe(): ?\DateTimeImmutable
    {
        return $this->demarreLe;
    }

    public function getSecondes(): int
    {
        return $this->secondes;
    }

    public function getVersions(): int
    {
        return $this->versions;
    }

    public function getErreurs(): int
    {
        return $this->erreurs;
    }

    public function getChangementsClient(): int
    {
        return $this->changementsClient;
    }

    public function getPremierBatLe(): ?\DateTimeImmutable
    {
        return $this->premierBatLe;
    }

    public function getDernierBatLe(): ?\DateTimeImmutable
    {
        return $this->dernierBatLe;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function getPointsPossibles(): ?int
    {
        return $this->pointsPossibles;
    }

    /** @return array<string, int|float> */
    public function getFaits(): array
    {
        return $this->faits;
    }

    /** @return list<array{le: \DateTimeImmutable, type: string, titre: string, detail: ?string}> */
    public function getEvenements(): array
    {
        return array_map(static fn (array $e): array => ['le' => new \DateTimeImmutable($e['le'])] + $e, $this->evenements);
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
