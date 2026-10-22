<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Promotion par segment de clients (docs/promotions.md) : un avantage sur
 * des articles (produits choisis ou catégorie entière), pendant une
 * période, pour un segment de clients, avec des conditions facultatives.
 *
 * Elle s'applique toute seule à la saisie d'un devis ou d'une commande
 * (PromotionsSaisie) : une seule par ligne, jamais pour un client au prix
 * B2B sans exception acceptée par un responsable. Une promotion déjà
 * utilisée ne se supprime pas (elle se suspend) : les lignes la gardent
 * pour l'historique et les rapports.
 *
 * Reste au local ; les promotions d'anniversaire sont créées le jour J par
 * la tâche app:promotions (origine « anniversaire »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'promotion')]
#[ORM\Index(name: 'idx_promotion_periode', columns: ['active', 'debut', 'fin'])]
class Promotion
{
    use PublicIdTrait;

    public const AVANTAGE_POURCENTAGE = 'pourcentage';
    /** Montant retiré par unité facturable (par m², par pièce...). */
    public const AVANTAGE_MONTANT = 'montant';
    /** Prix fixe par unité facturable. */
    public const AVANTAGE_PRIX_FIXE = 'prix_fixe';

    public const AVANTAGES = [
        self::AVANTAGE_POURCENTAGE => 'Pourcentage',
        self::AVANTAGE_MONTANT => 'Montant en F par unité',
        self::AVANTAGE_PRIX_FIXE => 'Prix fixe par unité',
    ];

    /*
     * Segments calculés (SegmentsClients), plus la fidélité et la liste
     * choisie à la main. La maquette parle de « palier de fidélité » ; le
     * programme n'a pas de paliers (règles du 13/10/2026) : le segment
     * retient les clients qui ont franchi un seuil (leurs achats rapportent).
     */
    public const SEGMENT_TOUS = 'tous';
    public const SEGMENT_FIDELITE = 'fidelite';
    public const SEGMENT_LISTE = 'liste';

    public const SEGMENTS = [
        self::SEGMENT_TOUS => 'Tous les clients',
        'particuliers' => 'Particuliers',
        'entreprises' => 'Entreprises et institutions',
        'nouveaux' => 'Nouveaux clients (aucune commande avant)',
        'actifs' => 'Clients actifs (achat sur 12 mois)',
        'inactifs' => 'Inactifs depuis 90 jours',
        self::SEGMENT_FIDELITE => 'Fidélité : seuil franchi',
        'gros' => 'Gros clients (500 000 F et plus sur 12 mois)',
        self::SEGMENT_LISTE => 'Liste choisie à la main',
    ];

    /*
     * Avantage sur toute la commande (docs/promotions.md, « Codes promo ») :
     * il ne passe que par un code généré (CodePromo) et s'ajoute aux
     * promotions des lignes, sur les lignes qui n'en ont pas.
     */
    public const COMMANDE_POURCENTAGE = 'pourcentage';
    public const COMMANDE_MONTANT = 'montant';

    public const AVANTAGES_COMMANDE = [
        self::COMMANDE_POURCENTAGE => 'Pourcentage',
        self::COMMANDE_MONTANT => 'Montant en F',
    ];

    public const ORIGINE_MANUELLE = 'manuelle';
    public const ORIGINE_ANNIVERSAIRE = 'anniversaire';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Vu par le client (devis, facture, espace client) : pas de segment dans le nom. */
    #[ORM\Column(length: 120)]
    private string $nom = '';

    /** @var Collection<int, Produits> */
    #[ORM\ManyToMany(targetEntity: Produits::class)]
    #[ORM\JoinTable(name: 'promotion_produit')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $produits;

    /** Toute une catégorie (en plus des produits choisis). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CategorieProduit $categorie = null;

    #[ORM\Column(length: 12)]
    private string $avantage = self::AVANTAGE_POURCENTAGE;

    /** Pourcentage, F par unité ou prix fixe par unité, selon l'avantage. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $valeur = '0';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    /** Dernier jour (compris). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $fin;

    #[ORM\Column(length: 20)]
    private string $segment = self::SEGMENT_TOUS;

    /** @var Collection<int, Clients> clients de la liste choisie à la main */
    #[ORM\ManyToMany(targetEntity: Clients::class)]
    #[ORM\JoinTable(name: 'promotion_client')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $clients;

