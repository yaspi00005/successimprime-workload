<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dépense saisie dans Finances › Dépenses (maquette F03), toujours avec
 * la photo du reçu ou de la facture.
 *
 * Au-delà du seuil des Réglages (100 000 F par défaut), la dépense attend
 * l'accord de la direction (ROLE_ADMIN) avant d'être payée. Le paiement
 * crée un décaissement en attente : il n'entre dans les soldes qu'une
 * fois validé dans « À valider », sur l'ERP local.
 *
 * Table locale, non synchronisée avec le site en ligne.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_depense_date', columns: ['date_depense'])]
#[ORM\Index(name: 'idx_depense_statut', columns: ['statut'])]
class Depense implements AppartientASociete
{
    use SocieteTrait;

    public const A_APPROUVER = 'a_approuver';
    public const A_PAYER = 'a_payer';
    /** Payée sur place à la saisie (reçu à l'appui). */
    public const VALIDEE = 'validee';
    /** Payée après coup (facture « à payer »). */
    public const PAYEE = 'payee';
    public const REFUSEE = 'refusee';

    /** Statut => [libellé, couleur de pastille]. */
    public const STATUTS = [
        self::A_APPROUVER => ['À approuver', 'orange'],
        self::A_PAYER => ['À payer', 'bleu'],
        self::VALIDEE => ['Validée', 'vert'],
        self::PAYEE => ['Payée', 'vert'],
        self::REFUSEE => ['Refusée', 'rouge'],
    ];

