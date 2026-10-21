<?php

namespace App\Entity;

use App\Repository\ColisProductionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un colis d'une commande (PD05, docs/production-colis.md) : une étiquette
 * 100 × 60 mm par colis, numérotée « 1/2 », « 2/2 », avec un QR qui porte
 * un jeton imprévisible et un code court lisible sous le QR. Le scan de
 * chaque colis (PD06, PD07) fait passer la commande « Prête » avec le
 * contrôle final. Reste à l'ERP local, jamais synchronisé.
 *
 * Réétiqueter une commande avec un autre nombre de colis annule les anciens
 * colis : leur QR répond alors « étiquette remplacée ».
 */
#[ORM\Entity(repositoryClass: ColisProductionRepository::class)]
#[ORM\Table(name: 'colis_production')]
#[ORM\Index(name: 'idx_colis_production_commande', columns: ['commande_id', 'annule_le'])]
class ColisProduction
{
    public const ROULEAU = 'rouleau';
    public const CARTON = 'carton';
    public const HOUSSE = 'housse';
    public const AUTRE = 'autre';

    /** Emballage => « Où coller » (maquette PD05). */
    public const OU_COLLER = [
        self::ROULEAU => ['libelle' => 'Bâche roulée', 'conseil' => 'sur le film, à 10 cm du bord, côté visible.'],
        self::CARTON => ['libelle' => 'Carton', 'conseil' => 'sur le dessus, jamais sur une ouverture.'],
        self::HOUSSE => ['libelle' => 'Kakémono', 'conseil' => 'sur la housse.'],
        self::AUTRE => ['libelle' => 'Autre colis', 'conseil' => 'sur la face la plus visible, à plat.'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commandes::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commandes $commande;

    /** Série d'étiquettes de la commande : 1 à la première impression, +1 à chaque réétiquetage. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $serie = 1;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rang;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $total;

    /** 64 caractères hexadécimaux, portés par le QR : ni devinables ni un id. */
    #[ORM\Column(length: 64, unique: true)]
    private string $jeton;

    /** Code court imprimé sous le QR, à taper ou à lire à la douchette (CMD-000183-C2). */
    #[ORM\Column(length: 50, unique: true)]
    private string $code;

    /**
     * Ce que contient le colis.
     *
     * @var list<array{ordre: int, ligne: int, libelle: string, quantite: int}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $contenu = [];

    #[ORM\Column(length: 12)]
    private string $emballage = self::AUTRE;

    #[ORM\Column]
    private \DateTimeImmutable $imprimeLe;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $imprimePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scanneLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $scannePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    /**
     * @param list<array{ordre: int, ligne: int, libelle: string, quantite: int}> $contenu
     */
    public function __construct(Commandes $commande, int $serie, int $rang, int $total, string $emballage, array $contenu, ?User $par, \DateTimeImmutable $le)
    {
        if ($total < 1 || $total > 99 || $rang < 1 || $rang > $total) {
            throw new \InvalidArgumentException('Le nombre de colis va de 1 à 99.');
        }
        $this->commande = $commande;
        $this->serie = max(1, $serie);
        $this->rang = $rang;
        $this->total = $total;
        $this->emballage = isset(self::OU_COLLER[$emballage]) ? $emballage : self::AUTRE;
        $this->contenu = array_values($contenu);
        $this->imprimePar = $par;
        $this->imprimeLe = $le;
        $this->jeton = bin2hex(random_bytes(32));
        $this->code = self::codeDe((string) $commande->getNumero(), $this->serie, $rang);
    }

    /** CMD-000183-C2 ; à partir du 2e étiquetage : CMD-000183-S2-C1. */
    public static function codeDe(string $numeroCommande, int $serie, int $rang): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z0-9-]+/', '', $numeroCommande) ?: 'CMD');

        return mb_substr($base, 0, 38).($serie > 1 ? '-S'.$serie : '').'-C'.$rang;
    }

    /**
     * Jeton complet (64 hexadécimaux, seul ou dans l'URL du QR) ou code
     * court, tels qu'ils sont tapés, lus à la douchette ou par la caméra.
     *
     * @return array{jeton: string}|array{code: string}|null
     */
    public static function lireSaisie(string $saisie): ?array
    {
        $saisie = trim($saisie);
        if ($saisie === '') {
            return null;
        }
        if (preg_match('~/c/([a-f0-9]{64})(?:[/?#]|$)~i', $saisie, $m) === 1 || preg_match('~^([a-f0-9]{64})$~i', $saisie, $m) === 1) {
            return ['jeton' => strtolower($m[1])];
        }
        $code = strtoupper(preg_replace('/\s+/', '', $saisie) ?? '');
        if (preg_match('/^[A-Z0-9-]{3,50}-C\d{1,2}$/', $code) === 1) {
            return ['code' => $code];
        }

        return null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): Commandes
    {
        return $this->commande;
    }

    public function getSerie(): int
    {
        return $this->serie;
    }

    public function getRang(): int
    {
        return $this->rang;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /** @return list<array{ordre: int, ligne: int, libelle: string, quantite: int}> */
    public function getContenu(): array
    {
        return $this->contenu;
    }

    /** « Bâche 300 × 100 cm × 1 · ourlet + œillets » : le contenu en une ligne. */
    public function getContenuTexte(): string
    {
        return implode(' ; ', array_map(static fn (array $c): string => (string) $c['libelle'], $this->contenu));
    }

    public function getEmballage(): string
    {
        return $this->emballage;
    }

    public function estRoule(): bool
    {
        return $this->emballage === self::ROULEAU;
    }

    public function getImprimeLe(): \DateTimeImmutable
    {
        return $this->imprimeLe;
    }

    public function getImprimePar(): ?User
    {
        return $this->imprimePar;
    }

    public function getScanneLe(): ?\DateTimeImmutable
    {
        return $this->scanneLe;
    }

    public function getScannePar(): ?User
    {
        return $this->scannePar;
    }

    public function estScanne(): bool
    {
        return $this->scanneLe !== null;
    }

    public function getAnnuleLe(): ?\DateTimeImmutable
    {
        return $this->annuleLe;
    }

    public function estAnnule(): bool
    {
        return $this->annuleLe !== null;
    }

    /**
     * Colis collé et scanné. Un second scan ne change rien (l'heure du
     * premier reste) : renvoie false dans ce cas.
     */
    public function scanner(User $par, \DateTimeImmutable $le): bool
    {
        if ($this->annuleLe !== null) {
            throw new \LogicException('Étiquette remplacée : scannez la nouvelle étiquette de ce colis.');
        }
        if ($this->scanneLe !== null) {
            return false;
        }
        $this->scanneLe = $le;
        $this->scannePar = $par;

        return true;
    }

    public function annuler(\DateTimeImmutable $le): void
    {
        $this->annuleLe ??= $le;
    }

    /**
     * Une pièce de ce travail est à refaire (contrôle final) : si ce colis
     * le contient, son scan ne compte plus, il se rescanne une fois la
     * pièce refaite et remise dedans. Renvoie true si le colis le contient.
     */
    public function rouvrirPourReprise(int $ordre): bool
    {
        if ([] === array_filter($this->contenu, static fn (array $c): bool => (int) $c['ordre'] === $ordre)) {
            return false;
        }
        $this->scanneLe = null;
        $this->scannePar = null;

        return true;
    }
}