    /** Quantité minimale de la ligne. */
    #[ORM\Column(nullable: true)]
    private ?int $quantiteMin = null;

    /** Montant minimal de la ligne avant remise (F). */
    #[ORM\Column(nullable: true)]
    private ?int $montantMin = null;

    /** Code à donner à la saisie (facultatif), en majuscules. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $code = null;

    /** Nombre de commandes par client ; vide = sans limite. */
    #[ORM\Column(nullable: true)]
    private ?int $utilisationsParClient = null;

    /** Avantage sur la commande (avec un code généré) : pourcentage ou montant ; vide = aucun. */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $avantageCommande = null;

    /** Pourcentage (90 % au plus) ou montant en F (hors TVA) sur la commande. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $valeurCommande = '0';

    /** Pourcentage : remise de la commande au plus (F, hors TVA). */
    #[ORM\Column(nullable: true)]
    private ?int $plafondCommande = null;

    /** Commande d'au moins (F, hors TVA, après les remises des lignes). */
    #[ORM\Column(nullable: true)]
    private ?int $minimumCommande = null;

    /** Des codes ont été générés (CodePromo) : la promotion ne s'applique plus qu'avec l'un d'eux. */
    #[ORM\Column(options: ['default' => false])]
    private bool $aCodes = false;

    /** Suspendue : ne s'applique plus, les lignes déjà faites la gardent. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 12, options: ['default' => self::ORIGINE_MANUELLE])]
    private string $origine = self::ORIGINE_MANUELLE;

    /** Note interne, jamais montrée au client. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->produits = new ArrayCollection();
        $this->clients = new ArrayCollection();
        $this->debut = new \DateTimeImmutable('today');
        $this->fin = $this->debut->modify('+14 days');
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = mb_substr(trim($nom), 0, 120); return $this; }

    /** @return Collection<int, Produits> */
    public function getProduits(): Collection { return $this->produits; }

    public function addProduit(Produits $produit): static
    {
        if (!$this->produits->contains($produit)) {
            $this->produits->add($produit);
        }

        return $this;
    }

    /** @param iterable<Produits> $produits */
    public function remplacerProduits(iterable $produits): static
    {
        $this->produits->clear();
        foreach ($produits as $p) {
            $this->addProduit($p);
        }

        return $this;
    }

