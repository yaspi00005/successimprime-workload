<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un tirage sur rouleau (maquettes PD02 et PD03, docs/production-rouleau.md) :
 * plusieurs travaux (ordres de production) regroupés sur la laize d'une
 * machine, avec leur placement, le bilan du rouleau, le fichier envoyé au
 * RIP et la fiche de contrôle avant impression.
 *
 * Numéro R-MMJJ-NN : R-1020-03 est le 3e tirage du 20/10. Un ordre de
 * production reste une ligne de commande ; toutes ses pièces sont sur un
 * seul tirage. ERP local seulement (jamais synchronisé).
 *
 * Statuts : préparé → envoyé au RIP → en impression (en pause) → imprimé →
 * terminé, ou annulé. L'impression en direct (PD04) se servira des trois
 * statuts du milieu et du statut de chaque pièce.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tirage_rouleau')]
#[ORM\Index(name: 'idx_tirage_statut', columns: ['statut'])]
class TirageRouleau
{
    public const STATUT_PREPARE = 'prepare';
    public const STATUT_ENVOYE = 'envoye';
    public const STATUT_EN_IMPRESSION = 'en_impression';
    public const STATUT_EN_PAUSE = 'en_pause';
    public const STATUT_IMPRIME = 'imprime';
    public const STATUT_TERMINE = 'termine';
    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [
        self::STATUT_PREPARE => 'Préparé',
        self::STATUT_ENVOYE => 'Envoyé au RIP',
        self::STATUT_EN_IMPRESSION => 'En impression',
        self::STATUT_EN_PAUSE => 'En pause',
        self::STATUT_IMPRIME => 'Imprimé',
        self::STATUT_TERMINE => 'Terminé',
        self::STATUT_ANNULE => 'Annulé',
    ];

    public const MODE_AUTO = 'auto';
    public const MODE_MANUEL = 'manuel';

    /** Envoi au RIP : un seul PDF pour tout le rouleau, ou les fichiers séparés avec une feuille de placement. */
    public const ENVOI_UNIQUE = 'unique';
    public const ENVOI_SEPARES = 'separes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private string $numero;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machines $machine;

    /** Article du rouleau chargé (famille « Supports »), quand il est connu. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Articles $support = null;

    #[ORM\Column(length: 160)]
    private string $libelleSupport;

    /** Code de production du support (BACHE440) : les travaux du même code vont sur ce rouleau. */
    #[ORM\Column(length: 30)]
    private string $codeSupport;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $laizeM;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $margeCm;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $espaceCm;

    #[ORM\Column(length: 10)]
    private string $mode = self::MODE_AUTO;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PREPARE;

    /* Bilan du rouleau (ImpositionRouleau::bilan). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, options: ['default' => '0.000'])]
    private string $imprimeM2 = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, options: ['default' => '0.000'])]
    private string $consommeM = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, options: ['default' => '0.000'])]
    private string $consommeM2 = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 3, options: ['default' => '0.000'])]
    private string $chuteM2 = '0';

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $pourcent = 0;

    /* Estimation (profil de la machine). */
    #[ORM\Column(nullable: true)]
    private ?int $dureeImpressionMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $sechageMin = null;

    #[ORM\Column(nullable: true)]
    private ?int $encreMl = null;

    /* Dernier fichier envoyé au RIP (sous var/uploads/production/rouleaux). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichierRipChemin = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fichierRipNom = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fichierRipEmpreinte = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $envoiMode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $envoyePar = null;

    /** Où la copie a été posée, tel que les postes le voient (\\ATELIER\Production\SOLVANT-320\IN\…), null : pas copié. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $copieMachine = null;

    /**
     * Envois au RIP successifs (le dernier en premier) : version, mode, nom,
     * chemin, empreinte, taille, le, par, copie.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $envois = null;

    /**
     * Points cochés sur la fiche de production (ControlesTirage::A_COCHER),
     * avec le code du rouleau scanné : clé => vrai, support_code => texte.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $controles = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rouleauConfirmeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $rouleauConfirmePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $demarreLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $demarrePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $modifieLe = null;

    /** @var Collection<int, PieceTirage> */
    #[ORM\OneToMany(targetEntity: PieceTirage::class, mappedBy: 'tirage', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['rang' => 'ASC', 'id' => 'ASC'])]
    private Collection $pieces;

    public function __construct(string $numero, Machines $machine, string $libelleSupport, string $codeSupport, float $laizeM, float $margeCm, float $espaceCm, ?User $par, \DateTimeImmutable $le)
    {
        $this->numero = $numero;
        $this->machine = $machine;
        $this->libelleSupport = mb_substr($libelleSupport, 0, 160);
        $this->codeSupport = substr($codeSupport, 0, 30);
        $this->laizeM = (string) round($laizeM, 3);
        $this->margeCm = (string) round($margeCm, 2);
        $this->espaceCm = (string) round($espaceCm, 2);
        $this->creePar = $par;
        $this->creeLe = $le;
        $this->pieces = new ArrayCollection();
    }

