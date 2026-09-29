<?php

namespace App\Entity;

use App\Repository\FacturesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FacturesRepository::class)]
#[ORM\Table(name: 'factures')]
#[ORM\UniqueConstraint(
    name: 'uniq_facture_numero',
    columns: ['numero']
)]
#[ORM\Index(
    name: 'idx_facture_commande',
    columns: ['commande_id']
)]
#[ORM\Index(
    name: 'idx_facture_type_document',
    columns: ['type_document']
)]
#[ORM\Index(
    name: 'idx_facture_comptabilisee',
    columns: ['comptabilisee']
)]
#[ORM\HasLifecycleCallbacks]
class Factures
{
    /*
     * ============================================================
     * TYPES DE DOCUMENT
     * ============================================================
     */

    public const TYPE_FACTURE = 'facture';
    public const TYPE_PROFORMA = 'proforma';

    public const TYPES_DOCUMENT = [
        self::TYPE_FACTURE,
        self::TYPE_PROFORMA,
    ];

    public const TYPES_DOCUMENT_LABELS = [
        self::TYPE_FACTURE =>
            'Facture officielle',

        self::TYPE_PROFORMA =>
            'Pro forma / Simulation',
    ];


    /*
     * ============================================================
     * ÉMETTEURS
     * ============================================================
     */

    public const EMETTEUR_DREPA = 'drepa';
    public const EMETTEUR_MDG_SUCCESS = 'mdg_success';
    public const EMETTEUR_MDG = 'mdg';

    public const EMETTEURS = [
        self::EMETTEUR_DREPA,
        self::EMETTEUR_MDG_SUCCESS,
        self::EMETTEUR_MDG,
    ];

    public const EMETTEURS_LABELS = [
        self::EMETTEUR_DREPA =>
            'DREPA TECHNOLOGIE',

        self::EMETTEUR_MDG_SUCCESS =>
            'MADIAL GROUP SARL / SUCCESS IMPRIM',

        self::EMETTEUR_MDG =>
            'MADIAL GROUP SARL',
    ];


    /*
     * ============================================================
     * STATUTS PAIEMENT
     * ============================================================
     */

    public const STATUT_IMPAYEE = 'impayee';
    public const STATUT_PARTIELLE = 'partielle';
    public const STATUT_PAYEE = 'payee';

    public const STATUTS_PAIEMENT = [
        self::STATUT_IMPAYEE,
        self::STATUT_PARTIELLE,
        self::STATUT_PAYEE,
    ];

    public const STATUTS_PAIEMENT_LABELS = [
        self::STATUT_IMPAYEE =>
            'Impayée',

        self::STATUT_PARTIELLE =>
            'Paiement partiel',

        self::STATUT_PAYEE =>
            'Payée',
    ];


    /*
     * ============================================================
     * ÉTATS
     * ============================================================
     */

    public const ETAT_BROUILLON = 'brouillon';
    public const ETAT_EMISE = 'emise';
    public const ETAT_ANNULEE = 'annulee';

    public const ETATS = [
        self::ETAT_BROUILLON,
        self::ETAT_EMISE,
        self::ETAT_ANNULEE,
    ];

    public const ETATS_LABELS = [
        self::ETAT_BROUILLON =>
            'Brouillon',

        self::ETAT_EMISE =>
            'Émise',

        self::ETAT_ANNULEE =>
            'Annulée',
    ];


    /*
     * ============================================================
     * ID
     * ============================================================
     */

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;


    /*
     * ============================================================
     * COMMANDE
     * ============================================================
     */

    #[ORM\ManyToOne(
        inversedBy: 'factures'
    )]
    #[ORM\JoinColumn(
        name: 'commande_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    #[Assert\NotNull]
    private ?Commandes $commande = null;


    /*
     * ============================================================
     * IDENTIFICATION
     * ============================================================
     */

    #[ORM\Column(
        length: 50,
        unique: true,
        nullable: true
    )]
    private ?string $numero = null;


    /*
     * ============================================================
     * TYPE DE DOCUMENT
     * ============================================================
     */

    #[ORM\Column(
        name: 'type_document',
        length: 20,
        options: [
            'default' => self::TYPE_PROFORMA,
        ]
    )]
    #[Assert\Choice(
        choices: self::TYPES_DOCUMENT
    )]
    private string $typeDocument =
        self::TYPE_PROFORMA;


    /*
     * ============================================================
     * COMPTABILISATION
     * ============================================================
     *
     * TRUE :
     * facture officielle réelle.
     *
     * FALSE :
     * pro forma / simulation / document commercial.
     */
    #[ORM\Column(
        options: [
            'default' => false,
        ]
    )]
    private bool $comptabilisee = false;


    /*
     * ============================================================
     * ÉMETTEUR
     * ============================================================
     */

    #[ORM\Column(
        length: 30,
        options: [
            'default' =>
                self::EMETTEUR_MDG_SUCCESS,
        ]
    )]
    #[Assert\Choice(
        choices: self::EMETTEURS
    )]
    private string $emetteur =
        self::EMETTEUR_MDG_SUCCESS;


        #[ORM\Column(
    length: 255,
    nullable: true
)]
private ?string $pdfFichier = null;


