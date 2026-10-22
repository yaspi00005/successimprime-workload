<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use App\Repository\ImmobilisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Bien du patrimoine (maquettes M03 et M04) : machine de production,
 * ordinateur, chaise, moto, climatiseur, groupe électrogène… avec son
 * code IMM-0001, sa catégorie, son lieu, à qui il est confié et son état.
 *
 * Une machine de production est reliée à sa fiche Machines : prix
 * d'achat, mise en service et valeur résiduelle viennent alors de la
 * machine, et elle s'amortit à l'usage. Les autres biens s'amortissent
 * en linéaire sur leur durée. Voir App\Service\Immobilisations\Patrimoine
 * et docs/immobilisations-primes.md. Table locale, non synchronisée.
 */
#[ORM\Entity(repositoryClass: ImmobilisationRepository::class)]
#[ORM\Index(name: 'idx_immobilisation_categorie', columns: ['categorie'])]
class Immobilisation implements AppartientASociete
{
    use SocieteTrait;

    /**
     * Catégories : libellé, libellé court (registre), compte d'immobilisation
     * et compte d'amortissement SYSCOHADA, durée par défaut en années,
     * icône. Les sous-comptes restent à valider avec le cabinet.
     */
    public const CATEGORIES = [
        'machine' => ['libelle' => 'Machines de production', 'court' => 'Machine', 'compte' => '2411', 'amortissement' => '2841', 'duree' => 5, 'icone' => 'fa-print', 'couleur' => '#1d4ed8'],
        'informatique' => ['libelle' => 'Ordinateurs et tablettes', 'court' => 'Informatique', 'compte' => '2442', 'amortissement' => '2844', 'duree' => 3, 'icone' => 'fa-laptop', 'couleur' => '#0ea5e9'],
        'chaise' => ['libelle' => 'Chaises', 'court' => 'Mobilier', 'compte' => '2444', 'amortissement' => '2844', 'duree' => 5, 'icone' => 'fa-chair', 'couleur' => '#f97316'],
        'table' => ['libelle' => 'Tables et bureaux', 'court' => 'Mobilier', 'compte' => '2444', 'amortissement' => '2844', 'duree' => 10, 'icone' => 'fa-table', 'couleur' => '#ec4899'],
        'vehicule' => ['libelle' => 'Motos et tricycle', 'court' => 'Véhicule', 'compte' => '2451', 'amortissement' => '2845', 'duree' => 4, 'icone' => 'fa-motorcycle', 'couleur' => '#dc2626'],
        'climatiseur' => ['libelle' => 'Climatiseurs', 'court' => 'Climatisation', 'compte' => '2341', 'amortissement' => '2834', 'duree' => 5, 'icone' => 'fa-snowflake', 'couleur' => '#60a5fa'],
        'energie' => ['libelle' => 'Groupe électrogène et onduleurs', 'court' => 'Énergie', 'compte' => '2341', 'amortissement' => '2834', 'duree' => 5, 'icone' => 'fa-bolt', 'couleur' => '#eab308'],
        'audiovisuel' => ['libelle' => 'Écran TV et caméras', 'court' => 'Audiovisuel', 'compte' => '2441', 'amortissement' => '2844', 'duree' => 5, 'icone' => 'fa-tv', 'couleur' => '#64748b'],
        'logiciel' => ['libelle' => 'Logiciels', 'court' => 'Logiciel', 'compte' => '2131', 'amortissement' => '2813', 'duree' => 3, 'icone' => 'fa-code', 'couleur' => '#7c3aed'],
    ];

    /** Immobilisation incorporelle : suivie à part, hors « valeur d'achat totale » (M03). */
    public const INCORPORELLES = ['logiciel'];

    public const ETATS = [
        'neuf' => ['Neuf', 'bleu'],
        'bon' => ['Bon', 'vert'],
        'revision' => ['Révision', 'orange'],
        'abime' => ['Abîmé', 'orange'],
        'hors_service' => ['Hors service', 'rouge'],
    ];

    public const SORTIES = ['vente' => 'Vente', 'rebut' => 'Mise au rebut', 'vol' => 'Vol ou perte'];