    public function getCategorie(): ?CategorieProduit { return $this->categorie; }
    public function setCategorie(?CategorieProduit $categorie): static { $this->categorie = $categorie; return $this; }
    public function getAvantage(): string { return $this->avantage; }
    public function setAvantage(string $avantage): static { $this->avantage = isset(self::AVANTAGES[$avantage]) ? $avantage : self::AVANTAGE_POURCENTAGE; return $this; }
    public function getValeur(): float { return (float) $this->valeur; }
    public function setValeur(float $valeur): static { $this->valeur = number_format(max(0, $valeur), 2, '.', ''); return $this; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function setDebut(\DateTimeImmutable $d): static { $this->debut = $d->setTime(0, 0); return $this; }
    public function getFin(): \DateTimeImmutable { return $this->fin; }
    public function setFin(\DateTimeImmutable $d): static { $this->fin = $d->setTime(0, 0); return $this; }
    public function getSegment(): string { return $this->segment; }
    public function setSegment(string $segment): static { $this->segment = isset(self::SEGMENTS[$segment]) ? $segment : self::SEGMENT_TOUS; return $this; }
    public function getSegmentLabel(): string { return self::SEGMENTS[$this->segment] ?? $this->segment; }

    /** @return Collection<int, Clients> */
    public function getClients(): Collection { return $this->clients; }

    public function addClient(Clients $client): static
    {
        if (!$this->clients->contains($client)) {
            $this->clients->add($client);
        }

        return $this;
    }

    /** @param iterable<Clients> $clients */
    public function remplacerClients(iterable $clients): static
    {
        $this->clients->clear();
        foreach ($clients as $c) {
            $this->addClient($c);
        }

        return $this;
    }

    public function getQuantiteMin(): ?int { return $this->quantiteMin; }
    public function setQuantiteMin(?int $q): static { $this->quantiteMin = null !== $q && $q > 1 ? $q : null; return $this; }
    public function getMontantMin(): ?int { return $this->montantMin; }
    public function setMontantMin(?int $m): static { $this->montantMin = null !== $m && $m > 0 ? $m : null; return $this; }
    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): static { $this->code = self::normaliserCode($code); return $this; }
    public function getUtilisationsParClient(): ?int { return $this->utilisationsParClient; }
    public function setUtilisationsParClient(?int $n): static { $this->utilisationsParClient = null !== $n && $n > 0 ? $n : null; return $this; }
    public function getAvantageCommande(): ?string { return $this->avantageCommande; }
    public function setAvantageCommande(?string $a): static { $this->avantageCommande = isset(self::AVANTAGES_COMMANDE[(string) $a]) ? $a : null; return $this; }
    public function getValeurCommande(): float { return (float) $this->valeurCommande; }
    public function setValeurCommande(float $v): static { $this->valeurCommande = number_format(max(0, $v), 2, '.', ''); return $this; }
    public function getPlafondCommande(): ?int { return $this->plafondCommande; }
    public function setPlafondCommande(?int $m): static { $this->plafondCommande = null !== $m && $m > 0 ? $m : null; return $this; }
    public function getMinimumCommande(): ?int { return $this->minimumCommande; }
    public function setMinimumCommande(?int $m): static { $this->minimumCommande = null !== $m && $m > 0 ? $m : null; return $this; }
    public function hasCodes(): bool { return $this->aCodes; }
    public function marquerACodes(): static { $this->aCodes = true; return $this; }
    public function setACodes(bool $a): static { $this->aCodes = $a; return $this; }

    /** Elle ne s'applique qu'avec un code généré : choisie ainsi, des codes générés, ou un avantage sur la commande. */
    public function exigeCodeGenere(): bool
    {
        return $this->aCodes || $this->aUnAvantageCommande();
    }

    /** Un avantage sur toute la commande, donné par un code généré. */
    public function aUnAvantageCommande(): bool
    {
        return null !== $this->avantageCommande && $this->getValeurCommande() > 0;
    }

    /** Une remise sur des articles (produits ou catégorie). */
    public function aUnAvantageLignes(): bool
    {
        return $this->getValeur() > 0 && (!$this->produits->isEmpty() || null !== $this->categorie);
    }

    /** Sans aucun code (ni code simple, ni codes générés) : elle s'applique toute seule et se montre au client. */
    public function estSansCode(): bool
    {
        return null === $this->code && !$this->exigeCodeGenere();
    }

    /** « −10 % sur la commande (au plus 20 000 F) », « −5 000 F sur la commande ». */
    public function getLibelleAvantageCommande(): ?string
    {
        if (!$this->aUnAvantageCommande()) {
            return null;
        }
        $nombre = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
        if (self::COMMANDE_MONTANT === $this->avantageCommande) {
            return '−'.$nombre($this->getValeurCommande()).' F sur la commande';
        }

        return '−'.$nombre($this->getValeurCommande()).' % sur la commande'.(null !== $this->plafondCommande ? ' (au plus '.$nombre($this->plafondCommande).' F)' : '');
    }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
    public function getOrigine(): string { return $this->origine; }
    public function setOrigine(string $origine): static { $this->origine = self::ORIGINE_ANNIVERSAIRE === $origine ? $origine : self::ORIGINE_MANUELLE; return $this; }
    public function estAnniversaire(): bool { return self::ORIGINE_ANNIVERSAIRE === $this->origine; }

