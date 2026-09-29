<?php

namespace App\Entity;

use App\Repository\DevisDetailsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;


#[ORM\Entity(repositoryClass: DevisDetailsRepository::class)]
#[ORM\Table(name: 'devis_details')]
class DevisDetails
{

public const TYPE_PRODUIT = 'produit';
public const TYPE_ARTICLE = 'article';
public const TYPE_LIBRE = 'libre';
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'devisDetails')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Devis $devis = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Produits $produit = null;



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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?TypesImpression $typeImpression = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supports $support = null;

   
   #[ORM\ManyToOne]
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

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, options: ['default' => 0])]
    #[Assert\PositiveOrZero(
        message: 'Le prix unitaire ne peut pas être négatif.'
    )]
    private string $prixUnitaire = '0';

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $coutRevient = 0;

    /*
     * Remise en FCFA par unité facturable (par m², par mètre
     * linéaire, par exemplaire... selon le mode de calcul), pas un
     * montant fixe sur toute la ligne ni un pourcentage : aligné sur
     * le module commande.
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

  #[ORM\Column(
    length: 20,
    options: ['default' => 'produit']
)]
private string $typeLigne = self::TYPE_PRODUIT;

#[ORM\ManyToOne]
#[ORM\JoinColumn(
    nullable: true,
    onDelete: 'SET NULL'
)]
private ?Articles $article = null;
#[ORM\Column(
    length: 30,
    options: ['default' => 'automatique']
)]
private string $modeSaisie = 'automatique';

    /*
     * Champ temporaire du formulaire.
     * Il n’est pas enregistré directement dans cette table.
     */
    private ?string $jetonsFichiers = null;

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
     * Ancien champ conservé pour les anciennes devis.
     * Les nouveaux fichiers sont enregistrés dans $fichiers.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fichier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    /**
     * @var Collection<int, DevisDetailFinition>
     */
    #[ORM\OneToMany(
        targetEntity: DevisDetailFinition::class,
        mappedBy: 'devisDetail',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Valid]
    private Collection $finitions;



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
    public function __construct()
    {
        $this->finitions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function setDevis(?Devis $devis): static
    {
        $this->devis = $devis;

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
     * Applique la configuration au détail de devis.
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

            'metre_carre' => (float) ($this->surface ?? 0)
                * $this->quantite,

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
        return (float) ($this->surface ?? 0) * $this->quantite;
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

    public function calculerTotaux(
        bool $clientB2B = false
    ): static {
        /*
     * Reprend les données fiables de ProduitConfiguration,
     * puis calcule ou verrouille les dimensions.
     */
        if (
    $this->typeLigne === self::TYPE_PRODUIT
    && $this->isConfigurationAutomatique()
) {
    $this->appliquerConfiguration(
        $clientB2B
    );
}

       if (
    $this->typeLigne === self::TYPE_PRODUIT
    && !$this->isConfigurationAutomatique()
    && $this->modeCalcul === 'metre_carre'
) {
    $this->calculerSurface();
}

        $this->appliquerDimensionsALaDesignation();

        $totalBrut = $this->calculerTotalBrut();
        $montantRemise = $this->calculerMontantRemise($totalBrut);

        $this->totalHt = max(
            0,
            $totalBrut - $montantRemise
        );

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

    /*
     * La remise est un montant par unité facturable (ex. 500 FCFA par
     * m²), pas un montant fixe sur toute la ligne : elle se multiplie
     * donc par le même facteur que le prix unitaire
     * (calculerFacteur()), plafonné au total brut de la ligne.
     */
    private function calculerMontantRemise(int $totalBrut): int
    {
        return min(
            $totalBrut,
            max(0, (int) round($this->remise * $this->calculerFacteur()))
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
        return $this->calculerMontantRemise($this->calculerTotalBrut());
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
     * @return Collection<int, DevisDetailFinition>
     */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

  public function addFinition(
    DevisDetailFinition $finition
): static {
    $configuration = $finition->getConfigurationFinition();

    if ($configuration !== null) {
        foreach ($this->finitions as $existante) {
            if (
                $existante !== $finition
                && $existante->getConfigurationFinition()?->getId()
                    === $configuration->getId()
            ) {
                return $this;
            }
        }
    }

    if    (!$this->finitions->contains($finition)) {
        $this->finitions->add($finition);
        $finition->setDevisDetail($this);
    }

    return $this;
}


   #[Assert\Callback]
public function validerModeDevis(
    ExecutionContextInterface $context
): void {
    /*
     * ========================================================
     * ARTICLE EN STOCK
     * ========================================================
     */
    if (
        $this->typeLigne
        === self::TYPE_ARTICLE
    ) {
        if ($this->article === null) {
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
     * SAISIE LIBRE
     * ========================================================
     */
    if (
        $this->typeLigne
        === self::TYPE_LIBRE
    ) {
        if (
            trim(
                (string) $this->designation
            ) === ''
        ) {
            $context
                ->buildViolation(
                    'La désignation est obligatoire.'
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
     * PRODUIT
     * ========================================================
     */
    if (
        $this->typeLigne
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


    if ($this->isConfigurationAutomatique()) {
        if (
            $this->produitConfiguration
            === null
        ) {
            $context
                ->buildViolation(
                    'Une configuration est obligatoire en mode automatique.'
                )
                ->atPath(
                    'produitConfiguration'
                )
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


    if ($this->isConfigurationManuelle()) {
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
                ->atPath(
                    'produitConfiguration'
                )
                ->addViolation();
        }
    }


    if ((float) $this->prixUnitaire <= 0) {
        $context
            ->buildViolation(
                'Le prix unitaire doit être supérieur à zéro.'
            )
            ->atPath('prixUnitaire')
            ->addViolation();
    }


    if (
        $this->modeCalcul
        === 'metre_carre'
        && (
            $this->largeur === null
            || (float) $this->largeur <= 0
            || $this->longueur === null
            || (float) $this->longueur <= 0
        )
    ) {
        $context
            ->buildViolation(
                'La largeur et la longueur sont obligatoires '
                . 'pour un calcul au mètre carré.'
            )
            ->atPath('largeur')
            ->addViolation();
    }


    if (
        $this->modeCalcul
        === 'metre'
        && (
            $this->longueur === null
            || (float) $this->longueur <= 0
        )
    ) {
        $context
            ->buildViolation(
                'La longueur est obligatoire pour un calcul au mètre.'
            )
            ->atPath('longueur')
            ->addViolation();
    }
}
    public function __toString(): string
    {
        return sprintf(
            '%s — %d unité(s)',
            $this->designation ?? 'Détail de devis',
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


   public function removeFinition(
    DevisDetailFinition $finition
): static {
    if ($this->finitions->removeElement($finition)) {
        if ($finition->getDevisDetail() === $this) {
            $finition->setDevisDetail(null);
        }
    }

    return $this;
}


#[Assert\Callback]
public function validerFinitionsUniques(
    ExecutionContextInterface $context
): void {
    $configurationsRencontrees = [];
    $finitionsRencontrees = [];

    foreach ($this->finitions as $index => $detailFinition) {
        $configurationId = $detailFinition
            ->getConfigurationFinition()
            ?->getId();

        if ($configurationId !== null) {
            if (isset($configurationsRencontrees[$configurationId])) {
                $context
                    ->buildViolation(
                        'Une même finition ne peut pas être ajoutée '
                        . 'deux fois au même détail.'
                    )
                    ->atPath(sprintf(
                        'finitions[%d].configurationFinition',
                        $index
                    ))
                    ->addViolation();

                continue;
            }

            $configurationsRencontrees[$configurationId] = true;
            continue;
        }

        // Pour les finitions manuelles sans configuration.
        $finitionId = $detailFinition->getFinition()?->getId();

        if ($finitionId !== null) {
            if (isset($finitionsRencontrees[$finitionId])) {
                $context
                    ->buildViolation(
                        'Une même finition ne peut pas être ajoutée '
                        . 'deux fois au même détail.'
                    )
                    ->atPath(sprintf(
                        'finitions[%d].finition',
                        $index
                    ))
                    ->addViolation();

                continue;
            }

            $finitionsRencontrees[$finitionId] = true;
        }
    }
}

public function getTypeLigne(): string
{
    return $this->typeLigne;
}

public function setTypeLigne(
    ?string $typeLigne
): static {
    $typeLigne =
        strtolower(
            trim(
                $typeLigne ?? ''
            )
        );

    $this->typeLigne =
        in_array(
            $typeLigne,
            [
                self::TYPE_PRODUIT,
                self::TYPE_ARTICLE,
                self::TYPE_LIBRE,
            ],
            true
        )
            ? $typeLigne
            : self::TYPE_PRODUIT;

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
}
