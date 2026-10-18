<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des rapports envoyés par e-mail (docs/rapports-mail.md) :
 * une ligne par rapport du jour, de la semaine ou du mois, avec le
 * résultat pour chaque destinataire, et une ligne par essai
 * (« M'envoyer un test »).
 *
 * La clé « jour:2026-10-17 » d'un vrai envoi est unique : la ligne est
 * posée avant le premier e-mail, si bien qu'une tâche relancée (chaque
 * quart d'heure par le planificateur, ou à la main) n'envoie jamais deux
 * fois le même rapport. Un essai n'a pas de clé. Le contenu des e-mails
 * n'est pas gardé : seulement qui l'a reçu, quand, et l'erreur s'il n'est
 * pas parti. Table locale, non synchronisée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'envoi_rapport')]
#[ORM\UniqueConstraint(name: 'uniq_envoi_rapport_cle', columns: ['cle'])]
#[ORM\Index(name: 'idx_envoi_rapport_demarre', columns: ['demarre_le'])]
class EnvoiRapport
{
    public const EN_COURS = 'en_cours';
    public const ENVOYE = 'envoye';
    public const PARTIEL = 'partiel';
    public const ECHEC = 'echec';

    public const STATUTS = [
        self::EN_COURS => 'En cours',
        self::ENVOYE => 'Envoyé',
        self::PARTIEL => 'En partie',
        self::ECHEC => 'Échec',
    ];

    /** Résultat pour un destinataire. */
    public const LIGNE_A_ENVOYER = 'a_envoyer';
    public const LIGNE_ENVOYE = 'envoye';
    public const LIGNE_ECHEC = 'echec';
    public const LIGNE_SANS_ADRESSE = 'sans_adresse';

    public const LIGNES = [
        self::LIGNE_A_ENVOYER => 'Pas encore parti',
        self::LIGNE_ENVOYE => 'Envoyé',
        self::LIGNE_ECHEC => 'Échec',
        self::LIGNE_SANS_ADRESSE => 'Sans adresse e-mail',
    ];

    /** Lancé par la tâche planifiée, à la main (commande), ou essai depuis l'écran. */
    public const AUTO = 'auto';
    public const COMMANDE = 'commande';
    public const ESSAI = 'essai';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** jour, semaine ou mois (App\Service\Rapports\PeriodeRapport). */
    #[ORM\Column(length: 10)]
    private string $type;

    /** « 2026-10-17 », « 2026-W42 », « 2026-10 ». */
    #[ORM\Column(length: 12)]
    private string $periode;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    /** « jour:2026-10-17 » pour un vrai envoi, null pour un essai. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $cle = null;

    #[ORM\Column(length: 10)]
    private string $declencheur;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $demandePar = null;

    #[ORM\Column(length: 10)]
    private string $statut = self::EN_COURS;

    #[ORM\Column]
    private \DateTimeImmutable $demarreLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineLe = null;

    /**
     * Un élément par destinataire, dans l'ordre d'envoi.
     *
     * @var list<array{utilisateur: int, nom: string, adresse: ?string, version: string, statut: string, le: ?string, erreur: ?string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $lignes = [];

    #[ORM\Column]
    private bool $pieceJointe = false;

    /** Premier envoi, puis une de plus à chaque reprise des destinataires en échec. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $tentatives = 1;

    public function __construct(string $type, string $periode, \DateTimeImmutable $debut, string $declencheur, \DateTimeImmutable $demarreLe, ?User $demandePar = null)
    {
        $this->type = $type;
        $this->periode = $periode;
        $this->debut = $debut->setTime(0, 0);
        $this->declencheur = $declencheur;
        $this->demarreLe = $demarreLe;
        $this->demandePar = $demandePar;
        $this->cle = self::ESSAI === $declencheur ? null : self::cleDe($type, $periode);
    }

    public static function cleDe(string $type, string $periode): string
    {
        return $type.':'.$periode;
    }

    /** @param list<array{utilisateur: int, nom: string, adresse: ?string, version: string}> $destinataires */
    public function preparer(array $destinataires, bool $pieceJointe): static
    {
        $this->pieceJointe = $pieceJointe;
        $this->lignes = array_map(static fn (array $d): array => $d + [
            'statut' => null === $d['adresse'] ? self::LIGNE_SANS_ADRESSE : self::LIGNE_A_ENVOYER,
            'le' => null,
            'erreur' => null,
        ], $destinataires);

        return $this;
    }

