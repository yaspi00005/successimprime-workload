<?php

namespace App\Entity;

use App\Repository\MachinesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MachinesRepository::class)]
class Machines
{
    /*
     * ============================================================
     * MODES DE FACTURATION (RENTABILITÉ)
     * ============================================================
     *
     * metre_carre : machines facturées à la surface (largeur x
     * longueur), ex. traceurs / grand format.
     *
     * feuille : machines facturées à la feuille A4/A3, ex.
     * photocopieurs, impression de thèses/mémoires.
     */
    public const MODE_FACTURATION_METRE_CARRE = 'metre_carre';

    public const MODE_FACTURATION_FEUILLE = 'feuille';

    public const MODES_FACTURATION = [
        self::MODE_FACTURATION_METRE_CARRE,
        self::MODE_FACTURATION_FEUILLE,
    ];

    public const MODES_FACTURATION_LABELS = [
        self::MODE_FACTURATION_METRE_CARRE => 'Au mètre carré',
        self::MODE_FACTURATION_FEUILLE => 'À la feuille (A4/A3)',
    ];

    /*
     * Unités d'usage de l'amortissement (docs/immobilisations-primes.md,
     * maquette M01) : code => [unité courte, libellé de la colonne].
     * Sans unité choisie, m² pour le grand format et page A4 à la feuille.
     */
    public const UNITE_M2 = 'm2';
    public const UNITE_METRE = 'metre';
    public const UNITE_PAGE = 'page';
    public const UNITE_CARTE = 'carte';
    public const UNITE_TRANSFERT = 'transfert';

    public const UNITES = [
        self::UNITE_M2 => ['m²', 'm² imprimés'],
        self::UNITE_METRE => ['m', 'm de film'],
        self::UNITE_PAGE => ['page A4', 'pages (A3 = 2 A4)'],
        self::UNITE_CARTE => ['carte', 'cartes'],
        self::UNITE_TRANSFERT => ['transfert', 'transferts'],
    ];

    /*
     * Relevé mensuel du compteur de la machine (maquette M02) : sans
     * relevé, l'usage vient de la production terminée ; « simple », un
     * compteur ; « a4a3 », quatre compteurs A4 / A3 en couleur et en noir
     * et blanc (photocopieur Canon), une A3 comptant pour 2 A4.
     */
    public const RELEVE_SIMPLE = 'simple';
    public const RELEVE_A4A3 = 'a4a3';

    public const RELEVES = [
        self::RELEVE_SIMPLE => 'Un compteur',
        self::RELEVE_A4A3 => 'Compteurs A4 / A3, couleur et noir',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(length: 100)]
    private ?string $marque = null;

    #[ORM\Column(length: 100)]
    private ?string $modeles = null;

    #[ORM\Column(length: 100)]
    private ?string $numeroSerie = null;

    #[ORM\Column(length: 100)]
    private ?string $typeMachine = null;

    #[ORM\Column(length: 10)]
    private ?string $largeurImpression = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $nbTetes = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $dateAchat = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $DateMiseService = null;

    /*
     * En m² decimaux (Types::FLOAT) et non plus en entier : un
     * arrondi a chaque impression individuelle ferait disparaitre
     * tous les petits travaux (moins de 0,5 m²) avant meme qu'ils
     * ne s'additionnent. Voir enregistrerUsage().
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $compteurM2 = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $compteurHeures = null;

    /*
     * Compteur en feuilles A4-équivalent (une A3 compte pour 2 A4),
     * pour les machines facturées à la feuille. Distinct de
     * compteurM2, réservé aux machines grand format.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $compteurFeuilles = 0;

    #[ORM\Column(length: 20)]
    private ?string $etat = null;

    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $numeroMachine = null;

    #[ORM\Column(length: 45, unique: true)]
    private ?string $adresseIp = null;

    /** Entretien régulier à faire, en texte libre (maquette 27). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $planPreventif = null;

    /** Amortissement à l'usage : capacité prévue sur la vie de la machine (m² ou pages A4). */
    #[ORM\Column(nullable: true)]
    private ?float $capaciteUsage = null;