    /**
     * R-MMJJ-NN : le rang du tirage dans la journée, à partir des numéros
     * déjà pris ce jour-là.
     *
     * @param list<string> $numerosDuJour
     */
    public static function numeroSuivant(\DateTimeImmutable $jour, array $numerosDuJour): string
    {
        $prefixe = 'R-'.$jour->format('md').'-';
        $max = 0;
        foreach ($numerosDuJour as $n) {
            if (str_starts_with($n, $prefixe)) {
                $max = max($max, (int) substr($n, \strlen($prefixe)));
            }
        }

        return $prefixe.str_pad((string) ($max + 1), 2, '0', \STR_PAD_LEFT);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getMachine(): Machines
    {
        return $this->machine;
    }

    public function getSupport(): ?Articles
    {
        return $this->support;
    }

    public function setSupport(?Articles $support): static
    {
        $this->support = $support;

        return $this;
    }

    public function getLibelleSupport(): string
    {
        return $this->libelleSupport;
    }

    public function getCodeSupport(): string
    {
        return $this->codeSupport;
    }

    public function getLaizeM(): float
    {
        return (float) $this->laizeM;
    }

    public function getLaizeCm(): float
    {
        return round((float) $this->laizeM * 100, 1);
    }

    public function getMargeCm(): float
    {
        return (float) $this->margeCm;
    }

    public function getEspaceCm(): float
    {
        return (float) $this->espaceCm;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = self::MODE_MANUEL === $mode ? self::MODE_MANUEL : self::MODE_AUTO;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getLibelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Placement modifiable : avant le début de l'impression. */
    public function estModifiable(): bool
    {
        return \in_array($this->statut, [self::STATUT_PREPARE, self::STATUT_ENVOYE], true);
    }

    public function estAnnule(): bool
    {
        return self::STATUT_ANNULE === $this->statut;
    }

    public function estEnvoye(): bool
    {
        return self::STATUT_ENVOYE === $this->statut;
    }

    /** @param array{longueur: float, consomme: float, imprime: float, chute: float, pourcent: int} $bilan */
    public function enregistrerBilan(array $bilan, ?int $dureeImpressionMin, ?int $sechageMin, ?int $encreMl, \DateTimeImmutable $le): static
    {
        $this->consommeM = (string) round($bilan['longueur'] / 100, 3);
        $this->consommeM2 = (string) round($bilan['consomme'], 3);
        $this->imprimeM2 = (string) round($bilan['imprime'], 3);
        $this->chuteM2 = (string) round($bilan['chute'], 3);
        $this->pourcent = max(0, min(100, $bilan['pourcent']));
        $this->dureeImpressionMin = $dureeImpressionMin;
        $this->sechageMin = $sechageMin;
        $this->encreMl = $encreMl;
        $this->modifieLe = $le;

        return $this;
    }

    public function getImprimeM2(): float
    {
        return (float) $this->imprimeM2;
    }

    public function getConsommeM(): float
    {
        return (float) $this->consommeM;
    }

    public function getConsommeM2(): float
    {
        return (float) $this->consommeM2;
    }

    public function getChuteM2(): float
    {
        return (float) $this->chuteM2;
    }

    public function getPourcent(): int
    {
        return $this->pourcent;
    }

    public function getDureeImpressionMin(): ?int
    {
        return $this->dureeImpressionMin;
    }

    public function getSechageMin(): ?int
    {
        return $this->sechageMin;
    }

    public function getEncreMl(): ?int
    {
        return $this->encreMl;
    }

    /**
     * Enregistre un envoi au RIP : le fichier rangé dans l'ERP, la copie
     * dans le dossier de la machine (ou null), l'historique. Le tirage
     * passe « envoyé » ; un nouvel envoi garde les anciens dans l'historique.
     *
     * @param array{chemin: string, nom: string, empreinte: ?string, taille: int, pages?: int, fichiers?: list<string>} $fichier
     */
    public function enregistrerEnvoi(string $mode, array $fichier, ?string $copie, User $par, \DateTimeImmutable $le): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Ce rouleau est déjà en impression : il ne peut plus être renvoyé au RIP.');
        }
        $envois = $this->getEnvois();
        array_unshift($envois, [
            'version' => \count($envois) + 1,
            'mode' => $mode,
            'nom' => $fichier['nom'],
            'chemin' => $fichier['chemin'],
            'empreinte' => $fichier['empreinte'],
            'taille' => $fichier['taille'],
            'pages' => $fichier['pages'] ?? null,
            'fichiers' => $fichier['fichiers'] ?? [],
            'copie' => $copie,
            'le' => $le->format(\DATE_ATOM),
            'par' => $par->getId(),
            'parNom' => trim((string) $par->getEmploye()?->getPrenom()) ?: $par->getUserIdentifier(),
        ]);
        $this->envois = \array_slice($envois, 0, 30);
        $this->envoiMode = self::ENVOI_SEPARES === $mode ? self::ENVOI_SEPARES : self::ENVOI_UNIQUE;
        $this->fichierRipChemin = $fichier['chemin'];
        $this->fichierRipNom = mb_substr($fichier['nom'], 0, 180);
        $this->fichierRipEmpreinte = $fichier['empreinte'];
        $this->copieMachine = null !== $copie ? mb_substr($copie, 0, 255) : null;
        $this->envoyeLe = $le;
        $this->envoyePar = $par;
        $this->statut = self::STATUT_ENVOYE;
        $this->modifieLe = $le;

        return $this;
    }