    /** Synchronisation : en ligne, une promotion à code reste éteinte (le code ne quitte pas l'imprimerie). */
    public static function activeEnLigne(?bool $active, self $p): bool
    {
        return (bool) $active && null === $p->code;
    }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = null !== $note && '' !== trim($note) ? mb_substr(trim($note), 0, 255) : null; return $this; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function setCreePar(?User $u): static { $this->creePar = $u; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getModifiePar(): ?User { return $this->modifiePar; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }

    public function marquerModifiee(?User $par, \DateTimeImmutable $le): static
    {
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }

    /** Code saisi au comptoir ou enregistré : majuscules, sans espaces. */
    public static function normaliserCode(?string $code): ?string
    {
        $code = mb_strtoupper(preg_replace('/\s+/', '', (string) $code) ?? '');

        return '' === $code ? null : mb_substr($code, 0, 30);
    }

    /** L'article est visé : produit choisi, ou produit de la catégorie. */
    public function vise(?Produits $produit): bool
    {
        if (null === $produit) {
            return false;
        }
        foreach ($this->produits as $p) {
            if ($p === $produit || (null !== $p->getId() && $p->getId() === $produit->getId())) {
                return true;
            }
        }
        $categorie = $produit->getCategorieProduit();

        return null !== $this->categorie && null !== $categorie
            && ($categorie === $this->categorie || (null !== $categorie->getId() && $categorie->getId() === $this->categorie->getId()));
    }

    public function couvre(\DateTimeInterface $jour): bool
    {
        $j = $jour->format('Y-m-d');

        return $this->debut->format('Y-m-d') <= $j && $j <= $this->fin->format('Y-m-d');
    }

    public function contientClient(?Clients $client): bool
    {
        if (null === $client) {
            return false;
        }
        foreach ($this->clients as $c) {
            if ($c === $client || (null !== $c->getId() && $c->getId() === $client->getId())) {
                return true;
            }
        }

        return false;
    }

    /** suspendue, a_venir, en_cours ou terminee. */
    public function etat(\DateTimeInterface $jour): string
    {
        return match (true) {
            !$this->active => 'suspendue',
            $jour->format('Y-m-d') < $this->debut->format('Y-m-d') => 'a_venir',
            $jour->format('Y-m-d') > $this->fin->format('Y-m-d') => 'terminee',
            default => 'en_cours',
        };
    }

    /** « −15 % », « −1 000 F par unité », « 5 000 F par unité ». */
    public function getLibelleAvantage(): string
    {
        return self::formaterAvantage($this->avantage, $this->getValeur());
    }

    public static function formaterAvantage(string $avantage, float $valeur): string
    {
        $nombre = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');

        return match ($avantage) {
            self::AVANTAGE_MONTANT => '−'.$nombre($valeur).' F par unité',
            self::AVANTAGE_PRIX_FIXE => 'prix fixe '.$nombre($valeur).' F par unité',
            default => '−'.$nombre($valeur).' %',
        };
    }

    /** Libellé gardé sur la ligne : nom et avantage du jour. */
    public function libelleLigne(): string
    {
        return mb_substr($this->nom.' ('.$this->getLibelleAvantage().')', 0, 160);
    }

    /** Articles visés, pour l'écran et l'espace client. */
    public function getLibelleArticles(): string
    {
        $noms = array_map(static fn (Produits $p): string => (string) $p->getNom(), $this->produits->toArray());
        if (null !== $this->categorie) {
            array_unshift($noms, 'toute la catégorie '.$this->categorie->getNom());
        }

        return [] === $noms ? '—' : implode(', ', $noms);
    }

    /**
     * Pour le journal d'activité.
     *
     * @return array<string, mixed>
     */
    public function instantane(): array
    {
        return [
            'nom' => $this->nom,
            'articles' => $this->getLibelleArticles(),
            'avantage' => $this->getLibelleAvantage(),
            'du' => $this->debut->format('Y-m-d'),
            'au' => $this->fin->format('Y-m-d'),
            'segment' => $this->getSegmentLabel(),
            'clients' => self::SEGMENT_LISTE === $this->segment ? \count($this->clients) : null,
            'quantite_min' => $this->quantiteMin,
            'montant_min' => $this->montantMin,
            'code' => null !== $this->code,
            'utilisations' => $this->utilisationsParClient,
            'avantage_commande' => $this->getLibelleAvantageCommande(),
            'minimum_commande' => $this->minimumCommande,
            'codes_generes' => $this->aCodes,
            'active' => $this->active,
        ];
    }
}