    /** Amortissement à l'usage : valeur résiduelle en fin de vie (F). */
    #[ORM\Column(nullable: true)]
    private ?int $valeurResiduelle = null;

    /** Unité d'usage de l'amortissement (UNITES), null : celle du mode de facturation. */
    #[ORM\Column(name: 'unite_usage', length: 10, nullable: true)]
    private ?string $unite = null;

    /** Compteur des machines comptées à l'unité (cartes PVC, transferts de sublimation). */
    #[ORM\Column(options: ['default' => 0])]
    private int $compteurUnites = 0;

    /** Relevé mensuel du compteur (RELEVES), null : usage compté sur la production. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $releveCompteurs = null;

    /** Toner par page A4 (F), pour le coût complet par page de la fiche (M02). */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $coutPageCouleur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $coutPageNoir = null;

    /** Montage d'impression : marge non imprimable de la machine (mm). */
    #[ORM\Column(nullable: true)]
    private ?float $margeMontageMm = null;

    /** Montage d'impression : feuilles acceptées, ex. « A4,A3,SRA3 ». */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $formatsMontage = null;

    /*
     * ============================================================
     * RENTABILITÉ / AMORTISSEMENT
     * ============================================================
     */

    #[ORM\Column(length: 20, options: ['default' => self::MODE_FACTURATION_METRE_CARRE])]
    private string $modeFacturation = self::MODE_FACTURATION_METRE_CARRE;

    #[ORM\Column(nullable: true)]
    private ?int $prixAchat = null;

    #[ORM\Column(nullable: true)]
    private ?int $dureeAmortissementMois = null;

    /*
     * Donnees du cout de revient (CalculateurCoutRevient) : vitesse
     * reelle de production, cout d'une heure de machine (amortissement,
     * electricite, operateur) et cout de l'encre par m2 imprime.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $vitesseM2Heure = null;

    #[ORM\Column(nullable: true)]
    private ?int $coutHoraire = null;

    #[ORM\Column(nullable: true)]
    private ?int $coutEncreM2 = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $revenuAvantSuivi = 0;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateDebutSuivi = null;

    /**
     * @var Collection<int, CommandesDetails>
     */
    #[ORM\OneToMany(targetEntity: CommandesDetails::class, mappedBy: 'machine')]
    private Collection $commandesDetails;

    /**
     * @var Collection<int, Production>
     */
    #[ORM\OneToMany(targetEntity: Production::class, mappedBy: 'machine')]
    private Collection $productions;

    /**
     * @var Collection<int, Maintenance>
     */
    #[ORM\OneToMany(targetEntity: Maintenance::class, mappedBy: 'machine')]
    private Collection $maintenances;

    public function __construct()
    {
        $this->commandesDetails = new ArrayCollection();
        $this->productions = new ArrayCollection();
        $this->maintenances = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getMarque(): ?string
    {
        return $this->marque;
    }

    public function setMarque(string $marque): static
    {
        $this->marque = $marque;

        return $this;
    }

    public function getModeles(): ?string
    {
        return $this->modeles;
    }

    public function setModeles(string $modeles): static
    {
        $this->modeles = $modeles;

        return $this;
    }

    public function getNumeroSerie(): ?string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie;

        return $this;
    }

    public function getTypeMachine(): ?string
    {
        return $this->typeMachine;
    }

    public function setTypeMachine(string $typeMachine): static
    {
        $this->typeMachine = $typeMachine;

        return $this;
    }

    public function getLargeurImpression(): ?string
    {
        return $this->largeurImpression;
    }

    public function setLargeurImpression(string $largeurImpression): static
    {
        $this->largeurImpression = $largeurImpression;

        return $this;
    }

    public function getNbTetes(): ?int
    {
        return $this->nbTetes;
    }

    public function setNbTetes(int $nbTetes): static
    {
        $this->nbTetes = $nbTetes;

        return $this;
    }