    /** Lieux proposés à la saisie (texte libre accepté). */
    public const LIEUX = ['Atelier', 'Accueil et comptoir', 'Bureau direction', 'Livraison', 'Cour'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** IMM-0001, attribué à la création et jamais réutilisé. */
    #[ORM\Column(length: 12, unique: true)]
    private string $code;

    #[ORM\Column(length: 150)]
    private string $designation;

    #[ORM\Column(length: 20)]
    private string $categorie;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateAchat = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateMiseService = null;

    /** F ; pour une machine, son prix d'achat fait foi. */
    #[ORM\Column]
    private int $valeurAchat = 0;

    #[ORM\Column]
    private int $valeurResiduelle = 0;

    /** Durée d'amortissement linéaire, en années. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $dureeAnnees = null;

    /** Nombre d'unités d'un lot (« Lot de 22 chaises »). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $quantite = 1;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: true, unique: true, onDelete: 'SET NULL')]
    private ?Machines $machine = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confieA = null;

    #[ORM\Column(length: 15)]
    private string $etat = 'bon';

    /** Précision sur l'état (« 2 abîmées »). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $etatNote = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fournisseur = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $numeroSerie = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Dernier contrôle physique (inventaire). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $controleLe = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sortieLe = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $sortieMotif = null;

    /** Prix de vente du bien sorti (0 pour un rebut ou un vol). */
    #[ORM\Column(nullable: true)]
    private ?int $prixCession = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(string $code, string $designation, string $categorie)
    {
        $this->code = $code;
        $this->designation = $designation;
        $this->setCategorie($categorie);
        $this->creeLe = new \DateTimeImmutable();
        $this->modifieLe = $this->creeLe;
    }

    /** « IMM-0007 ». */
    public static function codePour(int $numero): string
    {
        return sprintf('IMM-%04d', $numero);
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getDesignation(): string { return $this->designation; }
    public function getCategorie(): string { return $this->categorie; }
    public function getLieu(): ?string { return $this->lieu; }
    public function getDateAchat(): ?\DateTimeImmutable { return $this->dateAchat; }
    public function getValeurResiduelleSaisie(): int { return $this->valeurResiduelle; }
    public function getDureeAnnees(): ?int { return $this->dureeAnnees; }
    public function getQuantite(): int { return $this->quantite; }
    public function getMachine(): ?Machines { return $this->machine; }
    public function getConfieA(): ?User { return $this->confieA; }
    public function getEtat(): string { return $this->etat; }
    public function getEtatNote(): ?string { return $this->etatNote; }
    public function getFournisseur(): ?string { return $this->fournisseur; }
    public function getNumeroSerie(): ?string { return $this->numeroSerie; }
    public function getNote(): ?string { return $this->note; }
    public function getControleLe(): ?\DateTimeImmutable { return $this->controleLe; }
    public function getSortieLe(): ?\DateTimeImmutable { return $this->sortieLe; }
    public function getSortieMotif(): ?string { return $this->sortieMotif; }
    public function getPrixCession(): ?int { return $this->prixCession; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }

    public function setDesignation(string $designation): static
    {
        $this->designation = mb_substr(trim($designation), 0, 150);

        return $this;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = isset(self::CATEGORIES[$categorie]) ? $categorie : 'informatique';

        return $this;
    }

    public function setLieu(?string $lieu): static
    {
        $lieu = null !== $lieu ? trim($lieu) : '';
        $this->lieu = '' !== $lieu ? mb_substr($lieu, 0, 80) : null;

        return $this;
    }

    public function setDateAchat(?\DateTimeImmutable $date): static
    {
        $this->dateAchat = $date;

        return $this;
    }

    public function setDateMiseService(?\DateTimeImmutable $date): static
    {
        $this->dateMiseService = $date;

        return $this;
    }

    public function setValeurAchat(int $valeur): static
    {
        $this->valeurAchat = max(0, $valeur);

        return $this;
    }

    public function setValeurResiduelle(int $valeur): static
    {
        $this->valeurResiduelle = max(0, $valeur);

        return $this;
    }

    public function setDureeAnnees(?int $annees): static
    {
        $this->dureeAnnees = null !== $annees && $annees > 0 ? min(50, $annees) : null;

        return $this;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(1, min(9999, $quantite));

        return $this;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function setConfieA(?User $u): static
    {
        $this->confieA = $u;

        return $this;
    }

    public function setEtat(string $etat, ?string $note = null): static
    {
        $this->etat = isset(self::ETATS[$etat]) ? $etat : 'bon';
        $note = null !== $note ? trim($note) : '';
        $this->etatNote = '' !== $note ? mb_substr($note, 0, 80) : null;

        return $this;
    }

    public function setFournisseur(?string $fournisseur): static
    {
        $this->fournisseur = null !== $fournisseur && '' !== trim($fournisseur) ? mb_substr(trim($fournisseur), 0, 120) : null;

        return $this;
    }

    public function setNumeroSerie(?string $numero): static
    {
        $this->numeroSerie = null !== $numero && '' !== trim($numero) ? mb_substr(trim($numero), 0, 80) : null;

        return $this;
    }

    public function setNote(?string $note): static
    {
        $this->note = null !== $note && '' !== trim($note) ? trim($note) : null;

        return $this;
    }

    public function controler(\DateTimeImmutable $le): static
    {
        $this->controleLe = $le;

        return $this;
    }

    /**
     * Sortie du patrimoine (vente, rebut, vol) : l'amortissement s'arrête
     * au mois de sortie.
     */
    public function sortir(\DateTimeImmutable $le, string $motif, int $prix): static
    {
        $this->sortieLe = $le;
        $this->sortieMotif = isset(self::SORTIES[$motif]) ? $motif : 'rebut';
        $this->prixCession = 'vente' === $this->sortieMotif ? max(0, $prix) : 0;

        return $this;
    }

    public function estSorti(): bool
    {
        return null !== $this->sortieLe;
    }

    public function touche(\DateTimeImmutable $le): static
    {
        $this->modifieLe = $le;

        return $this;
    }

    /** Valeur d'achat : celle de la machine reliée, sinon la valeur saisie. */
    public function getValeurAchat(): int
    {
        return null !== $this->machine && null !== $this->machine->getPrixAchat() ? (int) $this->machine->getPrixAchat() : $this->valeurAchat;
    }

    public function getValeurResiduelle(): int
    {
        return null !== $this->machine ? (int) ($this->machine->getValeurResiduelle() ?? 0) : $this->valeurResiduelle;
    }

    /** Mise en service : celle de la machine, sinon celle saisie, sinon l'achat. */
    public function getDateMiseService(): ?\DateTimeImmutable
    {
        $machine = $this->machine?->getDateMiseService() ?? $this->machine?->getDateAchat();
        if (null !== $machine) {
            return \DateTimeImmutable::createFromInterface($machine);
        }

        return $this->dateMiseService ?? $this->dateAchat;
    }

    /** Durée linéaire en mois : la durée saisie, celle de la machine, ou celle de la catégorie. */
    public function dureeMois(): int
    {
        if (null !== $this->machine && null === $this->dureeAnnees && null !== $this->machine->getDureeAmortissementMois()) {
            return max(1, (int) $this->machine->getDureeAmortissementMois());
        }

        return 12 * ($this->dureeAnnees ?? self::CATEGORIES[$this->categorie]['duree']);
    }

    /** Montant à amortir : valeur d'achat moins valeur résiduelle. */
    public function baseAmortissable(): int
    {
        return max(0, $this->getValeurAchat() - $this->getValeurResiduelle());
    }

    public function libelleCategorie(): string
    {
        return self::CATEGORIES[$this->categorie]['libelle'];
    }

    public function libelleCourt(): string
    {
        return self::CATEGORIES[$this->categorie]['court'];
    }

    public function compteImmobilisation(): string
    {
        return self::CATEGORIES[$this->categorie]['compte'];
    }

    public function compteAmortissement(): string
    {
        return self::CATEGORIES[$this->categorie]['amortissement'];
    }

    public function libelleEtat(): string
    {
        return $this->etatNote ?? self::ETATS[$this->etat][0];
    }

    public function couleurEtat(): string
    {
        return self::ETATS[$this->etat][1];
    }

    public function estIncorporelle(): bool
    {
        return \in_array($this->categorie, self::INCORPORELLES, true);
    }
}
