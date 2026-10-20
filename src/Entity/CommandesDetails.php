<?php

namespace App\Entity;

use App\Entity\Trait\PromotionLigneTrait;
use App\Entity\Trait\PublicIdTrait;
use App\Entity\Trait\PiecesAuM2Trait;
use App\Entity\Trait\TotalFigeTrait;
use App\Repository\CommandesDetailsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CommandesDetailsRepository::class)]
#[ORM\Table(name: 'commandes_details')]
#[ORM\HasLifecycleCallbacks]
class CommandesDetails
{
    use PublicIdTrait;
    use PromotionLigneTrait;
    use TotalFigeTrait;
    use PiecesAuM2Trait;

    public const PRODUCTION_A_PRODUIRE = 'a_produire';
    public const PRODUCTION_EN_COURS = 'en_production';
    public const PRODUCTION_TERMINEE = 'terminee';
    public const PRODUCTION_PRETE_LIVRAISON = 'prete_livraison';
    public const PRODUCTION_EN_LIVRAISON = 'en_livraison';
    public const PRODUCTION_LIVREE = 'livree';
    public const PRODUCTION_ANNULEE = 'annulee';
    public const PRODUCTION_NON_REQUISE = 'non_requise';


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produits $produit = null;

    /*
     * « Mes produits » (docs/mes-produits.md) : la ligne vient d'un produit
     * deja valide du client. On garde ce que le client a demande (ex. 20 m2)
     * a cote de ce que l'atelier produit (quantite, ex. 4 baches).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProduitClient $produitClient = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $quantiteDemandee = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $uniteDemandee = null;

    /*
     * Fichier lie du produit du client retenu a la commande (MP05/MP06) :
     * nom, empreinte SHA-256, taille validee (mm) et date du lien. Seulement
     * la reference : le fichier reste sur le serveur de l'atelier. Le local
     * la compare a son produit et au fichier du dossier avant l'impression
     * (App\Service\ProduitsClient\Aiguillage).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fichierLie = null;

    #[ORM\Column(length: 20, options: ['default' => 'automatique'])]
    #[Assert\Choice(
        choices: ['automatique', 'manuel', 'libre'],
        message: 'Le mode de saisie est invalide.'
    )]
    private string $modeConfiguration = 'manuel';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'produit_configuration_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?ProduitConfiguration $produitConfiguration = null;

    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TypesImpression $typeImpression = null;

    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supports $support = null;

    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Machines $machine = null;
    #[ORM\ManyToOne(inversedBy: 'commandesDetails')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Format $format = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La désignation est obligatoire.')]
    private ?string $designation = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 12,
        scale: 4,
        nullable: true
    )]
    #[Assert\PositiveOrZero]
    private ?string $largeur = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 12,
        scale: 4,
        nullable: true
    )]
    #[Assert\PositiveOrZero]
    private ?string $longueur = null;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 12,
        scale: 4,
        nullable: true
    )]
    #[Assert\PositiveOrZero]
    private ?string $surface = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive(
        message: 'La quantité doit être supérieure à zéro.'
    )]
    private int $quantite = 1;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, options: ['default' => '0.0000'])]
    #[Assert\PositiveOrZero(
        message: 'Le prix unitaire ne peut pas être négatif.'
    )]
    private string $prixUnitaire = '0';

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $coutRevient = 0;

    /*
     * Remise en FCFA (montant fixe), pas en pourcentage : plus
     * simple à saisir précisément qu'un pourcentage (ex. 14,2857 %
     * pour obtenir exactement 2 000 FCFA de remise sur 14 000 FCFA).
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $remise = 0;

    /*
     * La TVA est également un pourcentage.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $tva = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTtc = 0;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $profilCouleurs = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $resolution = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $grammage = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $epaisseur = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $rectoVerso = false;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    private int $nombreFaces = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $laminage = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $oeillets = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $decoupe = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $pliage = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $emballage = false;

    /*
     * Champ temporaire du formulaire.
     * Il n’est pas enregistré directement dans cette table.
     */
    private ?string $jetonsFichiers = null;