    /** Note le résultat pour le destinataire de rang $i. */
    public function noter(int $i, string $statut, \DateTimeImmutable $le, ?string $erreur = null): static
    {
        if (isset($this->lignes[$i])) {
            $this->lignes[$i]['statut'] = $statut;
            $this->lignes[$i]['le'] = $le->format('Y-m-d H:i:s');
            $this->lignes[$i]['erreur'] = $erreur;
        }

        return $this;
    }

    /** Adresse lue à nouveau sur la fiche employé, au moment d'une reprise. */
    public function adresser(int $i, ?string $adresse): static
    {
        if (isset($this->lignes[$i])) {
            $this->lignes[$i]['adresse'] = $adresse;
            if (null === $adresse) {
                $this->lignes[$i]['statut'] = self::LIGNE_SANS_ADRESSE;
            }
        }

        return $this;
    }

    /** Nouvel essai pour les destinataires en échec : l'envoi repasse « en cours ». */
    public function rouvrir(): static
    {
        $this->statut = self::EN_COURS;
        $this->termineLe = null;
        ++$this->tentatives;

        return $this;
    }

    /** Fin de l'envoi : statut d'ensemble d'après les lignes. */
    public function terminer(\DateTimeImmutable $le): static
    {
        $this->statut = self::statutDe($this->lignes);
        $this->termineLe = $le;

        return $this;
    }

    /**
     * Envoyé : chaque destinataire l'a reçu ; échec : personne ; en
     * partie : entre les deux (un destinataire sans adresse compte comme
     * un manque, pour que la direction le voie).
     *
     * @param list<array{statut: string}> $lignes
     */
    public static function statutDe(array $lignes): string
    {
        $envoyes = \count(array_filter($lignes, static fn (array $l): bool => self::LIGNE_ENVOYE === $l['statut']));

        return match (true) {
            0 === $envoyes => self::ECHEC,
            $envoyes === \count($lignes) => self::ENVOYE,
            default => self::PARTIEL,
        };
    }

    /**
     * Rangs des destinataires à qui le rapport n'est pas encore parti
     * (échec ou envoi interrompu), sans ceux qui n'ont pas d'adresse.
     *
     * @return list<int>
     */
    public function aReprendre(): array
    {
        return array_keys(array_filter($this->lignes, static fn (array $l): bool => \in_array($l['statut'], [self::LIGNE_ECHEC, self::LIGNE_A_ENVOYER], true)));
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getPeriode(): string { return $this->periode; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function getCle(): ?string { return $this->cle; }
    public function getDeclencheur(): string { return $this->declencheur; }
    public function getDemandePar(): ?User { return $this->demandePar; }
    public function getStatut(): string { return $this->statut; }
    public function getLibelleStatut(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function getDemarreLe(): \DateTimeImmutable { return $this->demarreLe; }
    public function getTermineLe(): ?\DateTimeImmutable { return $this->termineLe; }
    public function isEssai(): bool { return self::ESSAI === $this->declencheur; }
    public function isPieceJointe(): bool { return $this->pieceJointe; }
    public function getTentatives(): int { return $this->tentatives; }

    /** @return list<array{utilisateur: int, nom: string, adresse: ?string, version: string, statut: string, le: ?string, erreur: ?string}> */
    public function getLignes(): array { return $this->lignes; }

    public function nombre(string $statut): int
    {
        return \count(array_filter($this->lignes, static fn (array $l): bool => $l['statut'] === $statut));
    }
}