#[ORM\Column(
    nullable: true
)]
private ?\DateTimeImmutable $pdfGenereLe = null;


#[ORM\Column(
    length: 64,
    nullable: true
)]
private ?string $pdfHash = null;
    /*
     * ============================================================
     * DATES
     * ============================================================
     */

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE
    )]
    private ?\DateTimeImmutable $dateFacture = null;


    #[ORM\Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateEcheance = null;


    /*
     * ============================================================
     * MONTANTS DE BASE
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $totalHt = 0;


    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $remise = 0;


    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $tva = 0;


    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $montantTva = 0;


    /*
     * ============================================================
     * MAJORATION / SURFACTURATION
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => false,
        ]
    )]
    private bool $surfacturation = false;


    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 8,
        scale: 2,
        nullable: true
    )]
    private ?string $tauxSurfacturation = null;


    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $montantSurfacturation = 0;


    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $motifSurfacturation = null;


    /*
     * ============================================================
     * TOTAL FINAL DU DOCUMENT
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $totalTtc = 0;


    /*
     * ============================================================
     * PAIEMENTS
     * ============================================================
     *
     * Utilisés uniquement pour la facture comptabilisée.
     */

    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $montantPaye = 0;


    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $resteAPayer = 0;


    #[ORM\Column(
        name: 'statut_paiement',
        length: 20,
        options: [
            'default' =>
                self::STATUT_IMPAYEE,
        ]
    )]
    private string $statutPaiement =
        self::STATUT_IMPAYEE;


    /*
     * ============================================================
     * ÉTAT DU DOCUMENT
     * ============================================================
     */

    #[ORM\Column(
        length: 20,
        options: [
            'default' =>
                self::ETAT_BROUILLON,
        ]
    )]
    private string $etat =
        self::ETAT_BROUILLON;


    /*
     * ============================================================
     * OBSERVATION
     * ============================================================
     */

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $observation = null;


    /*
     * ============================================================
     * AUTHENTICITÉ
     * ============================================================
     */

    #[ORM\Column(
        length: 64,
        unique: true,
        nullable: true
    )]
    private ?string $tokenAuthenticite = null;


    /*
     * ============================================================
     * FACTURATION À UN TIERS
     * ============================================================
     *
     * Le demandeur n'est pas toujours celui qui paie (ex : un
     * employé demande, son entreprise règle et reçoit la
     * facture). Quand facturerAUnTiers est actif, ces coordonnées
     * remplacent celles du client sur le PDF, sans modifier la
     * fiche client elle-même.
     * ============================================================
     */

    #[ORM\Column(options: ['default' => false])]
    private bool $facturerAUnTiers = false;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nomFacturation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseFacturation = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephoneFacturation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'L’adresse email de facturation n’est pas valide.')]
    private ?string $emailFacturation = null;


    /*
     * ============================================================
     * AUDIT
     * ============================================================
     */

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE
    )]
    private ?\DateTimeImmutable $createdAt = null;


    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $updatedAt = null;


    /*
     * ============================================================
     * PAIEMENTS LIÉS
     * ============================================================
     */




    /**
     * @var Collection<int, Paiements>
     */
    #[ORM\OneToMany(
        mappedBy: 'facture',
        targetEntity: Paiements::class
    )]
    private Collection $paiements;


    public function __construct()
    {
        $this->dateFacture =
            new \DateTimeImmutable();

        $this->createdAt =
            new \DateTimeImmutable();

        $this->paiements =
            new ArrayCollection();
    }


    public function getId(): ?int
    {
        return $this->id;
    }


    /*
     * ============================================================
     * COMMANDE
     * ============================================================
     */

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(
        ?Commandes $commande
    ): static {
        $this->commande = $commande;

        return $this;
    }


    /*
     * ============================================================
     * NUMÉRO
     * ============================================================
     */

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(
        ?string $numero
    ): static {
        $numero =
            $numero !== null
                ? trim($numero)
                : null;

        $this->numero =
            $numero !== ''
                ? $numero
                : null;

        return $this;
    }


    /*
     * ============================================================
     * TYPE DOCUMENT
     * ============================================================
     */

    public function getTypeDocument(): string
    {
        return $this->typeDocument;
    }

    public function setTypeDocument(
        string $typeDocument
    ): static {
        if (
            !in_array(
                $typeDocument,
                self::TYPES_DOCUMENT,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Type de document de facturation invalide.'
            );
        }

        $this->typeDocument =
            $typeDocument;

        /*
         * Une pro forma ne doit jamais être
         * comptabilisée.
         */
        if (
            $typeDocument
            === self::TYPE_PROFORMA
        ) {
            $this->comptabilisee = false;
        }

        return $this;
    }

    public function getTypeDocumentLabel():
        string
    {
        return self::TYPES_DOCUMENT_LABELS[
            $this->typeDocument
        ] ?? $this->typeDocument;
    }

    public function estFacture(): bool
    {
        return $this->typeDocument
            === self::TYPE_FACTURE;
    }

    public function estProforma(): bool
    {
        return $this->typeDocument
            === self::TYPE_PROFORMA;
    }


    /*
     * ============================================================
     * COMPTABILISÉE
     * ============================================================
     */

    public function isComptabilisee(): bool
    {
        return $this->comptabilisee;
    }

    public function setComptabilisee(
        bool $comptabilisee
    ): static {
        if (
            $comptabilisee
            && $this->typeDocument
                !== self::TYPE_FACTURE
        ) {
            throw new \LogicException(
                'Seule une facture officielle peut être comptabilisée.'
            );
        }

        $this->comptabilisee =
            $comptabilisee;

        return $this;
    }


    /*
     * ============================================================
     * ÉMETTEUR
     * ============================================================
     */

    public function getEmetteur(): string
    {
        return $this->emetteur;
    }

    public function setEmetteur(
        string $emetteur
    ): static {
        if (
            !in_array(
                $emetteur,
                self::EMETTEURS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'Émetteur invalide.'
            );
        }

        $this->emetteur =
            $emetteur;

        return $this;
    }

    public function getEmetteurLabel(): string
    {
        return self::EMETTEURS_LABELS[
            $this->emetteur
        ] ?? $this->emetteur;
    }


    /*
     * ============================================================
     * DATES
     * ============================================================
     */

    public function getDateFacture():
        ?\DateTimeImmutable
    {
        return $this->dateFacture;
    }

    public function setDateFacture(
        \DateTimeImmutable $dateFacture
    ): static {
        $this->dateFacture =
            $dateFacture;

        return $this;
    }


    public function getDateEcheance():
        ?\DateTimeImmutable
    {
        return $this->dateEcheance;
    }

    public function setDateEcheance(
        ?\DateTimeImmutable $dateEcheance
    ): static {
        $this->dateEcheance =
            $dateEcheance;

        return $this;
    }


    /*
     * ============================================================
     * MONTANTS
     * ============================================================
     */

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function setTotalHt(
        ?int $totalHt
    ): static {
        $this->totalHt =
            max(
                0,
                $totalHt ?? 0
            );

        $this->recalculerTotal();

        return $this;
    }


    public function getRemise(): int
    {
        return $this->remise;
    }

    public function setRemise(
        ?int $remise
    ): static {
        $this->remise =
            max(
                0,
                $remise ?? 0
            );

        $this->recalculerTotal();

        return $this;
    }


    public function getTva(): int
    {
        return $this->tva;
    }

    public function setTva(
        ?int $tva
    ): static {
        $this->tva =
            max(
                0,
                $tva ?? 0
            );

        return $this;
    }


    public function getMontantTva(): int
    {
        return $this->montantTva;
    }

    public function setMontantTva(
        ?int $montantTva
    ): static {
        $this->montantTva =
            max(
                0,
                $montantTva ?? 0
            );

        $this->recalculerTotal();

        return $this;
    }


    /*
     * ============================================================
     * SURFACTURATION / MAJORATION
     * ============================================================
     */

    public function isSurfacturation(): bool
    {
        return $this->surfacturation;
    }

    public function setSurfacturation(
        bool $surfacturation
    ): static {
        $this->surfacturation =
            $surfacturation;

        if (!$surfacturation) {
            $this->tauxSurfacturation = null;
            $this->montantSurfacturation = 0;
            $this->motifSurfacturation = null;
        }

        $this->recalculerTotal();

        return $this;
    }


    public function getTauxSurfacturation():
        ?float
    {
        return $this->tauxSurfacturation !== null
            ? (float) $this->tauxSurfacturation
            : null;
    }

    public function setTauxSurfacturation(
        float|int|string|null $taux
    ): static {
        if ($taux === null || $taux === '') {
            $this->tauxSurfacturation = null;

            return $this;
        }

        $valeur =
            max(
                0,
                (float) $taux
            );

        $this->tauxSurfacturation =
            number_format(
                $valeur,
                2,
                '.',
                ''
            );

        return $this;
    }


    public function getMontantSurfacturation():
        int
    {
        return $this->montantSurfacturation;
    }

    public function setMontantSurfacturation(
        ?int $montant
    ): static {
        $this->montantSurfacturation =
            max(
                0,
                $montant ?? 0
            );

        if ($this->montantSurfacturation > 0) {
            $this->surfacturation = true;
        }

        $this->recalculerTotal();

        return $this;
    }


    public function getMotifSurfacturation():
        ?string
    {
        return $this->motifSurfacturation;
    }

    public function setMotifSurfacturation(
        ?string $motif
    ): static {
        $motif =
            $motif !== null
                ? trim($motif)
                : null;

        $this->motifSurfacturation =
            $motif !== ''
                ? $motif
                : null;

        return $this;
    }


    /*
     * Calcul automatique depuis un pourcentage.
     */
    public function appliquerTauxSurfacturation(
        float $taux
    ): static {
        if ($taux < 0) {
            throw new \InvalidArgumentException(
                'Le taux de majoration ne peut pas être négatif.'
            );
        }

        $this->surfacturation = true;

        $this->setTauxSurfacturation(
            $taux
        );

        /*
         * La majoration est calculée sur
         * le montant TTC de base.
         */
        $base =
            max(
                0,
                $this->totalHt
                - $this->remise
                + $this->montantTva
            );

        $this->montantSurfacturation =
            (int) round(
                $base
                * ($taux / 100)
            );

        $this->recalculerTotal();

        return $this;
    }


    /*
     * ============================================================
     * TOTAL TTC
     * ============================================================
     */

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }


    public function recalculerTotal(): static
    {
        $base =
            max(
                0,
                $this->totalHt
                - $this->remise
                + $this->montantTva
            );

        $majoration =
            $this->surfacturation
                ? $this->montantSurfacturation
                : 0;

        $this->totalTtc =
            max(
                0,
                $base
                + $majoration
            );

        $this->recalculerSituationPaiement();

        return $this;
    }


    /*
     * ============================================================
     * PAIEMENT
     * ============================================================
     */

    public function getMontantPaye(): int
    {
        return $this->montantPaye;
    }

    public function setMontantPaye(
        ?int $montantPaye
    ): static {
        $this->montantPaye =
            max(
                0,
                $montantPaye ?? 0
            );

        $this->recalculerSituationPaiement();

        return $this;
    }


    public function getResteAPayer(): int
    {
        return $this->resteAPayer;
    }


    public function getStatutPaiement(): string
    {
        return $this->statutPaiement;
    }

    public function getStatutPaiementLabel():
        string
    {
        return self::STATUTS_PAIEMENT_LABELS[
            $this->statutPaiement
        ] ?? $this->statutPaiement;
    }


    public function recalculerSituationPaiement():
        static
    {
        /*
         * Une simulation ne participe pas
         * au paiement réel.
         */
        if (!$this->comptabilisee) {
            $this->montantPaye = 0;
            $this->resteAPayer = $this->totalTtc;
            $this->statutPaiement =
                self::STATUT_IMPAYEE;

            return $this;
        }

        $this->resteAPayer =
            max(
                0,
                $this->totalTtc
                - $this->montantPaye
            );

        if (
            $this->totalTtc > 0
            && $this->resteAPayer <= 0
        ) {
            $this->statutPaiement =
                self::STATUT_PAYEE;

        } elseif (
            $this->montantPaye > 0
        ) {
            $this->statutPaiement =
                self::STATUT_PARTIELLE;

        } else {
            $this->statutPaiement =
                self::STATUT_IMPAYEE;
        }

        return $this;
    }


    /*
     * ============================================================
     * ÉTAT
     * ============================================================
     */

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function setEtat(
        string $etat
    ): static {
        if (
            !in_array(
                $etat,
                self::ETATS,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'État de facture invalide.'
            );
        }

        $this->etat =
            $etat;

        return $this;
    }

    public function estBrouillon(): bool
    {
        return $this->etat
            === self::ETAT_BROUILLON;
    }

    public function estEmise(): bool
    {
        return $this->etat
            === self::ETAT_EMISE;
    }

    public function estAnnulee(): bool
    {
        return $this->etat
            === self::ETAT_ANNULEE;
    }


    /*
     * ============================================================
     * OBSERVATION
     * ============================================================
     */

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(
        ?string $observation
    ): static {
        $observation =
            $observation !== null
                ? trim($observation)
                : null;

        $this->observation =
            $observation !== ''
                ? $observation
                : null;

        return $this;
    }


    /*
     * ============================================================
     * TOKEN
     * ============================================================
     */

    public function getTokenAuthenticite():
        ?string
    {
        return $this->tokenAuthenticite;
    }

    public function genererTokenAuthenticite():
        static
    {
        if ($this->tokenAuthenticite === null) {
            $this->tokenAuthenticite =
                bin2hex(
                    random_bytes(16)
                );
        }

        return $this;
    }


    /*
     * ============================================================
     * FACTURATION À UN TIERS
     * ============================================================
     */

    public function isFacturerAUnTiers(): bool
    {
        return $this->facturerAUnTiers;
    }

    public function setFacturerAUnTiers(bool $facturerAUnTiers): static
    {
        $this->facturerAUnTiers = $facturerAUnTiers;

        return $this;
    }

    public function getNomFacturation(): ?string
    {
        return $this->nomFacturation;
    }

    public function setNomFacturation(?string $nomFacturation): static
    {
        $this->nomFacturation = $nomFacturation;

        return $this;
    }

    public function getAdresseFacturation(): ?string
    {
        return $this->adresseFacturation;
    }

    public function setAdresseFacturation(?string $adresseFacturation): static
    {
        $this->adresseFacturation = $adresseFacturation;

        return $this;
    }

    public function getTelephoneFacturation(): ?string
    {
        return $this->telephoneFacturation;
    }

    public function setTelephoneFacturation(?string $telephoneFacturation): static
    {
        $this->telephoneFacturation = $telephoneFacturation;

        return $this;
    }

    public function getEmailFacturation(): ?string
    {
        return $this->emailFacturation;
    }

    public function setEmailFacturation(?string $emailFacturation): static
    {
        $this->emailFacturation = $emailFacturation;

        return $this;
    }


    /*
     * ============================================================
     * AUDIT
     * ============================================================
     */

    public function getCreatedAt():
        ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt():
        ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }


    /*
     * ============================================================
     * PAIEMENTS LIÉS
     * ============================================================
     */

    /**
     * @return Collection<int, Paiements>
     */
    public function getPaiements():
        Collection
    {
        return $this->paiements;
    }

    public function addPaiement(
        Paiements $paiement
    ): static {
        /*
         * Une pro forma ne reçoit pas
         * de paiement réel.
         */
        if (!$this->comptabilisee) {
            throw new \LogicException(
                'Un paiement ne peut être rattaché qu’à une facture comptabilisée.'
            );
        }

        if (
            !$this->paiements
                ->contains($paiement)
        ) {
            $this->paiements
                ->add($paiement);

            $paiement->setFacture(
                $this
            );
        }

        return $this;
    }

    public function removePaiement(
        Paiements $paiement
    ): static {
        if (
            $this->paiements
                ->removeElement($paiement)
        ) {
            if (
                $paiement->getFacture()
                === $this
            ) {
                $paiement->setFacture(
                    null
                );
            }
        }

        return $this;
    }


    /*
     * ============================================================
     * COPIE DES MONTANTS DE LA COMMANDE
     * ============================================================
     *
     * Les montants sont copiés et stockés.
     * Ils ne seront plus recalculés automatiquement
     * après création du document.
     */
    public function chargerDepuisCommande(
        Commandes $commande
    ): static {
        $this->commande =
            $commande;

        $this->totalHt =
            (int) (
                $commande->getTotalHt()
                ?? 0
            );

        $this->remise =
            (int) (
                $commande->getRemise()
                ?? 0
            );

        $this->tva =
            (int) (
                $commande->getTva()
                ?? 0
            );

        $commandeTtc =
            (int) (
                $commande->getTotalTtc()
                ?? 0
            );

        $this->montantTva =
            max(
                0,
                $commandeTtc
                - max(
                    0,
                    $this->totalHt
                    - $this->remise
                )
            );

        $this->recalculerTotal();

        /*
         * Les montants viennent d'être rechargés depuis la
         * commande (bouton "Actualiser") : un éventuel PDF déjà
         * archivé afficherait des montants obsolètes. On
         * l'invalide pour forcer une régénération à la prochaine
         * consultation.
         */
        $this->pdfFichier = null;
        $this->pdfHash = null;
        $this->pdfGenereLe = null;

        return $this;
    }


    /*
     * ============================================================
     * SYNCHRONISER UNIQUEMENT LES PAIEMENTS
     * ============================================================
     *
     * On ne modifie PAS les montants de la facture.
     */
    public function synchroniserPaiementsDepuisCommande():
    static
{
    if (
        !$this->comptabilisee
        || $this->commande === null
    ) {
        return $this;
    }

    $montantPayeAvant = $this->montantPaye;

    $totalPaye = 0;

    foreach (
        $this->commande->getPaiements()
        as $paiement
    ) {

        /*
         * Seulement les paiements
         * réellement validés.
         */
        if (
            !$paiement->estValide()
        ) {
            continue;
        }

        $totalPaye +=
            (int) $paiement->getMontant();
    }


    $this->montantPaye =
        $totalPaye;


    $this->resteAPayer =
        max(
            0,
            $this->totalTtc
            - $this->montantPaye
        );


    if (
        $this->totalTtc > 0
        && $this->resteAPayer <= 0
    ) {

        $this->statutPaiement =
            self::STATUT_PAYEE;

    } elseif (
        $this->montantPaye > 0
    ) {

        $this->statutPaiement =
            self::STATUT_PARTIELLE;

    } else {

        $this->statutPaiement =
            self::STATUT_IMPAYEE;
    }


    /*
     * Le PDF déjà archivé affiche un "reste à payer" figé au
     * moment de sa génération. Si le montant payé vient
     * réellement de changer, l'ancien PDF est invalidé : la
     * prochaine consultation en régénère un à jour (les montants
     * facturés, eux, restent inchangés). Sans ce test, la simple
     * consultation de la facture (qui appelle cette méthode à
     * chaque affichage) forcerait une régénération à chaque fois.
     */
    if ($this->montantPaye !== $montantPayeAvant) {
        $this->pdfFichier = null;
        $this->pdfHash = null;
        $this->pdfGenereLe = null;
    }


    return $this;
}


    /*
     * ============================================================
     * LIFECYCLE
     * ============================================================
     */

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->dateFacture ??=
            new \DateTimeImmutable();

        $this->createdAt ??=
            new \DateTimeImmutable();

        $this->genererTokenAuthenticite();

        $this->recalculerTotal();
    }


    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt =
            new \DateTimeImmutable();

        $this->recalculerTotal();
    }


    /*
     * ============================================================
     * CHOICES
     * ============================================================
     */

    public static function getTypesDocumentPourFormulaire():
        array
    {
        return array_flip(
            self::TYPES_DOCUMENT_LABELS
        );
    }

    public static function getEmetteursPourFormulaire():
        array
    {
        return array_flip(
            self::EMETTEURS_LABELS
        );
    }


    public function __toString(): string
    {
        return $this->numero
            ?? sprintf(
                '%s #%d',
                $this->getTypeDocumentLabel(),
                $this->id ?? 0
            );
    }
    public function getPdfFichier(): ?string
{
    return $this->pdfFichier;
}

public function setPdfFichier(
    ?string $pdfFichier
): static {
    $this->pdfFichier =
        $pdfFichier;

    return $this;
}


public function getPdfGenereLe():
    ?\DateTimeImmutable
{
    return $this->pdfGenereLe;
}

public function setPdfGenereLe(
    ?\DateTimeImmutable $pdfGenereLe
): static {
    $this->pdfGenereLe =
        $pdfGenereLe;

    return $this;
}


public function getPdfHash(): ?string
{
    return $this->pdfHash;
}

public function setPdfHash(
    ?string $pdfHash
): static {
    $this->pdfHash =
        $pdfHash;

    return $this;
}


public function hasPdfArchive(): bool
{
    return
        $this->pdfFichier !== null
        && trim($this->pdfFichier) !== '';
}
}