    /** Dépenses engagées : elles comptent dans le budget du mois. */
    public const ENGAGEES = [self::A_PAYER, self::VALIDEE, self::PAYEE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $objet = '';

    /** Catégorie de budget (App\Service\Finances\Budgets::CATEGORIES). */
    #[ORM\Column(length: 20)]
    private string $categorie = 'divers';

    #[ORM\Column]
    private int $montant = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDepense;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Fournisseurs $fournisseur = null;

    /** Payé à, quand ce n'est pas un fournisseur de la liste (« Station Bakary »). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $beneficiaire = null;

    /** « Payé avec » : le compte d'où part l'argent. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteTresorerie $compte = null;

    /** « Lié à » : commande, tournée, machine… en clair. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieA = null;

    /** Article acheté (matières) : stock et dernier prix d'achat affichés à l'approbation. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Articles $article = null;

    /*
     * Facture fournisseur créée à la réception d'une commande d'achat
     * (maquette SU05) : son paiement est refusé tant que la commande a
     * son paiement bloqué.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Achats $achat = null;

    /** Quantité achetée, dans l'unité de l'article. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, nullable: true)]
    private ?string $quantite = null;

    /** Date limite de paiement d'une dépense à payer. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $echeance = null;

    /** Chemin du reçu sous var/uploads/depenses : AAAA/MM/<32 hexa>.<ext>. */
    #[ORM\Column(length: 60)]
    private string $justificatif = '';

    #[ORM\Column(length: 20)]
    private string $statut = self::A_PAYER;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approuveeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approuveePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRefus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $payeeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $payeePar = null;

    /** Décaissement préparé au paiement (en attente jusqu'à sa validation). */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MouvementTresorerie $mouvement = null;

    /*
     * Dette fournisseur reprise au démarrage (docs/import-donnees.md) : elle
     * est à payer comme une autre, mais ce n'est pas une dépense du mois.
     * Elle n'entre ni dans les budgets ni dans les dépenses de la période.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $soldeOuverture = false;

    public function __construct(?User $par = null, ?\DateTimeImmutable $le = null)
    {
        $this->creeLe = $le ?? new \DateTimeImmutable();
        $this->dateDepense = $this->creeLe->setTime(0, 0);
        $this->creePar = $par;
    }

    public function libelleStatut(): string
    {
        if (self::A_PAYER === $this->statut && null !== $this->echeance) {
            return 'À payer le '.$this->echeance->format('d/m');
        }

        return self::STATUTS[$this->statut][0] ?? $this->statut;
    }

    public function couleurStatut(): string { return self::STATUTS[$this->statut][1] ?? 'gris'; }
    public function estEngagee(): bool { return \in_array($this->statut, self::ENGAGEES, true); }
    public function estPayee(): bool { return \in_array($this->statut, [self::VALIDEE, self::PAYEE], true); }
    /** Payé à : le fournisseur de la liste, sinon le nom saisi. */
    public function getPayeA(): string { return $this->fournisseur?->getNom() ?? (string) $this->beneficiaire; }

    public function getId(): ?int { return $this->id; }
    public function getObjet(): string { return $this->objet; }
    public function setObjet(?string $o): static { $this->objet = mb_substr(trim((string) $o), 0, 150); return $this; }
    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(?string $c): static { $this->categorie = (string) $c; return $this; }
    public function getMontant(): int { return $this->montant; }
    public function setMontant(?int $m): static { $this->montant = max(0, (int) $m); return $this; }
    public function getDateDepense(): \DateTimeImmutable { return $this->dateDepense; }
    public function setDateDepense(?\DateTimeImmutable $d): static { $this->dateDepense = ($d ?? $this->creeLe)->setTime(0, 0); return $this; }
    public function getFournisseur(): ?Fournisseurs { return $this->fournisseur; }
    public function setFournisseur(?Fournisseurs $f): static { $this->fournisseur = $f; return $this; }
    public function getBeneficiaire(): ?string { return $this->beneficiaire; }
    public function setBeneficiaire(?string $b): static { $b = trim((string) $b); $this->beneficiaire = '' === $b ? null : mb_substr($b, 0, 100); return $this; }
    public function getCompte(): ?CompteTresorerie { return $this->compte; }
    public function setCompte(?CompteTresorerie $c): static { $this->compte = $c; return $this; }
    public function getLieA(): ?string { return $this->lieA; }
    public function setLieA(?string $l): static { $l = trim((string) $l); $this->lieA = '' === $l ? null : mb_substr($l, 0, 120); return $this; }
    public function getArticle(): ?Articles { return $this->article; }
    public function setArticle(?Articles $a): static { $this->article = $a; return $this; }
    public function getAchat(): ?Achats { return $this->achat; }
    public function setAchat(?Achats $a): static { $this->achat = $a; return $this; }
    public function getQuantite(): ?float { return null === $this->quantite ? null : (float) $this->quantite; }
    public function setQuantite(?float $q): static { $this->quantite = null === $q || $q <= 0 ? null : number_format($q, 3, '.', ''); return $this; }
    public function getEcheance(): ?\DateTimeImmutable { return $this->echeance; }
    public function setEcheance(?\DateTimeImmutable $e): static { $this->echeance = $e?->setTime(0, 0); return $this; }
    public function getJustificatif(): string { return $this->justificatif; }
    public function setJustificatif(string $j): static { $this->justificatif = $j; return $this; }
    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): static { $this->statut = isset(self::STATUTS[$s]) ? $s : self::A_PAYER; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $n): static { $n = trim((string) $n); $this->note = '' === $n ? null : $n; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function getApprouveeLe(): ?\DateTimeImmutable { return $this->approuveeLe; }
    public function getApprouveePar(): ?User { return $this->approuveePar; }
    public function approuver(User $par, \DateTimeImmutable $le): static { $this->approuveePar = $par; $this->approuveeLe = $le; return $this; }
    public function getMotifRefus(): ?string { return $this->motifRefus; }
    public function refuser(User $par, string $motif, \DateTimeImmutable $le): static { $this->statut = self::REFUSEE; $this->motifRefus = mb_substr(trim($motif), 0, 255); $this->approuveePar = $par; $this->approuveeLe = $le; return $this; }
    public function getPayeeLe(): ?\DateTimeImmutable { return $this->payeeLe; }
    public function getPayeePar(): ?User { return $this->payeePar; }
    public function payer(string $statut, User $par, \DateTimeImmutable $le, MouvementTresorerie $mouvement): static { $this->statut = $statut; $this->payeePar = $par; $this->payeeLe = $le; $this->mouvement = $mouvement; $this->compte = $mouvement->getCompteSource(); return $this; }
    public function getMouvement(): ?MouvementTresorerie { return $this->mouvement; }

    public function isSoldeOuverture(): bool { return $this->soldeOuverture; }

    /** Dette fournisseur du démarrage : à payer, sans reçu (le fichier d'import en tient lieu). */
    public function marquerSoldeOuverture(): static
    {
        $this->soldeOuverture = true;
        $this->statut = self::A_PAYER;

        return $this;
    }
}