    /**
     * @var Collection<int, ControlePrePresse>
     */
    #[ORM\OneToMany(
        targetEntity: ControlePrePresse::class,
        mappedBy: 'commandeDetail',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['creeLe' => 'DESC'])]
    private Collection $controlesPrePresse;

    #[ORM\Column(options: ['default' => false])]
    private bool $batValide = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $etat = true;

    #[ORM\Column(length: 30, options: ['default' => 'normale'])]
    #[Assert\Choice(
        choices: ['basse', 'normale', 'haute', 'urgente']
    )]
    private string $priorite = 'normale';

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $tempsEstime = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $tempsReel = null;

    /*
     * Ancien champ conservé pour les anciennes commandes.
     * Les nouveaux fichiers sont enregistrés dans $fichiers.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    /** Ce que le client a écrit en commandant depuis son espace (ses mots, visibles de lui). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $demandeClient = null;

    #[ORM\Column(
        length: 30,
        options: ['default' => 'a_produire']
    )]
    #[Assert\Choice(
        callback: [self::class, 'getStatutsProductionAutorises'],
        message: 'Le statut de production est invalide.'
    )]
    private string $statutProduction = self::PRODUCTION_A_PRODUIRE;

    /**
     * Statut de production juste avant l'annulation de la commande,
     * mémorisé pour permettre une restauration fidèle (voir
     * CommandesController::annuler() / ::restaurer()). Remis à null
     * dès que la ligne est restaurée.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $statutProductionAvantAnnulation = null;

    /**
     * Ligne sans fichier retirée de la liste de la prépresse (bouton
     * « Retirer de la liste », docs/prepresse.md) : la commande n'est ni
     * clôturée ni supprimée, et « Remettre dans la liste » l'y ramène.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retirePrepresseLe = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $retirePrepressePar = null;

    /**
     * Fichier de découpe (docs/prepresse.md) : null, d'après les finitions
     * de la ligne (RegleDecoupe) ; true ou false, la prépresse l'a coché
     * (« Fichier de découpe requis ») ou décoché.
     */
    #[ORM\Column(nullable: true)]
    private ?bool $fichierDecoupeRequis = null;

    /**
     * « Découpe sans fichier séparé » : coché par le graphiste à l'envoi en
     * production (le contour est dans le fichier d'impression). Montré au
     * machiniste.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $decoupeSansFichier = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $productionDebuteLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $productionTermineeLe = null;

    /**
     * Passage « prête pour la livraison » (contrôle final fait, colis
     * scannés) : c'est la date de l'étape « Prête » vue par le client.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $preteLivraisonLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'production_debutee_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $productionDebuteePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'production_terminee_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $productionTermineePar = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $quantiteProduite = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $quantiteRebut = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observationProduction = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $quantiteLivree = 0;

    /**
     * @var Collection<int, Etiquette>
     */
    #[ORM\OneToMany(
        targetEntity: Etiquette::class,
        mappedBy: 'commandeDetail'
    )]
    #[ORM\OrderBy(['creeLe' => 'DESC'])]
    private Collection $etiquettes;

    /**
     * @var Collection<int, CommandeDetailFinition>
     */
    #[ORM\OneToMany(
        targetEntity: CommandeDetailFinition::class,
        mappedBy: 'commandeDetail',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Valid]
    private Collection $finitions;

    /**
     * @var Collection<int, CommandeDetailFichier>
     */
    #[ORM\OneToMany(
        targetEntity: CommandeDetailFichier::class,
        mappedBy: 'commandeDetail',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Valid]
    private Collection $fichiers;

    /**
     * @var Collection<int, Production>
     */
    #[ORM\OneToMany(
        targetEntity: Production::class,
        mappedBy: 'commandeDetails'
    )]
    private Collection $productions;

    /**
     * @var Collection<int, StockSorties>
     */
    #[ORM\OneToMany(
        targetEntity: StockSorties::class,
        mappedBy: 'commandeDetail'
    )]
    private Collection $stockSorties;


    #[ORM\Column(
        length: 30,
        options: ['default' => 'automatique']
    )]
    private string $modeSaisie = 'automatique';

    #[ORM\Column(length: 30, options: ['default' => 'unite'])]
    #[Assert\Choice(
        choices: [
            'forfait',
            'unite',
            'heure',
            'feuille',
            'exemplaire',
            'metre',
            'metre_carre',
            'point',
            'face',
        ],
        message: 'Le mode de calcul est invalide.'
    )]
    private string $modeCalcul = 'unite';

    #[ORM\Column(options: ['default' => true])]
    private bool $prePresseNecessaire = true;


    #[ORM\Column(options: ['default' => true])]
    private bool $productionNecessaire = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Articles $article = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->finitions = new ArrayCollection();
        $this->fichiers = new ArrayCollection();
        $this->productions = new ArrayCollection();
        $this->stockSorties = new ArrayCollection();
        $this->controlesPrePresse = new ArrayCollection();
        $this->etiquettes = new ArrayCollection();
    }

    public const TYPE_PRODUIT = 'produit';
    public const TYPE_ARTICLE = 'article';
    public const TYPE_LIBRE = 'libre';

    #[ORM\Column(
        length: 20,
        options: [
            'default' => 'produit',
        ]
    )]
    private string $typeLigne = self::TYPE_PRODUIT;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getProduit(): ?Produits
    {
        return $this->produit;
    }

    public function setProduit(?Produits $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getModeConfiguration(): string
    {
        return $this->modeConfiguration;
    }

    public function setModeConfiguration(
        ?string $modeConfiguration
    ): static {
        $modeConfiguration = strtolower(
            trim($modeConfiguration ?? '')
        );

        /*
         * Repli pour une valeur vide/invalide : "manuel", pas
         * "automatique". Necessaire car un groupe de boutons radio
         * "expanded" (voir CommandesDetailsType::modeConfiguration,
         * desormais cache a l'utilisateur) soumet null quand aucun
         * radio n'est coche -- et "empty_data" ne s'applique pas a
         * un champ "expanded" (compound) en Symfony, donc cette
         * option seule ne suffit pas a eviter ce repli.
         */
        $this->modeConfiguration = in_array(
            $modeConfiguration,
            ['automatique', 'manuel', 'libre'],
            true
        ) ? $modeConfiguration : 'manuel';

        if ($this->modeConfiguration === 'libre') {
            $this->produit = null;
            $this->produitConfiguration = null;
            $this->typeImpression = null;
            $this->support = null;
            $this->format = null;
            $this->machine = null;
        }

        if ($this->modeConfiguration === 'manuel') {
            $this->produitConfiguration = null;
        }

        return $this;
    }
    public function isSaisieLibre(): bool
    {
        return $this->modeConfiguration === 'libre';
    }
    public function isConfigurationAutomatique(): bool
    {
        return $this->modeConfiguration === 'automatique';
    }
    public function isConfigurationManuelle(): bool
    {
        return $this->modeConfiguration === 'manuel';
    }

    /**
     * Applique la configuration au détail de commande.
     *
     * En mode automatique :
     * - produit, type, support et format sont imposés ;
     * - les dimensions sont reprises de la configuration ;
     * - elles ne peuvent pas être remplacées par le formulaire.
     *
     * En mode manuel :
     * - les dimensions peuvent être saisies uniquement pour
     *   une configuration de type "mesure" ;
     * - la surface est toujours recalculée côté serveur.
     */
    public function appliquerConfiguration(
        bool $clientB2B = false
    ): static {
        if (!$this->isConfigurationAutomatique()) {
            throw new \DomainException(
                'Une configuration catalogue ne peut être appliquée '
                    . 'qu’en mode automatique.'
            );
        }
        $configuration = $this->produitConfiguration;

        if ($configuration === null) {
            throw new \DomainException(
                'Veuillez sélectionner une configuration de produit.'
            );
        }

        if (!$configuration->isActive()) {
            throw new \DomainException(
                'Cette configuration de produit est inactive.'
            );
        }

        /*
     * Ces informations doivent toujours correspondre
     * à la configuration sélectionnée.
     */
        $this->produit = $configuration->getProduit();
        $this->typeImpression = $configuration->getTypeImpression();
        $this->support = $configuration->getSupport();
        $this->format = $configuration->getFormat();

        $this->verifierQuantiteConfiguration($configuration);

        if ($configuration->utiliseFormatFixe()) {
            if ($this->format === null) {
                throw new \DomainException(
                    'Aucun format n’est associé à cette configuration.'
                );
            }

            /*
         * A4, A3, A2, carte de visite...
         * La largeur, la longueur et la surface ne sont pas utiles.
         */
            $this->largeur = null;
            $this->longueur = null;
            $this->surface = null;
        } elseif ($configuration->utiliseDimensions()) {
            if ($this->isConfigurationAutomatique()) {
                /*
             * Même si une autre valeur a été envoyée depuis le navigateur,
             * on reprend obligatoirement les dimensions enregistrées.
             */
                if (!$configuration->possedeDimensionsParDefaut()) {
                    throw new \DomainException(
                        'Les dimensions automatiques de cette configuration '
                            . 'ne sont pas renseignées.'
                    );
                }

                $this->setLargeur(
                    $configuration->getLargeurDefaut()
                );

                $this->setLongueur(
                    $configuration->getLongueurDefaut()
                );
            } else {
                /*
             * En manuel, l’utilisateur choisit la largeur
             * et la longueur.
             */
                if (
                    $this->largeur === null
                    || (float) $this->largeur <= 0
                    || $this->longueur === null
                    || (float) $this->longueur <= 0
                ) {
                    throw new \DomainException(
                        'Veuillez saisir une largeur et une longueur '
                            . 'supérieures à zéro.'
                    );
                }
            }

            /*
         * La surface reçue du navigateur n’est jamais utilisée.
         * Elle est recalculée ici.
         */
            $this->calculerSurface();
        } else {
            /*
         * Configuration sans format ni dimensions :
         * mug, stylo, prestation forfaitaire, etc.
         */
            $this->format = null;
            $this->largeur = null;
            $this->longueur = null;
            $this->surface = null;
        }

        $this->prixUnitaire = number_format(
            (float) $configuration->getPrixApplicable($clientB2B),
            4,
            '.',
            ''
        );
        $this->modeCalcul = $configuration->getModeCalcul();

        if ($this->designation === null || trim($this->designation) === '') {
            $this->designation = $this->produit?->getNom();
        }
        return $this;
    }
    private function verifierQuantiteConfiguration(
        ProduitConfiguration $configuration
    ): void {
        if ($this->quantite < $configuration->getQuantiteMinimale()) {
            throw new \DomainException(sprintf(
                'La quantité minimale pour cette configuration est %d.',
                $configuration->getQuantiteMinimale()
            ));
        }

        $quantiteMaximale = $configuration->getQuantiteMaximale();

        if (
            $quantiteMaximale !== null
            && $this->quantite > $quantiteMaximale
        ) {
            throw new \DomainException(sprintf(
                'La quantité maximale pour cette configuration est %d.',
                $quantiteMaximale
            ));
        }
    }

    public function calculerMontantImpression(): int
    {
        return (int) round(
            (float) $this->prixUnitaire * max(0, $this->calculerFacteur())
        );
    }

    /*
     * Nombre d'"unités facturables" de la ligne selon son mode de
     * calcul (mètres carrés, mètres linéaires, exemplaires...).
     * Utilisé à la fois pour le prix (prixUnitaire x facteur) et pour
     * la remise (remise x facteur) : la remise est elle aussi un
     * montant "par unité" (ex. 500 FCFA par m²), pas un montant fixe
     * sur toute la ligne.
     */
    private function calculerFacteur(): float
    {
        return match ($this->modeCalcul) {
            'forfait' => 1,

            'unite',
            'heure',
            'feuille',
            'exemplaire' => $this->quantite,

            'metre' => (float) ($this->longueur ?? 0)
                * $this->quantite,

            // Petites pièces (PiecesAuM2Trait) : la surface facturée vient du nombre de pièces au m².
            'metre_carre' => $this->surfacePiecesAuM2()
                ?? (float) ($this->surface ?? 0) * $this->quantite,

            'face' => max(1, $this->nombreFaces)
                * $this->quantite,

            'point' => $this->quantite,

            default => $this->quantite,
        };
    }

    /**
     * Surface totale de la ligne (surface unitaire x quantité), pour
     * l'affichage "(X m²)" sur les PDF. Le cast (float) est
     * volontaire : $surface peut contenir une chaîne non numérique
     * sur d'anciennes lignes, et une multiplication directe
     * planterait au lieu de retourner 0.
     */
    public function getSurfaceTotale(): float
    {
        // En pièces au m² : la surface facturée (espacement et chutes compris).
        return $this->surfacePiecesAuM2() ?? (float) ($this->surface ?? 0) * $this->quantite;
    }

    public function getProduitClient(): ?ProduitClient
    {
        return $this->produitClient;
    }

    public function setProduitClient(?ProduitClient $produitClient): static
    {
        $this->produitClient = $produitClient;

        return $this;
    }

    /** @return array{nom: string, empreinte: string, l: float, h: float, le: ?string}|null */
    public function getFichierLie(): ?array
    {
        return $this->fichierLie;
    }

    public function setFichierLie(?array $fichierLie): static
    {
        $this->fichierLie = $fichierLie;

        return $this;
    }

    public function getQuantiteDemandee(): ?float
    {
        return null === $this->quantiteDemandee ? null : (float) $this->quantiteDemandee;
    }

    public function getUniteDemandee(): ?string
    {
        return $this->uniteDemandee;
    }

    /**
     * Quantite saisie par le client et son unite (piece, m2, lot).
     */
    public function setDemande(?float $quantite, ?string $unite): static
    {
        $this->quantiteDemandee = null === $quantite ? null : number_format($quantite, 2, '.', '');
        $this->uniteDemandee = null === $quantite ? null : $unite;

        return $this;
    }

    public function getProduitConfiguration(): ?ProduitConfiguration
    {
        return $this->produitConfiguration;
    }

    public function setProduitConfiguration(
        ?ProduitConfiguration $produitConfiguration
    ): static {
        $this->produitConfiguration = $produitConfiguration;

        return $this;
    }

    public function getTypeImpression(): ?TypesImpression
    {
        return $this->typeImpression;
    }

    public function setTypeImpression(
        ?TypesImpression $typeImpression
    ): static {
        $this->typeImpression = $typeImpression;

        return $this;
    }

    public function getSupport(): ?Supports
    {
        return $this->support;
    }

    public function setSupport(?Supports $support): static
    {
        $this->support = $support;

        return $this;
    }

    public function getMachine(): ?Machines
    {
        return $this->machine;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getFormat(): ?Format
    {
        return $this->format;
    }

    public function setFormat(?Format $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $this->designation = $designation !== null
            ? trim($designation)
            : null;

        return $this;
    }

    public function getLargeur(): ?string
    {
        return $this->largeur;
    }

    public function setLargeur(
        string|float|int|null $largeur
    ): static {
        $this->largeur = $this->normaliserDecimal($largeur, 4);

        return $this;
    }

    public function getLongueur(): ?string
    {
        return $this->longueur;
    }

    public function setLongueur(
        string|float|int|null $longueur
    ): static {
        $this->longueur = $this->normaliserDecimal($longueur, 4);

        return $this;
    }

    public function getSurface(): ?string
    {
        return $this->surface;
    }

    public function setSurface(
        string|float|int|null $surface
    ): static {
        $this->surface = $this->normaliserDecimal($surface, 4);

        return $this;
    }

    private function normaliserDecimal(
        string|float|int|null $valeur,
        int $decimales
    ): ?string {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        $valeur = str_replace(',', '.', (string) $valeur);

        if (!is_numeric($valeur)) {
            return null;
        }

        return number_format(
            max(0, (float) $valeur),
            $decimales,
            '.',
            ''
        );
    }

    public function calculerSurface(): static
    {
        if ($this->largeur === null || $this->longueur === null) {
            $this->surface = null;

            return $this;
        }

        $this->surface = number_format(
            (float) $this->largeur * (float) $this->longueur,
            4,
            '.',
            ''
        );

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(?int $quantite): static
    {
        $this->quantite = max(1, $quantite ?? 1);

        return $this;
    }

    public function getPrixUnitaire(): float
    {
        return (float) $this->prixUnitaire;
    }

    public function setPrixUnitaire(
        string|float|int|null $prixUnitaire
    ): static {
        $this->prixUnitaire = $this->normaliserDecimal(
            $prixUnitaire,
            4
        ) ?? '0';

        return $this;
    }

    public function getCoutRevient(): int
    {
        return $this->coutRevient;
    }

    public function setCoutRevient(?int $coutRevient): static
    {
        $this->coutRevient = max(0, $coutRevient ?? 0);

        return $this;
    }

    public function getRemise(): int
    {
        return $this->remise;
    }

    public function setRemise(string|float|int|null $remise): static
    {
        $this->remise = max(0, (int) round((float) ($remise ?? 0)));

        return $this;
    }

    public function getTva(): int
    {
        return $this->tva;
    }

    public function setTva(?int $tva): static
    {
        $this->tva = max(0, $tva ?? 0);

        return $this;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function setTotalHt(?int $totalHt): static
    {
        $this->totalHt = max(0, $totalHt ?? 0);

        return $this;
    }

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }

    public function setTotalTtc(?int $totalTtc): static
    {
        $this->totalTtc = max(0, $totalTtc ?? 0);

        return $this;
    }

    /**
     * @param bool $clientB2B sans effet ici (le tarif B2B est choisi à la
     *                        création de la ligne) ; gardé pour les appelants
     */
    public function calculerTotaux(
        bool $clientB2B = false
    ): static {
        // Total d'origine conservé (bascule, TotalFigeTrait) : rien de ce
        // qui fait le total n'a changé depuis sa lecture.
        if ($this->garderTotalFige()) {
            return $this;
        }

        /*
         * La configuration du catalogue s'applique quand la ligne est
         * créée, jamais en recalculant ses totaux : une ancienne ligne
         * « automatique » (promotion décidée, réveil d'un client...)
         * garde ses dimensions, son prix et ses finitions. La réappliquer
         * effaçait sa largeur et sa longueur quand la configuration
         * n'impose pas de dimensions.
         */
        if (
            $this->typeLigne === self::TYPE_PRODUIT
            && $this->modeCalcul === 'metre_carre'
        ) {
            $this->calculerSurface();
        }

        $this->appliquerDimensionsALaDesignation();

        $totalBrut = $this->calculerTotalBrut();
        $montantRemise = $this->calculerMontantRemise($totalBrut);
        $this->montantPromotion = $this->calculerMontantPromotion($totalBrut, $this->calculerFacteur());

        $this->totalHt = max(
            0,
            $totalBrut - $montantRemise
        );
        // Part du code promo de la commande (docs/promotions.md), après les remises de la ligne.
        $this->totalHt -= $this->plafonnerRemiseCodePromo($this->totalHt);

        $montantTva = (int) round(
            $this->totalHt * $this->tva / 100
        );

        $this->totalTtc = $this->totalHt + $montantTva;

        return $this;
    }

    private function calculerTotalBrut(): int
    {
        $montantFinitions = 0;

        foreach ($this->finitions as $finition) {
            $montantFinitions += max(
                0,
                $finition->getMontant() ?? 0
            );
        }

        return $this->calculerMontantImpression() + $montantFinitions;
    }

    /*
     * La remise est un montant par unité facturable (ex. 500 FCFA par
     * m²), pas un montant fixe sur toute la ligne : elle se multiplie
     * donc par le même facteur que le prix unitaire
     * (calculerFacteur()), plafonné au total brut de la ligne. La remise
     * de la promotion (docs/promotions.md) s'y ajoute.
     */
    private function calculerMontantRemise(int $totalBrut): int
    {
        return min(
            $totalBrut,
            max(0, (int) round(($this->remise + $this->remisePromotion) * $this->calculerFacteur()))
        );
    }

    /**
     * Montant effectivement retiré par la remise sur cette ligne
     * (remise par unité x facteur, plafonné au total brut), utilisé
     * pour l'affichage sur les PDF (devis/facture) au lieu du seul
     * taux par unité.
     */
    public function getMontantRemise(): int
    {
        $brut = $this->calculerTotalBrut();

        // Total d'origine conservé : ce qui a vraiment été retiré du brut.
        if ($this->totalFige) {
            return max(0, $brut - $this->totalHt);
        }

        $remise = $this->calculerMontantRemise($brut);

        // Avec la part du code promo de la commande : la ligne montre tout ce qui lui est retiré.
        return $remise + min($this->getRemiseCodePromo(), max(0, $brut - $remise));
    }

    /**
     * Ajoute (ou met à jour) les dimensions dans la désignation, au
     * format "Nom du produit (29,7 x 42 cm)", pour qu'elles restent
     * visibles partout où la désignation est affichée (listes, PDF)
     * sans devoir modifier chaque gabarit.
     *
     * Idempotent : un ancien suffixe de dimensions est d'abord
     * retiré avant d'ajouter le suffixe à jour, pour ne pas
     * l'accumuler à chaque nouvel enregistrement.
     */
    private function appliquerDimensionsALaDesignation(): void
    {
        $base = preg_replace(
            '/\s*\([0-9]+(?:,[0-9]+)?\s*x\s*[0-9]+(?:,[0-9]+)?\s*cm\)\s*$/u',
            '',
            trim((string) $this->designation)
        );

        if (
            $this->largeur === null
            || $this->longueur === null
        ) {
            $this->designation = $base !== '' ? $base : null;

            return;
        }

        $formaterCm = static function (string $valeurMetres): string {
            $texte = number_format(
                (float) $valeurMetres * 100,
                2,
                ',',
                ''
            );

            $texte = rtrim($texte, '0');
            $texte = rtrim($texte, ',');

            return $texte === '' ? '0' : $texte;
        };

        $suffixe = sprintf(
            ' (%s x %s cm)',
            $formaterCm($this->largeur),
            $formaterCm($this->longueur)
        );

        $this->designation = ($base !== '' ? $base : 'Produit') . $suffixe;
    }

    public function getProfilCouleurs(): ?string
    {
        return $this->profilCouleurs;
    }

    public function setProfilCouleurs(
        ?string $profilCouleurs
    ): static {
        $this->profilCouleurs = $this->nettoyerTexte(
            $profilCouleurs
        );

        return $this;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function setResolution(?string $resolution): static
    {
        $this->resolution = $this->nettoyerTexte($resolution);

        return $this;
    }

    public function getGrammage(): ?string
    {
        return $this->grammage;
    }

    public function setGrammage(?string $grammage): static
    {
        $this->grammage = $this->nettoyerTexte($grammage);

        return $this;
    }

    public function getEpaisseur(): ?string
    {
        return $this->epaisseur;
    }

    public function setEpaisseur(?string $epaisseur): static
    {
        $this->epaisseur = $this->nettoyerTexte($epaisseur);

        return $this;
    }

    public function isRectoVerso(): bool
    {
        return $this->rectoVerso;
    }

    public function setRectoVerso(bool $rectoVerso): static
    {
        $this->rectoVerso = $rectoVerso;

        return $this;
    }

    public function getNombreFaces(): int
    {
        return $this->nombreFaces;
    }

    public function setNombreFaces(?int $nombreFaces): static
    {
        $this->nombreFaces = max(1, $nombreFaces ?? 1);

        return $this;
    }

    public function getLaminage(): ?string
    {
        return $this->laminage;
    }

    public function setLaminage(?string $laminage): static
    {
        $this->laminage = $this->nettoyerTexte($laminage);

        return $this;
    }

    public function isOeillets(): bool
    {
        return $this->oeillets;
    }

    public function setOeillets(bool $oeillets): static
    {
        $this->oeillets = $oeillets;

        return $this;
    }

    public function isDecoupe(): bool
    {
        return $this->decoupe;
    }

    public function setDecoupe(bool $decoupe): static
    {
        $this->decoupe = $decoupe;

        return $this;
    }

    public function getPliage(): ?string
    {
        return $this->pliage;
    }

    public function setPliage(?string $pliage): static
    {
        $this->pliage = $this->nettoyerTexte($pliage);

        return $this;
    }

    public function isEmballage(): bool
    {
        return $this->emballage;
    }

    public function setEmballage(bool $emballage): static
    {
        $this->emballage = $emballage;

        return $this;
    }

    public function getJetonsFichiers(): ?string
    {
        return $this->jetonsFichiers;
    }

    public function setJetonsFichiers(
        ?string $jetonsFichiers
    ): static {
        $this->jetonsFichiers = $jetonsFichiers;

        return $this;
    }

    public function isBatValide(): bool
    {
        return $this->batValide;
    }

    public function setBatValide(bool $batValide): static
    {
        $this->batValide = $batValide;

        return $this;
    }

    public function isEtat(): bool
    {
        return $this->etat;
    }

    public function setEtat(bool $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    public function getPriorite(): string
    {
        return $this->priorite;
    }

    public function setPriorite(?string $priorite): static
    {
        $priorite = strtolower(trim($priorite ?? ''));

        $this->priorite = in_array(
            $priorite,
            ['basse', 'normale', 'haute', 'urgente'],
            true
        ) ? $priorite : 'normale';

        return $this;
    }

    public function getTempsEstime(): ?int
    {
        return $this->tempsEstime;
    }

    public function setTempsEstime(?int $tempsEstime): static
    {
        $this->tempsEstime = $tempsEstime !== null
            ? max(0, $tempsEstime)
            : null;

        return $this;
    }

    public function getTempsReel(): ?int
    {
        return $this->tempsReel;
    }

    public function setTempsReel(?int $tempsReel): static
    {
        $this->tempsReel = $tempsReel !== null
            ? max(0, $tempsReel)
            : null;

        return $this;
    }

    public function getFichier(): ?string
    {
        return $this->fichier;
    }

    public function setFichier(?string $fichier): static
    {
        $this->fichier = $this->nettoyerTexte($fichier);

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $this->observation = $this->nettoyerTexte(
            $observation
        );

        return $this;
    }

    private function nettoyerTexte(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur !== '' ? $valeur : null;
    }

    /**
     * @return Collection<int, CommandeDetailFinition>
     */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

    public function addFinition(
        CommandeDetailFinition $finition
    ): static {
        if (!$this->finitions->contains($finition)) {
            $this->finitions->add($finition);
            $finition->setCommandeDetail($this);
        }

        return $this;
    }

    public function removeFinition(
        CommandeDetailFinition $finition
    ): static {
        if ($this->finitions->removeElement($finition)) {
            if ($finition->getCommandeDetail() === $this) {
                $finition->setCommandeDetail(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, CommandeDetailFichier>
     */
    public function getFichiers(): Collection
    {
        return $this->fichiers;
    }

    public function addFichier(
        CommandeDetailFichier $fichier
    ): static {
        if (!$this->fichiers->contains($fichier)) {
            $this->fichiers->add($fichier);
            $fichier->setCommandeDetail($this);
        }

        return $this;
    }

    public function removeFichier(
        CommandeDetailFichier $fichier
    ): static {
        if ($this->fichiers->removeElement($fichier)) {
            if ($fichier->getCommandeDetail() === $this) {
                $fichier->setCommandeDetail(null);
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
            $production->setCommandeDetails($this);
        }

        return $this;
    }

    public function removeProduction(Production $production): static
    {
        if ($this->productions->removeElement($production)) {
            if ($production->getCommandeDetails() === $this) {
                $production->setCommandeDetails(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, StockSorties>
     */
    public function getStockSorties(): Collection
    {
        return $this->stockSorties;
    }

    public function addStockSorty(
        StockSorties $stockSorty
    ): static {
        if (!$this->stockSorties->contains($stockSorty)) {
            $this->stockSorties->add($stockSorty);
            $stockSorty->setCommandeDetail($this);
        }

        return $this;
    }

    public function removeStockSorty(
        StockSorties $stockSorty
    ): static {
        if ($this->stockSorties->removeElement($stockSorty)) {
            if ($stockSorty->getCommandeDetail() === $this) {
                $stockSorty->setCommandeDetail(null);
            }
        }

        return $this;
    }
  #[Assert\Callback]
public function validerModeCommande(
    ExecutionContextInterface $context
): void {
    /*
     * ========================================================
     * TYPE : ARTICLE EN STOCK
     * ========================================================
     *
     * Une vente directe d'article ne dépend ni d'un produit,
     * ni d'une configuration Produit.
     */
    if (
        $this->getTypeLigne()
        === self::TYPE_ARTICLE
    ) {
        if ($this->getArticle() === null) {
            $context
                ->buildViolation(
                    'Veuillez sélectionner un article en stock.'
                )
                ->atPath('article')
                ->addViolation();
        }

        if ((float) $this->prixUnitaire <= 0) {
            $context
                ->buildViolation(
                    'Le prix unitaire doit être supérieur à zéro.'
                )
                ->atPath('prixUnitaire')
                ->addViolation();
        }

        return;
    }


    /*
     * ========================================================
     * TYPE : SAISIE LIBRE
     * ========================================================
     */
    if (
        $this->getTypeLigne()
        === self::TYPE_LIBRE
    ) {
        if (
            trim(
                (string) $this->getDesignation()
            ) === ''
        ) {
            $context
                ->buildViolation(
                    'La désignation est obligatoire pour une saisie libre.'
                )
                ->atPath('designation')
                ->addViolation();
        }

        if ((float) $this->prixUnitaire <= 0) {
            $context
                ->buildViolation(
                    'Le prix unitaire doit être supérieur à zéro.'
                )
                ->atPath('prixUnitaire')
                ->addViolation();
        }

        return;
    }


    /*
     * ========================================================
     * TYPE : PRODUIT / PRESTATION
     * ========================================================
     */
    if (
        $this->getTypeLigne()
        !== self::TYPE_PRODUIT
    ) {
        $context
            ->buildViolation(
                'Le type de ligne est invalide.'
            )
            ->atPath('typeLigne')
            ->addViolation();

        return;
    }


    /*
     * ========================================================
     * MODE AUTOMATIQUE
     * ========================================================
     */
    if (
        $this->isConfigurationAutomatique()
    ) {
        if ($this->produitConfiguration === null) {
            $context
                ->buildViolation(
                    'Une configuration est obligatoire en mode automatique.'
                )
                ->atPath('produitConfiguration')
                ->addViolation();
        }

        if ($this->produit === null) {
            $context
                ->buildViolation(
                    'Le produit est obligatoire en mode automatique.'
                )
                ->atPath('produit')
                ->addViolation();
        }
    }


    /*
     * ========================================================
     * MODE MANUEL
     * ========================================================
     */
    if (
        $this->isConfigurationManuelle()
    ) {
        if ($this->produit === null) {
            $context
                ->buildViolation(
                    'Le produit est obligatoire en mode manuel.'
                )
                ->atPath('produit')
                ->addViolation();
        }

        if (
            $this->produitConfiguration
            !== null
        ) {
            $context
                ->buildViolation(
                    'Une ligne manuelle ne doit pas utiliser '
                    . 'une configuration automatique.'
                )
                ->atPath('produitConfiguration')
                ->addViolation();
        }
    }


    /*
     * ========================================================
     * ANCIEN MODE LIBRE PRODUIT
     * ========================================================
     *
     * Compatibilité temporaire avec modeConfiguration = libre.
     */
    if (
        $this->isSaisieLibre()
    ) {
        if ($this->produit !== null) {
            $context
                ->buildViolation(
                    'Une prestation libre ne doit pas être liée '
                    . 'à un produit du catalogue.'
                )
                ->atPath('produit')
                ->addViolation();
        }

        if (
            $this->produitConfiguration
            !== null
        ) {
            $context
                ->buildViolation(
                    'Une prestation libre ne doit pas utiliser '
                    . 'une configuration automatique.'
                )
                ->atPath('produitConfiguration')
                ->addViolation();
        }
    }


    /*
     * ========================================================
     * PRIX
     * ========================================================
     */
    if ((float) $this->prixUnitaire <= 0) {
        $context
            ->buildViolation(
                'Le prix unitaire doit être supérieur à zéro.'
            )
            ->atPath('prixUnitaire')
            ->addViolation();
    }


    /*
     * ========================================================
     * CALCUL AU MÈTRE CARRÉ
     * ========================================================
     */
    if (
        $this->modeCalcul === 'metre_carre'
        && (
            $this->largeur === null
            || (float) $this->largeur <= 0
            || $this->longueur === null
            || (float) $this->longueur <= 0
        )
    ) {
        $context
            ->buildViolation(
                '{{ ligne }} : la largeur et la longueur sont obligatoires '
                . 'pour un calcul au mètre carré.'
            )
            ->setParameter('{{ ligne }}', $this->libelleLigneErreur())
            ->atPath('largeur')
            ->addViolation();
    }


    /*
     * ========================================================
     * CALCUL AU MÈTRE
     * ========================================================
     */
    if (
        $this->modeCalcul === 'metre'
        && (
            $this->longueur === null
            || (float) $this->longueur <= 0
        )
    ) {
        $context
            ->buildViolation(
                '{{ ligne }} : la longueur est obligatoire pour un calcul au mètre.'
            )
            ->setParameter('{{ ligne }}', $this->libelleLigneErreur())
            ->atPath('longueur')
            ->addViolation();
    }
}
    /**
     * La ligne nommée dans un message d'erreur : sa place et sa désignation
     * (« Ligne 2 « Bâche imprimée » »). L'erreur s'affiche aussi en haut du
     * formulaire, loin du volet de la ligne, et deux lignes peuvent porter
     * la même désignation.
     */
    private function libelleLigneErreur(): string
    {
        $lignes = $this->commande?->getCommandesDetails()->getValues() ?? [];
        $rang = array_search($this, $lignes, true);
        $place = false === $rang ? 'Ligne' : sprintf('Ligne %d', $rang + 1);
        $designation = trim((string) $this->designation);

        return '' === $designation ? $place : sprintf('%s « %s »', $place, $designation);
    }

    /**
     * @return list<string>
     */
    public static function getStatutsProductionAutorises(): array
    {
        return [
            self::PRODUCTION_A_PRODUIRE,
            self::PRODUCTION_EN_COURS,
            self::PRODUCTION_TERMINEE,
            self::PRODUCTION_PRETE_LIVRAISON,
            self::PRODUCTION_EN_LIVRAISON,
            self::PRODUCTION_LIVREE,
            self::PRODUCTION_ANNULEE,
            self::PRODUCTION_NON_REQUISE,
        ];
    }

    public function getStatutProduction(): string
    {
        return $this->statutProduction;
    }

    public function setStatutProduction(string $statutProduction): static
    {
        if (
            !in_array(
                $statutProduction,
                self::getStatutsProductionAutorises(),
                true
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Statut de production invalide : %s',
                    $statutProduction
                )
            );
        }

        $this->statutProduction = $statutProduction;

        return $this;
    }

    public function getStatutProductionAvantAnnulation(): ?string
    {
        return $this->statutProductionAvantAnnulation;
    }

    public function setStatutProductionAvantAnnulation(?string $statutProductionAvantAnnulation): static
    {
        $this->statutProductionAvantAnnulation = $statutProductionAvantAnnulation;

        return $this;
    }

    public function getProductionDebuteLe(): ?\DateTimeImmutable
    {
        return $this->productionDebuteLe;
    }

    public function getProductionTermineeLe(): ?\DateTimeImmutable
    {
        return $this->productionTermineeLe;
    }

    public function getPreteLivraisonLe(): ?\DateTimeImmutable
    {
        return $this->preteLivraisonLe;
    }

    /** Synchronisation (site en ligne) : la date arrive de l'ERP. */
    public function setPreteLivraisonLe(?\DateTimeImmutable $le): static
    {
        $this->preteLivraisonLe = $le;

        return $this;
    }

    public function getProductionDebuteePar(): ?User
    {
        return $this->productionDebuteePar;
    }

    public function getProductionTermineePar(): ?User
    {
        return $this->productionTermineePar;
    }

    public function getQuantiteProduite(): int
    {
        return $this->quantiteProduite;
    }

    public function setQuantiteProduite(?int $quantiteProduite): static
    {
        $this->quantiteProduite = max(0, $quantiteProduite ?? 0);

        return $this;
    }

    public function getQuantiteRebut(): int
    {
        return $this->quantiteRebut;
    }

    public function setQuantiteRebut(?int $quantiteRebut): static
    {
        $this->quantiteRebut = max(0, $quantiteRebut ?? 0);

        return $this;
    }

    public function getQuantiteConforme(): int
    {
        return max(
            0,
            $this->quantiteProduite - $this->quantiteRebut
        );
    }

    public function getQuantiteRestante(): int
    {
        return max(
            0,
            $this->quantite - $this->getQuantiteConforme()
        );
    }

    public function getObservationProduction(): ?string
    {
        return $this->observationProduction;
    }

    public function setObservationProduction(
        ?string $observationProduction
    ): static {
        $this->observationProduction = $this->nettoyerTexte(
            $observationProduction
        );

        return $this;
    }

    public function estEnProduction(): bool
    {
        return $this->statutProduction === self::PRODUCTION_EN_COURS;
    }

    public function estProductionTerminee(): bool
    {
        return $this->statutProduction === self::PRODUCTION_TERMINEE;
    }



    /**
     * Retourne le dernier contrôle prépresse.
     *
     * La collection est déjà ordonnée par :
     * #[ORM\OrderBy(['creeLe' => 'DESC'])]
     */
    public function getDernierControlePrePresse(): ?ControlePrePresse
    {
        if ($this->controlesPrePresse->isEmpty()) {
            return null;
        }

        $controle = $this->controlesPrePresse->first();

        return $controle instanceof ControlePrePresse
            ? $controle
            : null;
    }

    /**
     * Indique si un BAT est réellement nécessaire
     * pour cette ligne.
     *
     * La règle vient du contrôle prépresse.
     */
    public function isBatNecessairePourProduction(): bool
    {
        $controle = $this->getDernierControlePrePresse();

        if ($controle === null) {
            return false;
        }

        return $controle->isBatNecessaire();
    }

    /**
     * Indique si le BAT obligatoire est validé.
     *
     * Si aucun BAT n'est demandé, on retourne true.
     */
    public function isBatAutorisePourProduction(): bool
    {
        $controle = $this->getDernierControlePrePresse();

        if ($controle === null) {
            return true;
        }

        if (!$controle->isBatNecessaire()) {
            return true;
        }

        return $controle->isBatValide();
    }



    /**
     * À appeler lorsque la commande est validée.
     *
     * - fabrication : reste à produire ;
     * - vente directe : devient immédiatement prête à livrer.
     */
    public function preparerApresValidationCommande(): static
    {
        /*
     * ========================================================
     * 1. LIVRAISON DIRECTE
     * ========================================================
     *
     * Pas de prépresse.
     * Pas de production.
     */
        if (
            !$this->prePresseNecessaire
            && !$this->productionNecessaire
        ) {
            $this->statutProduction =
                self::PRODUCTION_PRETE_LIVRAISON;

            return $this;
        }

        /*
     * ========================================================
     * 2. PRODUCTION SANS PRÉPRESSE
     * ========================================================
     */
        if (
            !$this->prePresseNecessaire
            && $this->productionNecessaire
        ) {
            $this->statutProduction =
                self::PRODUCTION_A_PRODUIRE;

            return $this;
        }

        /*
     * ========================================================
     * 3. PRÉPRESSE + PRODUCTION
     * ========================================================
     *
     * La ligne reste en attente du contrôle prépresse.
     */
        if (
            $this->prePresseNecessaire
            && $this->productionNecessaire
        ) {
            $this->statutProduction =
                self::PRODUCTION_A_PRODUIRE;

            return $this;
        }

        /*
     * ========================================================
     * 4. PRÉPRESSE SANS PRODUCTION
     * ========================================================
     *
     * Cas particulier : après validation prépresse,
     * la ligne pourra aller directement à la livraison.
     */
        $this->statutProduction =
            self::PRODUCTION_NON_REQUISE;

        return $this;
    }

    /**
     * Démarrage d'une véritable production.
     */
    public function demarrerProduction(
        User $utilisateur,
        ?Machines $machine = null
    ): static {
        /*
     * Une vente directe n'entre jamais en production.
     */
        if (!$this->productionNecessaire) {
            throw new \LogicException(
                'Cette ligne de commande ne nécessite pas de production.'
            );
        }

        if (
            $this->statutProduction
            !== self::PRODUCTION_A_PRODUIRE
        ) {
            throw new \LogicException(
                'Cette production ne peut pas être démarrée dans son état actuel.'
            );
        }

        /*
     * Le BAT est contrôlé uniquement à partir
     * du dernier contrôle prépresse.
     */
        $controle = $this->getDernierControlePrePresse();

        if (
            $controle !== null
            && $controle->isBatNecessaire()
            && !$controle->isBatValide()
        ) {
            throw new \LogicException(
                'Le BAT doit être validé avant de démarrer la production.'
            );
        }

        $this->statutProduction =
            self::PRODUCTION_EN_COURS;

        $this->productionDebuteLe =
            new \DateTimeImmutable();

        $this->productionTermineeLe = null;

        $this->productionDebuteePar =
            $utilisateur;

        $this->productionTermineePar = null;

        /*
     * Remise à zéro des résultats
     * pour un nouveau démarrage.
     */
        $this->quantiteProduite = 0;
        $this->quantiteRebut = 0;
        $this->observationProduction = null;

        if ($machine !== null) {
            $this->machine = $machine;
        }

        return $this;
    }

    /**
     * « Refaire une pièce » au contrôle final (PD06) : la ligne terminée
     * repart en production ; les pièces à refaire passent en rebut.
     */
    public function rouvrirPourRefaire(int $pieces): static
    {
        if (!$this->productionNecessaire || $this->statutProduction !== self::PRODUCTION_TERMINEE) {
            throw new \LogicException('Seule une ligne terminée, pas encore prête, peut être refaite.');
        }
        if ($pieces < 1) {
            throw new \InvalidArgumentException('Indiquez au moins une pièce à refaire.');
        }
        $this->quantiteRebut += $pieces;
        $this->statutProduction = self::PRODUCTION_A_PRODUIRE;
        $this->productionTermineeLe = null;
        $this->productionTermineePar = null;

        return $this;
    }

    /**
     * Démarrage d'une pièce à refaire : sans remettre à zéro ce qui a déjà
     * été produit (le BAT a été contrôlé au premier démarrage).
     */
    public function demarrerReprise(User $utilisateur, ?Machines $machine = null): static
    {
        if (!$this->productionNecessaire || $this->statutProduction !== self::PRODUCTION_A_PRODUIRE) {
            throw new \LogicException('Cette production ne peut pas être reprise dans son état actuel.');
        }
        $this->statutProduction = self::PRODUCTION_EN_COURS;
        $this->productionDebuteLe ??= new \DateTimeImmutable();
        $this->productionDebuteePar ??= $utilisateur;
        if ($machine !== null) {
            $this->machine = $machine;
        }

        return $this;
    }

    /**
     * Terminer une production déjà démarrée.
     */
    public function terminerProduction(
        User $utilisateur,
        int $quantiteProduite,
        int $quantiteRebut = 0,
        ?string $observation = null
    ): static {
        if (!$this->productionNecessaire) {
            throw new \LogicException(
                'Cette ligne ne nécessite pas de production.'
            );
        }

        /*
     * Aucun BAT n'est contrôlé ici.
     *
     * Si la production est en cours,
     * le contrôle BAT a déjà été effectué
     * au démarrage.
     */
        if (!$this->estEnProduction()) {
            throw new \LogicException(
                'La production doit être en cours avant d’être terminée.'
            );
        }

        if ($quantiteProduite < 1) {
            throw new \InvalidArgumentException(
                'La quantité produite doit être supérieure à zéro.'
            );
        }

        if (
            $quantiteRebut < 0
            || $quantiteRebut > $quantiteProduite
        ) {
            throw new \InvalidArgumentException(
                'La quantité de rebut est incorrecte.'
            );
        }

        $quantiteConforme =
            $quantiteProduite - $quantiteRebut;

        if ($quantiteConforme < $this->quantite) {
            throw new \LogicException(
                sprintf(
                    'La quantité conforme est insuffisante : %d sur %d.',
                    $quantiteConforme,
                    $this->quantite
                )
            );
        }

        $maintenant =
            new \DateTimeImmutable();

        $this->quantiteProduite =
            $quantiteProduite;

        $this->quantiteRebut =
            $quantiteRebut;

        $this->observationProduction =
            $this->nettoyerTexte($observation);

        $this->productionTermineeLe =
            $maintenant;

        $this->productionTermineePar =
            $utilisateur;

        $this->statutProduction =
            self::PRODUCTION_TERMINEE;

        if ($this->productionDebuteLe !== null) {
            $secondes = max(
                0,
                $maintenant->getTimestamp()
                    - $this->productionDebuteLe->getTimestamp()
            );

            $this->tempsReel =
                (int) ceil($secondes / 60);
        }

        return $this;
    }

    /**
     * Rend une ligne prête à être livrée.
     *
     * Deux circuits :
     *
     * 1. fabrication :
     *    production terminée + étiquette obligatoire ;
     *
     * 2. vente directe :
     *    aucune production ni étiquette de production obligatoire.
     *
     * $colisEtiquetes : le machiniste a imprimé les étiquettes des colis
     * (poste machiniste, OP05), qui tiennent lieu d'étiquette de production.
     */
    public function marquerPreteLivraison(bool $colisEtiquetes = false): static
    {
        /*
     * ========================================================
     * LIVRAISON DIRECTE
     * ========================================================
     */
        if (!$this->productionNecessaire) {
            if (
                !in_array(
                    $this->statutProduction,
                    [
                        self::PRODUCTION_NON_REQUISE,
                        self::PRODUCTION_PRETE_LIVRAISON,
                    ],
                    true
                )
            ) {
                throw new \LogicException(
                    'Cette ligne ne peut pas être préparée pour une livraison directe.'
                );
            }

            $this->statutProduction =
                self::PRODUCTION_PRETE_LIVRAISON;
            $this->preteLivraisonLe ??= new \DateTimeImmutable();

            return $this;
        }

        /*
     * ========================================================
     * PRODUIT FABRIQUÉ
     * ========================================================
     */
        if (!$this->estProductionTerminee()) {
            throw new \LogicException(
                'La production doit être terminée avant la livraison.'
            );
        }

        /*
     * Pour une fabrication, l'étiquette reste obligatoire.
     */
        if ($this->etiquettes->isEmpty() && !$colisEtiquetes) {
            throw new \LogicException(
                'Une étiquette doit être associée à ce détail.'
            );
        }

        $this->statutProduction =
            self::PRODUCTION_PRETE_LIVRAISON;
        $this->preteLivraisonLe = new \DateTimeImmutable();

        foreach ($this->etiquettes as $etiquette) {
            if (
                $etiquette->getStatut()
                === Etiquette::STATUT_ASSOCIEE
            ) {
                $etiquette->marquerPreteLivraison();
            }
        }

        return $this;
    }

    public function marquerEnLivraison(): static
    {
        if (
            $this->statutProduction
            !== self::PRODUCTION_PRETE_LIVRAISON
        ) {
            throw new \LogicException(
                'Le détail n’est pas prêt pour la livraison.'
            );
        }

        $this->statutProduction =
            self::PRODUCTION_EN_LIVRAISON;

        /*
     * Une vente directe peut n'avoir aucune étiquette.
     */
        foreach ($this->etiquettes as $etiquette) {
            if (
                $etiquette->getStatut()
                === Etiquette::STATUT_PRETE_LIVRAISON
            ) {
                $etiquette->marquerEnLivraison();
            }
        }

        return $this;
    }

    public function marquerLivree(): static
    {
        if (
            $this->statutProduction
            !== self::PRODUCTION_EN_LIVRAISON
        ) {
            throw new \LogicException(
                'Le détail doit être en livraison.'
            );
        }

        $this->statutProduction =
            self::PRODUCTION_LIVREE;

        foreach ($this->etiquettes as $etiquette) {
            if (
                $etiquette->getStatut()
                === Etiquette::STATUT_EN_LIVRAISON
            ) {
                $etiquette->marquerLivree();
            }
        }

        return $this;
    }

    /**
     * Utilisé pour identifier explicitement
     * une ligne sans fabrication.
     */
    public function marquerProductionNonRequise(): static
    {
        if (
            !in_array(
                $this->statutProduction,
                [
                    self::PRODUCTION_A_PRODUIRE,
                    self::PRODUCTION_NON_REQUISE,
                ],
                true
            )
        ) {
            throw new \LogicException(
                'Le statut de production ne peut plus être modifié.'
            );
        }

        $this->productionNecessaire = false;

        $this->statutProduction =
            self::PRODUCTION_NON_REQUISE;

        return $this;
    }

    /**
     * @return Collection<int, Etiquette>
     */
    public function getEtiquettes(): Collection
    {
        return $this->etiquettes;
    }

    public function addEtiquette(Etiquette $etiquette): static
    {
        if (!$this->etiquettes->contains($etiquette)) {
            $this->etiquettes->add($etiquette);

            if ($etiquette->getCommandeDetail() !== $this) {
                $etiquette->setCommande(null);
                $etiquette->setCommandeDetail($this);
            }
        }

        return $this;
    }

    public function removeEtiquette(Etiquette $etiquette): static
    {
        if ($this->etiquettes->removeElement($etiquette)) {
            if ($etiquette->getCommandeDetail() === $this) {
                $etiquette->setCommandeDetail(null);
            }
        }

        return $this;
    }
    public function __toString(): string
    {
        return sprintf(
            '%s — %d unité(s)',
            $this->designation ?? 'Détail de commande',
            $this->quantite
        );
    }


    public function getModeCalcul(): string
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(?string $modeCalcul): static
    {
        $modesAutorises = [
            'forfait',
            'unite',
            'heure',
            'feuille',
            'exemplaire',
            'metre',
            'metre_carre',
            'point',
            'face',
        ];

        $modeCalcul = strtolower(trim($modeCalcul ?? ''));

        $this->modeCalcul = in_array(
            $modeCalcul,
            $modesAutorises,
            true
        ) ? $modeCalcul : 'unite';

        return $this;
    }



    public function getModeSaisie(): string
    {
        return $this->modeSaisie;
    }

    public function setModeSaisie(?string $modeSaisie): static
    {
        $this->modeSaisie = $modeSaisie ?: 'automatique';

        return $this;
    }
    /**
     * @return Collection<int, ControlePrePresse>
     */
    public function getControlesPrePresse(): Collection
    {
        return $this->controlesPrePresse;
    }

    public function addControlePrePresse(
        ControlePrePresse $controle
    ): static {
        if (!$this->controlesPrePresse->contains($controle)) {
            $this->controlesPrePresse->add($controle);
            $controle->setCommandeDetail($this);
        }

        return $this;
    }

    public function removeControlePrePresse(
        ControlePrePresse $controle
    ): static {
        if ($this->controlesPrePresse->removeElement($controle)) {
            if ($controle->getCommandeDetail() === $this) {
                $controle->setCommandeDetail(null);
            }
        }

        return $this;
    }
    public function isProductionNecessaire(): bool
    {
        return $this->productionNecessaire;
    }

    public function estLivraisonDirecte(): bool
    {
        return !$this->productionNecessaire;
    }

    public function setProductionNecessaire(
        bool $productionNecessaire
    ): static {
        $this->productionNecessaire = $productionNecessaire;

        if (
            !$productionNecessaire
            && $this->statutProduction === self::PRODUCTION_A_PRODUIRE
        ) {
            $this->statutProduction =
                self::PRODUCTION_NON_REQUISE;
        }

        if (
            $productionNecessaire
            && $this->statutProduction === self::PRODUCTION_NON_REQUISE
        ) {
            $this->statutProduction =
                self::PRODUCTION_A_PRODUIRE;
        }

        return $this;
    }

    public function getQuantiteLivree(): int
    {
        return $this->quantiteLivree;
    }

    public function setQuantiteLivree(
        ?int $quantiteLivree
    ): static {
        $this->quantiteLivree = max(
            0,
            $quantiteLivree ?? 0
        );

        return $this;
    }

    public function getQuantiteRestanteLivraison(): int
    {
        return max(
            0,
            $this->quantite - $this->quantiteLivree
        );
    }

    public function estTotalementLivree(): bool
    {
        return $this->quantiteLivree >= $this->quantite;
    }
    public function enregistrerQuantiteLivree(
        int $quantite
    ): static {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException(
                'La quantité livrée doit être supérieure à zéro.'
            );
        }

        $restante = $this->getQuantiteRestanteLivraison();

        if ($quantite > $restante) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La quantité à livrer (%d) dépasse la quantité restante (%d).',
                    $quantite,
                    $restante
                )
            );
        }

        $this->quantiteLivree += $quantite;

        /*
     * Livraison complète.
     */
        if ($this->estTotalementLivree()) {
            $this->statutProduction =
                self::PRODUCTION_LIVREE;

            return $this;
        }

        /*
     * Livraison partielle :
     * il reste encore quelque chose à livrer.
     */
        $this->statutProduction =
            self::PRODUCTION_PRETE_LIVRAISON;

        return $this;
    }
    public function isPrePresseNecessaire(): bool
    {
        return $this->prePresseNecessaire;
    }

    public function setPrePresseNecessaire(
        bool $prePresseNecessaire
    ): static {
        $this->prePresseNecessaire = $prePresseNecessaire;

        return $this;
    }
    public function getArticle(): ?Articles
    {
        return $this->article;
    }

    public function setArticle(
        ?Articles $article
    ): static {
        $this->article = $article;

        return $this;
    }

    public function getTypeLigne(): string
    {
        return $this->typeLigne;
    }

    public function setTypeLigne(
        string $typeLigne
    ): static {
        if (
            !in_array(
                $typeLigne,
                [
                    self::TYPE_PRODUIT,
                    self::TYPE_ARTICLE,
                    self::TYPE_LIBRE,
                ],
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Type de ligne invalide.'
            );
        }

        $this->typeLigne = $typeLigne;

        return $this;
    }

    public function getDemandeClient(): ?string
    {
        return $this->demandeClient;
    }

    public function setDemandeClient(?string $demandeClient): static
    {
        $demandeClient = trim((string) $demandeClient);
        $this->demandeClient = '' === $demandeClient ? null : mb_substr($demandeClient, 0, 1000);

        return $this;
    }

    /**
     * Même ligne pour une nouvelle commande (« Dupliquer », « Commander à
     * nouveau ») : même produit, mêmes mesures, mêmes prix, remises et
     * finitions, sans rien de la fabrication, des contrôles ni des fichiers
     * (DuplicationCommande les reprend à part).
     */
    public function copiePourNouvelleCommande(): self
    {
        $copie = clone $this;
        $copie->id = null;
        $copie->commande = null;
        $copie->initialiserPublicId();
        $copie->finitions = new ArrayCollection();
        $copie->fichiers = new ArrayCollection();
        $copie->productions = new ArrayCollection();
        $copie->stockSorties = new ArrayCollection();
        $copie->controlesPrePresse = new ArrayCollection();
        $copie->etiquettes = new ArrayCollection();
        foreach ($this->finitions as $finition) {
            $copie->addFinition($finition->copiePourNouvelleLigne());
        }
        $copie->jetonsFichiers = null;
        $copie->batValide = false;
        $copie->etat = true;
        $copie->tempsReel = null;
        $copie->statutProduction = self::PRODUCTION_NON_REQUISE === $this->statutProduction ? self::PRODUCTION_NON_REQUISE : self::PRODUCTION_A_PRODUIRE;
        $copie->statutProductionAvantAnnulation = null;
        $copie->productionDebuteLe = null;
        $copie->productionTermineeLe = null;
        $copie->preteLivraisonLe = null;
        $copie->productionDebuteePar = null;
        $copie->productionTermineePar = null;
        $copie->quantiteProduite = 0;
        $copie->quantiteRebut = 0;
        $copie->observationProduction = null;
        $copie->quantiteLivree = 0;
        // Une ligne au total gardé (bascule) garde aussi son total dans la copie.
        if ($this->isTotalFige()) {
            $copie->figerTotal();
        }

        return $copie;
    }

    public function getRetirePrepresseLe(): ?\DateTimeImmutable
    {
        return $this->retirePrepresseLe;
    }

    public function getRetirePrepressePar(): ?string
    {
        return $this->retirePrepressePar;
    }

    public function retirerDePrepresse(\DateTimeImmutable $le, ?string $par): static
    {
        $this->retirePrepresseLe = $le;
        $this->retirePrepressePar = null !== $par ? mb_substr($par, 0, 120) : null;

        return $this;
    }

    public function remettreEnPrepresse(): static
    {
        $this->retirePrepresseLe = null;
        $this->retirePrepressePar = null;

        return $this;
    }

    public function getFichierDecoupeRequis(): ?bool
    {
        return $this->fichierDecoupeRequis;
    }

    public function setFichierDecoupeRequis(?bool $requis): static
    {
        $this->fichierDecoupeRequis = $requis;

        return $this;
    }

    public function isDecoupeSansFichier(): bool
    {
        return $this->decoupeSansFichier;
    }

    public function setDecoupeSansFichier(bool $sansFichier): static
    {
        $this->decoupeSansFichier = $sansFichier;

        return $this;
    }

    /** @return list<string> les noms des finitions de la ligne */
    public function nomsFinitions(): array
    {
        $noms = [];
        foreach ($this->finitions as $f) {
            $noms[] = (string) ($f->getNomFinition() ?? $f->getFinition()?->getNom());
        }

        return $noms;
    }

    /** @return list<string> les finitions de découpe de la ligne (« Découpe à la forme ») */
    public function finitionsDecoupe(): array
    {
        return array_values(array_filter($this->nomsFinitions(), \App\Service\Prepresse\RegleDecoupe::estFinitionDecoupe(...)));
    }

    /** Une finition de la ligne est une découpe (sans la case de la prépresse). */
    public function aFinitionDecoupe(): bool
    {
        return \App\Service\Prepresse\RegleDecoupe::requis($this->nomsFinitions(), null);
    }

    /** La ligne demande-t-elle un fichier de découpe ? (finition de découpe, ou case de la prépresse) */
    public function besoinFichierDecoupe(): bool
    {
        return \App\Service\Prepresse\RegleDecoupe::requis($this->nomsFinitions(), $this->fichierDecoupeRequis);
    }

    /**
     * Fichiers de découpe reçus (actifs et complets), le plus récent d'abord.
     *
     * @return list<CommandeDetailFichier>
     */
    public function fichiersDecoupe(): array
    {
        $liste = array_values(array_filter($this->fichiers->toArray(), static fn (CommandeDetailFichier $f): bool => $f->estDecoupe() && $f->isActif() && $f->estUploadTermine()));
        usort($liste, static fn (CommandeDetailFichier $a, CommandeDetailFichier $b): int => [$b->getCreeLe(), $b->getId()] <=> [$a->getCreeLe(), $a->getId()]);

        return $liste;
    }

    /** Le fichier de découpe à transmettre : le plus récent, ou null. */
    public function fichierDecoupe(): ?CommandeDetailFichier
    {
        return $this->fichiersDecoupe()[0] ?? null;
    }

    /**
     * Où en est le fichier de découpe : RegleDecoupe::NON_REQUIS, PRESENT,
     * MANQUANT ou SANS_FICHIER. Le fichier lié d'un produit du client
     * (Mes produits) compte aussi.
     */
    public function etatDecoupe(): string
    {
        $decoupeLiee = \is_array($this->fichierLie['decoupe'] ?? null);
        $sansFichierLie = (bool) ($this->fichierLie['decoupeSansFichier'] ?? false);

        return \App\Service\Prepresse\RegleDecoupe::etat($this->besoinFichierDecoupe(), $decoupeLiee || null !== $this->fichierDecoupe(), $this->decoupeSansFichier || $sansFichierLie);
    }
}