    public function getFichierRipChemin(): ?string
    {
        return $this->fichierRipChemin;
    }

    public function getFichierRipNom(): ?string
    {
        return $this->fichierRipNom;
    }

    public function getFichierRipEmpreinte(): ?string
    {
        return $this->fichierRipEmpreinte;
    }

    public function getEnvoiMode(): ?string
    {
        return $this->envoiMode;
    }

    public function getEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getEnvoyePar(): ?User
    {
        return $this->envoyePar;
    }

    public function getCopieMachine(): ?string
    {
        return $this->copieMachine;
    }

    /** @return list<array<string, mixed>> */
    public function getEnvois(): array
    {
        return array_values($this->envois ?? []);
    }

    /** L'envoi d'une version (1 = le premier), null s'il n'existe pas. */
    public function envoi(int $version): ?array
    {
        foreach ($this->getEnvois() as $e) {
            if ((int) ($e['version'] ?? 0) === $version) {
                return $e;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function getControles(): array
    {
        return $this->controles ?? [];
    }

    public function cocher(string $cle, bool $fait): static
    {
        $controles = $this->getControles();
        if ($fait) {
            $controles[$cle] = true;
        } else {
            unset($controles[$cle]);
            if ('support' === $cle) {
                unset($controles['support_code']);
                $this->rouleauConfirmeLe = null;
                $this->rouleauConfirmePar = null;
            }
        }
        $this->controles = [] === $controles ? null : $controles;

        return $this;
    }

    /** Support confirmé par le scan du rouleau (code lu) ou après lecture de son étiquette (code null). */
    public function confirmerRouleau(?string $code, User $par, \DateTimeImmutable $le): static
    {
        $controles = $this->getControles();
        $controles['support'] = true;
        if (null !== $code && '' !== trim($code)) {
            $controles['support_code'] = mb_substr(trim($code), 0, 60);
        } else {
            unset($controles['support_code']);
        }
        $this->controles = $controles;
        $this->rouleauConfirmeLe = $le;
        $this->rouleauConfirmePar = $par;

        return $this;
    }

    public function getRouleauConfirmeLe(): ?\DateTimeImmutable
    {
        return $this->rouleauConfirmeLe;
    }

    public function getRouleauConfirmePar(): ?User
    {
        return $this->rouleauConfirmePar;
    }

    public function demarrer(User $par, \DateTimeImmutable $le): static
    {
        if (self::STATUT_ENVOYE !== $this->statut) {
            throw new \LogicException('Envoyez d\'abord le rouleau au RIP.');
        }
        $this->statut = self::STATUT_EN_IMPRESSION;
        $this->demarreLe = $le;
        $this->demarrePar = $par;
        $this->modifieLe = $le;

        return $this;
    }

    public function annuler(\DateTimeImmutable $le): static
    {
        if (!$this->estModifiable()) {
            throw new \LogicException('Un rouleau en impression ne peut pas être annulé ici.');
        }
        $this->statut = self::STATUT_ANNULE;
        $this->modifieLe = $le;

        return $this;
    }

    public function getDemarreLe(): ?\DateTimeImmutable
    {
        return $this->demarreLe;
    }

    public function getDemarrePar(): ?User
    {
        return $this->demarrePar;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    /** @return Collection<int, PieceTirage> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function ajouterPiece(PieceTirage $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
        }

        return $this;
    }

    public function retirerPiece(PieceTirage $piece): static
    {
        $this->pieces->removeElement($piece);

        return $this;
    }

    /**
     * Les ordres du tirage, dans l'ordre d'impression (rang de leur
     * première pièce).
     *
     * @return list<OrdreProduction>
     */
    public function ordres(): array
    {
        $ordres = [];
        foreach ($this->piecesTriees() as $p) {
            $ordres[spl_object_id($p->getOrdre())] ??= $p->getOrdre();
        }

        return array_values($ordres);
    }

    /** @return list<PieceTirage> les pièces par rang d'impression */
    public function piecesTriees(): array
    {
        $pieces = $this->pieces->toArray();
        usort($pieces, static fn (PieceTirage $a, PieceTirage $b): int => [$a->getRang(), $a->getId() ?? 0] <=> [$b->getRang(), $b->getId() ?? 0]);

        return array_values($pieces);
    }
}
