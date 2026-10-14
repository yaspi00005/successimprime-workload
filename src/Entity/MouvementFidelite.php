<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du relevé de points de fidélité d'un client (docs/fidelite.md).
 *
 * Le solde est la somme des points. Les lignes positives (gain, ajustement
 * positif, points rendus) sont des lots qui expirent ; les lignes négatives
 * les consomment du plus ancien au plus récent (CalculFidelite::etat).
 *
 * Un gain porte sur une ligne de commande et un paiement : il garde ce qui a
 * été compté pour la progression vers les seuils (m², pièces, montant), même
 * quand il ne rapporte aucun point (paiement tardif, sous le seuil).
 *
 * Descend sur le site (type, points, dates, commande) : l'agent, le motif,
 * le paiement et la ligne restent au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mouvement_fidelite')]
#[ORM\Index(name: 'idx_mouvement_fidelite_client_date', columns: ['client_id', 'date'])]
#[ORM\Index(name: 'idx_mouvement_fidelite_type', columns: ['type'])]
class MouvementFidelite
{
    use PublicIdTrait;

    public const GAIN = 'gain';
    public const UTILISATION = 'utilisation';
    public const AJUSTEMENT = 'ajustement';
    public const EXPIRATION = 'expiration';
    /** Retire un gain (paiement ou commande annulés) ou rend des points utilisés. */
    public const ANNULATION = 'annulation';

    public const TYPES = [
        self::GAIN => 'Points gagnés',
        self::UTILISATION => 'Points utilisés',
        self::AJUSTEMENT => 'Ajustement',
        self::EXPIRATION => 'Points expirés',
        self::ANNULATION => 'Annulation',
    ];

    public const FAMILLE_M2 = 'm2';
    public const FAMILLE_AUTRE = 'autre';

    /** D'où viennent les valeurs d'un gain. */
    public const REGLE_DEFAUT = 'defaut';
    public const REGLE_CLIENT = 'client';
    public const REGLE_ARTICLE = 'article';
    public const REGLE_DESACTIVE = 'desactive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 20)]
    private string $type = self::GAIN;

    #[ORM\Column]
    private int $points = 0;

    /** Valeur d'un point de cette ligne en francs (1 par défaut, réglable par article). */
    #[ORM\Column(options: ['default' => 1])]
    private int $valeurPoint = 1;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $famille = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produits $produit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandesDetails $ligne = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    /** m² comptés pour la progression (part payée de la ligne). */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $m2 = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4, nullable: true)]
    private ?string $pieces = null;

    /** Montant compté (gain) ou remise accordée en francs (utilisation). */
    #[ORM\Column(nullable: true)]
    private ?int $montant = null;

    /** ×2 payé le jour même, ×1 dans les 72 h, 0 au-delà. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $multiplicateur = null;

    #[ORM\Column(length: 12, nullable: true)]
    private ?string $regle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expireLe = null;

    /** Le lot visé : gain retiré, points utilisés rendus, lot expiré. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $cible = null;

    /** Gain retiré ou utilisation rendue : la ligne ne compte plus pour la progression. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $agent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motif = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->date = new \DateTimeImmutable();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function setClient(?Clients $client): static { $this->client = $client; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Type de mouvement de fidélité inconnu.');
        }
        $this->type = $type;

        return $this;
    }
    public function getTypeLibelle(): string { return self::TYPES[$this->type] ?? $this->type; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $points): static { $this->points = $points; return $this; }
    public function getValeurPoint(): int { return $this->valeurPoint; }
    public function setValeurPoint(int $valeur): static { $this->valeurPoint = max(1, $valeur); return $this; }
    public function getFamille(): ?string { return $this->famille; }
    public function setFamille(?string $famille): static { $this->famille = $famille; return $this; }
    public function getProduit(): ?Produits { return $this->produit; }
    public function setProduit(?Produits $produit): static { $this->produit = $produit; return $this; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function setCommande(?Commandes $commande): static { $this->commande = $commande; return $this; }
    public function getLigne(): ?CommandesDetails { return $this->ligne; }
    public function setLigne(?CommandesDetails $ligne): static { $this->ligne = $ligne; return $this; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
    public function setPaiement(?Paiements $paiement): static { $this->paiement = $paiement; return $this; }
    public function getM2(): float { return (float) ($this->m2 ?? 0); }
    public function setM2(?float $m2): static { $this->m2 = null === $m2 ? null : number_format($m2, 4, '.', ''); return $this; }
    public function getPieces(): float { return (float) ($this->pieces ?? 0); }
    public function setPieces(?float $pieces): static { $this->pieces = null === $pieces ? null : number_format($pieces, 4, '.', ''); return $this; }
    public function getMontant(): ?int { return $this->montant; }
    public function setMontant(?int $montant): static { $this->montant = $montant; return $this; }
    public function getMultiplicateur(): ?int { return $this->multiplicateur; }
    public function setMultiplicateur(?int $m): static { $this->multiplicateur = $m; return $this; }
    public function getRegle(): ?string { return $this->regle; }
    public function setRegle(?string $regle): static { $this->regle = $regle; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getExpireLe(): ?\DateTimeImmutable { return $this->expireLe; }
    public function setExpireLe(?\DateTimeImmutable $d): static { $this->expireLe = $d; return $this; }
    public function getCible(): ?self { return $this->cible; }
    public function setCible(?self $cible): static { $this->cible = $cible; return $this; }
    public function getAnnuleLe(): ?\DateTimeImmutable { return $this->annuleLe; }
    public function setAnnuleLe(?\DateTimeImmutable $d): static { $this->annuleLe = $d; return $this; }
    public function estAnnule(): bool { return null !== $this->annuleLe; }
    public function getAgent(): ?User { return $this->agent; }
    public function setAgent(?User $agent): static { $this->agent = $agent; return $this; }
    public function getMotif(): ?string { return $this->motif; }
    public function setMotif(?string $motif): static { $this->motif = null !== $motif && '' !== trim($motif) ? mb_substr(trim($motif), 0, 255) : null; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }

    /** Valeur en francs des points de la ligne. */
    public function getValeurF(): int { return $this->points * $this->valeurPoint; }
}
