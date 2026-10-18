<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code promo généré pour une promotion (docs/promotions.md, « Codes promo ») :
 * un code partagé (RENTREE25), limité à un nombre d'utilisations en tout,
 * ou un code d'un lot de codes uniques à usage unique (SI-7K4P-2Q).
 *
 * Le code débloque la promotion : ses lignes (articles visés) et, si elle
 * en a un, son avantage sur la commande. Une utilisation, c'est un devis
 * pas encore transformé ou une commande qui le porte (Commandes::codePromo,
 * Devis::codePromo) ; une commande supprimée ou annulée la rend.
 *
 * Le code en clair reste au local. Le site en ligne n'en reçoit que
 * l'empreinte (HMAC-SHA256 avec la clé de synchronisation, CalculCodePromo),
 * ses dates, ses limites et son nombre d'utilisations, pour vérifier ce que
 * le client tape dans son espace ; l'imprimerie revérifie à la confirmation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'code_promo')]
#[ORM\Index(name: 'idx_code_promo_empreinte', columns: ['empreinte'])]
class CodePromo
{
    use PublicIdTrait;

    public const TYPE_PARTAGE = 'partage';
    public const TYPE_LOT = 'lot';

    public const TYPES = [
        self::TYPE_PARTAGE => 'Code partagé',
        self::TYPE_LOT => 'Code unique d\'un lot',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Promotion $promotion = null;

    /** En clair, lettres et chiffres seulement (CalculCodePromo::canonique) ; vide sur le site. */
    #[ORM\Column(length: 30, unique: true, nullable: true)]
    private ?string $code = null;

    /** HMAC-SHA256 du code avec SYNC_CLE : ce que le site garde pour reconnaître le code. */
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $empreinte = '';

    #[ORM\Column(length: 8)]
    private string $type = self::TYPE_PARTAGE;

    /** Lot de codes uniques (L1, L2…) : pour l'export et la feuille à imprimer. Reste au local. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $lot = null;

    /** Premier jour (facultatif : sinon celui de la promotion). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $debut = null;

    /** Dernier jour compris ; jamais après la fin de la promotion. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $expiration;

    /** Utilisations en tout (1 pour un code de lot). */
    #[ORM\Column(options: ['default' => 1])]
    private int $utilisationsMax = 1;

    #[ORM\Column(options: ['default' => 1])]
    private int $utilisationsParClient = 1;

    /** Utilisations comptées au local (devis et commandes vivants), pour la liste et pour le site. */
    #[ORM\Column(options: ['default' => 0])]
    private int $utilisations = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $desactivePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $desactiveLe = null;

    /** Sans argument : la synchronisation crée la copie du site ainsi (CatalogueSynchro). */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->expiration = new \DateTimeImmutable('today');
    }

    /** Un code neuf, au local (CodesPromo). */
    public static function creer(Promotion $promotion, string $type, string $code, string $empreinte, \DateTimeImmutable $expiration, \DateTimeImmutable $maintenant): self
    {
        $c = new self();
        $c->promotion = $promotion;
        $c->type = isset(self::TYPES[$type]) ? $type : self::TYPE_PARTAGE;
        $c->code = $code;
        $c->empreinte = $empreinte;
        $c->expiration = $expiration->setTime(0, 0);
        $c->creeLe = $maintenant;

        return $c;
    }

    public function getId(): ?int { return $this->id; }
    public function getPromotion(): ?Promotion { return $this->promotion; }
    public function getCode(): ?string { return $this->code; }
    public function getEmpreinte(): string { return $this->empreinte; }
    public function setEmpreinte(string $e): static { $this->empreinte = $e; return $this; }
    public function getType(): string { return $this->type; }
    public function estDUnLot(): bool { return self::TYPE_LOT === $this->type; }
    public function getLot(): ?string { return $this->lot; }
    public function setLot(?string $lot): static { $this->lot = $lot; return $this; }
    public function getDebut(): ?\DateTimeImmutable { return $this->debut; }
    public function setDebut(?\DateTimeImmutable $d): static { $this->debut = $d?->setTime(0, 0); return $this; }
    public function getExpiration(): \DateTimeImmutable { return $this->expiration; }
    public function setExpiration(\DateTimeImmutable $d): static { $this->expiration = $d->setTime(0, 0); return $this; }
    public function getUtilisationsMax(): int { return $this->utilisationsMax; }
    public function setUtilisationsMax(int $n): static { $this->utilisationsMax = max(1, $n); return $this; }
    public function getUtilisationsParClient(): int { return $this->utilisationsParClient; }
    public function setUtilisationsParClient(int $n): static { $this->utilisationsParClient = max(1, $n); return $this; }
    public function getUtilisations(): int { return $this->utilisations; }
    public function setUtilisations(int $n): static { $this->utilisations = max(0, $n); return $this; }
    public function isActive(): bool { return $this->active; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function setCreePar(?User $u): static { $this->creePar = $u; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getDesactivePar(): ?User { return $this->desactivePar; }
    public function getDesactiveLe(): ?\DateTimeImmutable { return $this->desactiveLe; }

    public function desactiver(?User $par, \DateTimeImmutable $le): static
    {
        $this->active = false;
        $this->desactivePar = $par;
        $this->desactiveLe = $le;

        return $this;
    }

    public function reactiver(): static
    {
        $this->active = true;
        $this->desactivePar = null;
        $this->desactiveLe = null;

        return $this;
    }

    /** Premier jour de validité : le sien, sinon celui de la promotion. */
    public function getDebutEffectif(): \DateTimeImmutable
    {
        $debutPromotion = $this->promotion?->getDebut() ?? $this->expiration;

        return null !== $this->debut && $this->debut > $debutPromotion ? $this->debut : $debutPromotion;
    }

    /** Dernier jour : le sien, jamais après la fin de la promotion. */
    public function getFinEffective(): \DateTimeImmutable
    {
        $fin = $this->promotion?->getFin();

        return null !== $fin && $fin < $this->expiration ? $fin : $this->expiration;
    }

    /** « SI-7K4P-2Q » pour un code de lot, le code tel quel sinon. */
    public function getCodeAffiche(): string
    {
        $code = (string) $this->code;
        if (self::TYPE_LOT === $this->type && 8 === \strlen($code) && str_starts_with($code, 'SI')) {
            return 'SI-'.substr($code, 2, 4).'-'.substr($code, 6, 2);
        }

        return $code;
    }

    public function estEpuise(): bool
    {
        return $this->utilisations >= $this->utilisationsMax;
    }

    /** active, a_venir, expire, epuise ou desactive (pour la liste). */
    public function etat(\DateTimeInterface $jour): string
    {
        $j = $jour->format('Y-m-d');

        return match (true) {
            !$this->active || !($this->promotion?->isActive() ?? false) => 'desactive',
            $j > $this->getFinEffective()->format('Y-m-d') => 'expire',
            $this->estEpuise() => 'epuise',
            $j < $this->getDebutEffectif()->format('Y-m-d') => 'a_venir',
            default => 'actif',
        };
    }

    /**
     * Pour le journal d'activité : jamais le code d'un lot en clair.
     *
     * @return array<string, mixed>
     */
    public function instantane(): array
    {
        return [
            'type' => self::TYPES[$this->type] ?? $this->type,
            'code' => self::TYPE_LOT === $this->type ? null : $this->code,
            'lot' => $this->lot,
            'du' => $this->debut?->format('Y-m-d'),
            'au' => $this->expiration->format('Y-m-d'),
            'utilisations_max' => $this->utilisationsMax,
            'par_client' => $this->utilisationsParClient,
            'active' => $this->active,
        ];
    }
}
