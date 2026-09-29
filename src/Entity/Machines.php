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
     * Ajoute l'usage d'une impression au compteur d'usure de la
     * machine, uniquement pour suivre la maintenance — n'affecte pas
     * le calcul de l'amortissement (basé sur le temps écoulé, voir
     * getChargeAmortissementMensuelle()). Le compteur alimenté dépend
     * du mode de facturation de la machine.
     */
    public function enregistrerUsage(float $surfaceM2Totale): void
    {
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
}