    public function getDateAchat(): ?\DateTime
    {
        return $this->dateAchat;
    }

    public function setDateAchat(\DateTime $dateAchat): static
    {
        $this->dateAchat = $dateAchat;

        return $this;
    }

    public function getDateMiseService(): ?\DateTime
    {
        return $this->DateMiseService;
    }

    public function setDateMiseService(\DateTime $DateMiseService): static
    {
        $this->DateMiseService = $DateMiseService;

        return $this;
    }

    public function getCompteurM2(): ?float
    {
        return $this->compteurM2;
    }

    public function setCompteurM2(float $compteurM2): static
    {
        $this->compteurM2 = $compteurM2;

        return $this;
    }

    public function getCompteurHeures(): ?int
    {
        return $this->compteurHeures;
    }

    public function setCompteurHeures(int $compteurHeures): static
    {
        $this->compteurHeures = $compteurHeures;

        return $this;
    }

    public function getCompteurFeuilles(): int
    {
        return $this->compteurFeuilles;
    }

    public function setCompteurFeuilles(int $compteurFeuilles): static
    {
        $this->compteurFeuilles = $compteurFeuilles;

        return $this;
    }

    public function getEtat(): ?string
    {
        return $this->etat;
    }

    public function setEtat(string $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    /**
     * @return Collection<int, CommandesDetails>
     */
    public function getCommandesDetails(): Collection
    {
        return $this->commandesDetails;
    }

    public function addCommandesDetail(CommandesDetails $commandesDetail): static
    {
        if (!$this->commandesDetails->contains($commandesDetail)) {
            $this->commandesDetails->add($commandesDetail);
            $commandesDetail->setMachine($this);
        }

        return $this;
    }

    public function removeCommandesDetail(CommandesDetails $commandesDetail): static
    {
        if ($this->commandesDetails->removeElement($commandesDetail)) {
            // set the owning side to null (unless already changed)
            if ($commandesDetail->getMachine() === $this) {
                $commandesDetail->setMachine(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Production>
     */
    public function getProductions(): Collection
    {
        return $this->productions;
    }

    public function addProduction(Production $production): static
    {
        if (!$this->productions->contains($production)) {
            $this->productions->add($production);
            $production->setMachine($this);
        }

        return $this;
    }

    public function removeProduction(Production $production): static
    {
        if ($this->productions->removeElement($production)) {
            // set the owning side to null (unless already changed)
            if ($production->getMachine() === $this) {
                $production->setMachine(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Maintenance>
     */
    public function getMaintenances(): Collection
    {
        return $this->maintenances;
    }

    public function addMaintenance(Maintenance $maintenance): static
    {
        if (!$this->maintenances->contains($maintenance)) {
            $this->maintenances->add($maintenance);
            $maintenance->setMachine($this);
        }

        return $this;
    }

    public function removeMaintenance(Maintenance $maintenance): static
    {
        if ($this->maintenances->removeElement($maintenance)) {
            // set the owning side to null (unless already changed)
            if ($maintenance->getMachine() === $this) {
                $maintenance->setMachine(null);
            }
        }

        return $this;
    }

    public function getNumeroMachine(): ?string
    {
        return $this->numeroMachine;
    }

    public function setNumeroMachine(?string $numeroMachine): static
    {
        $this->numeroMachine = $numeroMachine;

        return $this;
    }

    

   public function getAdresseIp(): ?string
{
    return $this->adresseIp;
}

public function setAdresseIp(string $adresseIp): static
{
    $adresseIp = trim($adresseIp);

    if (!filter_var($adresseIp, FILTER_VALIDATE_IP)) {
        throw new \InvalidArgumentException(
            'L’adresse IP de la machine est invalide.'
        );
    }

    $this->adresseIp = $adresseIp;

    return $this;
}

    public function __toString(): string
    {
        return $this->nom ?? 'Machine';
    }

    /*
     * ============================================================
     * RENTABILITÉ / AMORTISSEMENT
     * ============================================================
     */

    public function getModeFacturation(): string
    {
        return $this->modeFacturation;
    }

    public function setModeFacturation(string $modeFacturation): static
    {
        if (!in_array($modeFacturation, self::MODES_FACTURATION, true)) {
            throw new \InvalidArgumentException(
                'Le mode de facturation de la machine est invalide.'
            );
        }

        $this->modeFacturation = $modeFacturation;

        return $this;
    }

    public function getModeFacturationLabel(): string
    {
        return self::MODES_FACTURATION_LABELS[$this->modeFacturation] ?? $this->modeFacturation;
    }

    public function utiliseSurface(): bool
    {
        return $this->modeFacturation === self::MODE_FACTURATION_METRE_CARRE;
    }

    public function utiliseFeuille(): bool
    {
        return $this->modeFacturation === self::MODE_FACTURATION_FEUILLE;
    }

    /*
     * Surface d'une feuille A4, en m² (0,21 x 0,297) — sert de
     * référence pour convertir une surface imprimée en nombre de
     * feuilles A4-équivalent (une A3 compte pour 2 A4).
     */
    private const SURFACE_A4_M2 = 0.21 * 0.297;

    /**
     * Ajoute l'usage d'une impression terminée au compteur de la
     * machine : m² (grand format, DTF), feuilles A4-équivalent (à la
     * feuille) ou pièces (cartes, transferts). Ce compteur sert à la
     * maintenance et à l'amortissement à l'usage.
     */
    public function enregistrerUsage(float $surfaceM2Totale, float $quantite = 0): void
    {
        // Cartes PVC, transferts : on compte les pièces, même sans dimensions.
        if ($this->compteALUnite()) {
            $this->compteurUnites += (int) round(max(0, $quantite));

            return;
        }

        if ($surfaceM2Totale <= 0) {
            return;
        }

        if ($this->utiliseSurface()) {
            $this->compteurM2 = round(($this->compteurM2 ?? 0) + $surfaceM2Totale, 2);

            return;
        }

        if ($this->utiliseFeuille()) {
            $this->compteurFeuilles += (int) round(
                $surfaceM2Totale / self::SURFACE_A4_M2
            );
        }
    }

    public function getPrixAchat(): ?int
    {
        return $this->prixAchat;
    }

    public function setPrixAchat(?int $prixAchat): static
    {
        $this->prixAchat = $prixAchat;

        return $this;
    }

    public function getDureeAmortissementMois(): ?int
    {
        return $this->dureeAmortissementMois;
    }

    public function setDureeAmortissementMois(?int $dureeAmortissementMois): static
    {
        $this->dureeAmortissementMois = $dureeAmortissementMois;

        return $this;
    }

    public function getVitesseM2Heure(): ?float
    {
        return null === $this->vitesseM2Heure ? null : (float) $this->vitesseM2Heure;
    }

    public function setVitesseM2Heure(?float $vitesseM2Heure): static
    {
        $this->vitesseM2Heure = null === $vitesseM2Heure ? null : number_format($vitesseM2Heure, 2, '.', '');

        return $this;
    }

    public function getCoutHoraire(): ?int
    {
        return $this->coutHoraire;
    }

    public function setCoutHoraire(?int $coutHoraire): static
    {
        $this->coutHoraire = $coutHoraire;

        return $this;
    }

    public function getCoutEncreM2(): ?int
    {
        return $this->coutEncreM2;
    }

    public function setCoutEncreM2(?int $coutEncreM2): static
    {
        $this->coutEncreM2 = $coutEncreM2;

        return $this;
    }

    /**
     * Largeur utile en metres, quelle que soit la saisie (« 3,20 m »,
     * « 320 cm », « 320 »), ou null si elle n'est pas lisible.
     */
    public function getLaizeMetres(): ?float
    {
        if (!preg_match('/^\s*(\d+(?:[.,]\d+)?)/', (string) $this->largeurImpression, $nombre)) {
            return null;
        }

        $valeur = (float) str_replace(',', '.', $nombre[1]);

        if ($valeur <= 0) {
            return null;
        }

        return $valeur > 10 ? $valeur / 100 : $valeur;
    }

    public function getRevenuAvantSuivi(): int
    {
        return $this->revenuAvantSuivi;
    }

    public function setRevenuAvantSuivi(int $revenuAvantSuivi): static
    {
        $this->revenuAvantSuivi = $revenuAvantSuivi;

        return $this;
    }

    public function getDateDebutSuivi(): ?\DateTime
    {
        return $this->dateDebutSuivi;
    }

    public function setDateDebutSuivi(?\DateTime $dateDebutSuivi): static
    {
        $this->dateDebutSuivi = $dateDebutSuivi;

        return $this;
    }

    /**
     * Charge d'amortissement mensuelle (prix d'achat étalé sur la durée
     * d'amortissement), ou null si l'un des deux n'est pas renseigné.
     */
    public function getChargeAmortissementMensuelle(): ?float
    {
        if ($this->prixAchat === null || !$this->dureeAmortissementMois) {
            return null;
        }

        return $this->prixAchat / $this->dureeAmortissementMois;
    }

    public function getPlanPreventif(): ?string
    {
        return $this->planPreventif;
    }

    public function setPlanPreventif(?string $plan): static
    {
        $plan = null !== $plan ? trim($plan) : null;
        $this->planPreventif = '' === $plan ? null : $plan;

        return $this;
    }

    public function getMargeMontageMm(): ?float
    {
        return $this->margeMontageMm;
    }

    public function setMargeMontageMm(?float $marge): static
    {
        $this->margeMontageMm = $marge;

        return $this;
    }

    /** @return list<string> */
    public function getFormatsMontage(): array
    {
        return $this->formatsMontage !== null ? explode(',', $this->formatsMontage) : [];
    }

    /** @param list<string> $formats */
    public function setFormatsMontage(array $formats): static
    {
        $this->formatsMontage = $formats !== [] ? implode(',', $formats) : null;

        return $this;
    }

    public function getCapaciteUsage(): ?float
    {
        return $this->capaciteUsage;
    }

    public function setCapaciteUsage(?float $capacite): static
    {
        $this->capaciteUsage = $capacite !== null && $capacite > 0 ? $capacite : null;

        return $this;
    }

    public function getValeurResiduelle(): ?int
    {
        return $this->valeurResiduelle;
    }

    public function setValeurResiduelle(?int $valeur): static
    {
        $this->valeurResiduelle = $valeur !== null && $valeur >= 0 ? $valeur : null;

        return $this;
    }

    /** Code de l'unité d'usage : celle choisie, sinon m² (grand format) ou page A4 (à la feuille). */
    public function getUnite(): string
    {
        return $this->unite ?? ($this->utiliseFeuille() ? self::UNITE_PAGE : self::UNITE_M2);
    }

    public function setUnite(?string $unite): static
    {
        $this->unite = null !== $unite && isset(self::UNITES[$unite]) ? $unite : null;

        return $this;
    }

    /** Unité d'usage courte : « m² », « m », « page A4 », « carte », « transfert ». */
    public function uniteUsage(): string
    {
        return self::UNITES[$this->getUnite()][0];
    }

    /** Unité d'un compte : « m² », « m », « pages », « cartes », « transferts ». */
    public function unitePluriel(): string
    {
        return match ($this->getUnite()) {
            self::UNITE_PAGE => 'pages',
            self::UNITE_CARTE => 'cartes',
            self::UNITE_TRANSFERT => 'transferts',
            default => $this->uniteUsage(),
        };
    }

    /** Libellé de la colonne « Unité d'usage » (M01) : « m² imprimés », « m de film »… */
    public function libelleUnite(): string
    {
        return self::UNITES[$this->getUnite()][1];
    }

    /** Machine comptée à la pièce (cartes, transferts) plutôt qu'à la surface. */
    public function compteALUnite(): bool
    {
        return \in_array($this->getUnite(), [self::UNITE_CARTE, self::UNITE_TRANSFERT], true);
    }

    /**
     * Travail converti dans l'unité d'usage : m² tels quels, mètres de
     * film (surface ÷ laize), pages A4 (surface ÷ surface d'une A4), ou
     * nombre de pièces pour les cartes et les transferts.
     */
    public function usagePour(float $surfaceM2, float $quantite = 0): float
    {
        return match ($this->getUnite()) {
            self::UNITE_PAGE => $surfaceM2 / self::SURFACE_A4_M2,
            self::UNITE_METRE => $surfaceM2 / ($this->getLaizeMetres() ?? 1.0),
            self::UNITE_CARTE, self::UNITE_TRANSFERT => max(0.0, $quantite),
            default => $surfaceM2,
        };
    }

    /** Usage compté depuis la mise en service, dans l'unité de la machine. */
    public function usage(): float
    {
        return match ($this->getUnite()) {
            self::UNITE_PAGE => (float) $this->compteurFeuilles,
            self::UNITE_METRE => (float) ($this->compteurM2 ?? 0) / ($this->getLaizeMetres() ?? 1.0),
            self::UNITE_CARTE, self::UNITE_TRANSFERT => (float) $this->compteurUnites,
            default => (float) ($this->compteurM2 ?? 0),
        };
    }

    /**
     * Recale le compteur de l'unité d'usage sur un relevé (M02) : le
     * compteur de la machine fait foi, la production n'était qu'une
     * estimation entre deux relevés.
     */
    public function recalerCompteur(float $usage): static
    {
        match ($this->getUnite()) {
            self::UNITE_PAGE => $this->compteurFeuilles = (int) round($usage),
            self::UNITE_METRE => $this->compteurM2 = round($usage * ($this->getLaizeMetres() ?? 1.0), 2),
            self::UNITE_CARTE, self::UNITE_TRANSFERT => $this->compteurUnites = (int) round($usage),
            default => $this->compteurM2 = round($usage, 2),
        };

        return $this;
    }

    public function getCompteurUnites(): int
    {
        return $this->compteurUnites;
    }

    public function setCompteurUnites(int $compteur): static
    {
        $this->compteurUnites = max(0, $compteur);

        return $this;
    }

    public function getReleveCompteurs(): ?string
    {
        return $this->releveCompteurs;
    }

    public function setReleveCompteurs(?string $releve): static
    {
        $this->releveCompteurs = null !== $releve && isset(self::RELEVES[$releve]) ? $releve : null;

        return $this;
    }

    /** Compteurs A4 / A3 en couleur et en noir (photocopieur). */
    public function releveA4A3(): bool
    {
        return self::RELEVE_A4A3 === $this->releveCompteurs;
    }

    public function getCoutPageCouleur(): ?float
    {
        return null === $this->coutPageCouleur ? null : (float) $this->coutPageCouleur;
    }

    public function setCoutPageCouleur(?float $cout): static
    {
        $this->coutPageCouleur = null === $cout || $cout < 0 ? null : number_format($cout, 2, '.', '');

        return $this;
    }

    public function getCoutPageNoir(): ?float
    {
        return null === $this->coutPageNoir ? null : (float) $this->coutPageNoir;
    }

    public function setCoutPageNoir(?float $cout): static
    {
        $this->coutPageNoir = null === $cout || $cout < 0 ? null : number_format($cout, 2, '.', '');

        return $this;
    }

    /** Montant à amortir : prix d'achat moins valeur résiduelle. */
    public function baseAmortissable(): ?int
    {
        return $this->prixAchat !== null ? max(0, $this->prixAchat - ($this->valeurResiduelle ?? 0)) : null;
    }

    /**
     * Amortissement par unité d'usage : (prix d'achat − valeur résiduelle)
     * ÷ capacité prévue. Exemple : 18 000 000 F, 0 F, 60 000 m² → 300 F/m².
     */
    public function coutUsageUnitaire(): ?float
    {
        $base = $this->baseAmortissable();

        return $base !== null && $this->capaciteUsage !== null && $this->capaciteUsage > 0 ? $base / $this->capaciteUsage : null;
    }
}
