<?php

namespace App\Entity;

use App\Repository\MouvementTresorerieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(
    repositoryClass: MouvementTresorerieRepository::class
)]
#[ORM\Table(
    name: 'mouvement_tresorerie'
)]
#[ORM\Index(
    name: 'idx_mouvement_type',
    columns: ['type']
)]
#[ORM\Index(
    name: 'idx_mouvement_statut',
    columns: ['statut']
)]
#[ORM\Index(
    name: 'idx_mouvement_date',
    columns: ['date_operation']
)]
#[ORM\Index(
    name: 'idx_mouvement_categorie',
    columns: ['categorie']
)]
#[ORM\Index(
    name: 'idx_mouvement_impact_resultat',
    columns: ['impact_resultat']
)]
#[ORM\Index(
    name: 'idx_mouvement_confidentiel',
    columns: ['confidentiel']
)]
#[ORM\HasLifecycleCallbacks]
class MouvementTresorerie
{
    /*
     * ============================================================
     * TYPES DE MOUVEMENT
     * ============================================================
     */

    public const TYPE_ENCAISSEMENT =
        'encaissement';

    public const TYPE_DECAISSEMENT =
        'decaissement';

    public const TYPE_TRANSFERT =
        'transfert';


    public const TYPES = [
        self::TYPE_ENCAISSEMENT,
        self::TYPE_DECAISSEMENT,
        self::TYPE_TRANSFERT,
    ];


    public const TYPES_LABELS = [
        self::TYPE_ENCAISSEMENT =>
            'Encaissement',

        self::TYPE_DECAISSEMENT =>
            'Décaissement',

        self::TYPE_TRANSFERT =>
            'Transfert',
    ];


    /*
     * ============================================================
     * CATÉGORIES FINANCIÈRES
     * ============================================================
     *
     * PRODUITS :
     *
     * vente
     * autre_produit
     *
     * CHARGES :
     *
     * salaire
     * achat
     * carburant
     * transport
     * entretien
     * electricite
     * loyer
     * autre_charge
     *
     * NEUTRES :
     *
     * transfert_interne
     * ajustement
     * ============================================================
     */

    public const CATEGORIE_VENTE =
        'vente';

    public const CATEGORIE_AUTRE_PRODUIT =
        'autre_produit';

    public const CATEGORIE_SALAIRE =
        'salaire';

    public const CATEGORIE_ACHAT =
        'achat';

    public const CATEGORIE_CARBURANT =
        'carburant';

    public const CATEGORIE_TRANSPORT =
        'transport';

    public const CATEGORIE_ENTRETIEN =
        'entretien';

    public const CATEGORIE_ELECTRICITE =
        'electricite';

    public const CATEGORIE_LOYER =
        'loyer';

    public const CATEGORIE_AUTRE_CHARGE =
        'autre_charge';

    public const CATEGORIE_TRANSFERT_INTERNE =
        'transfert_interne';

    public const CATEGORIE_AJUSTEMENT =
        'ajustement';

    public const CATEGORIE_FRAIS_BANCAIRE =
        'frais_bancaire';

    public const CATEGORIE_REMBOURSEMENT_CREDIT =
        'remboursement_credit';


    public const CATEGORIES = [
        self::CATEGORIE_VENTE,
        self::CATEGORIE_AUTRE_PRODUIT,
        self::CATEGORIE_SALAIRE,
        self::CATEGORIE_ACHAT,
        self::CATEGORIE_CARBURANT,
        self::CATEGORIE_TRANSPORT,
        self::CATEGORIE_ENTRETIEN,
        self::CATEGORIE_ELECTRICITE,
        self::CATEGORIE_LOYER,
        self::CATEGORIE_AUTRE_CHARGE,
        self::CATEGORIE_TRANSFERT_INTERNE,
        self::CATEGORIE_AJUSTEMENT,
        self::CATEGORIE_FRAIS_BANCAIRE,
        self::CATEGORIE_REMBOURSEMENT_CREDIT,
    ];


    public const CATEGORIES_LABELS = [

        self::CATEGORIE_VENTE =>
            'Vente / prestation client',

        self::CATEGORIE_AUTRE_PRODUIT =>
            'Autre produit / recette',

        self::CATEGORIE_SALAIRE =>
            'Salaires et rémunérations',

        self::CATEGORIE_ACHAT =>
            'Achats',

        self::CATEGORIE_CARBURANT =>
            'Carburant',

        self::CATEGORIE_TRANSPORT =>
            'Transport',

        self::CATEGORIE_ENTRETIEN =>
            'Entretien / réparation',

        self::CATEGORIE_ELECTRICITE =>
            'Électricité / énergie',

        self::CATEGORIE_LOYER =>
            'Loyer',

        self::CATEGORIE_AUTRE_CHARGE =>
            'Autre charge',

        self::CATEGORIE_TRANSFERT_INTERNE =>
            'Transfert interne',

        self::CATEGORIE_AJUSTEMENT =>
            'Ajustement de trésorerie',

        self::CATEGORIE_FRAIS_BANCAIRE =>
            'Frais bancaires',

        self::CATEGORIE_REMBOURSEMENT_CREDIT =>
            'Remboursement de crédit',
    ];


    /*
     * ============================================================
     * STATUTS
     * ============================================================
     */

    public const STATUT_EN_ATTENTE =
        'en_attente';

    public const STATUT_VALIDE =
        'valide';

    public const STATUT_ANNULE =
        'annule';


    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_VALIDE,
        self::STATUT_ANNULE,
    ];


    public const STATUTS_LABELS = [
        self::STATUT_EN_ATTENTE =>
            'En attente',

        self::STATUT_VALIDE =>
            'Validé',

        self::STATUT_ANNULE =>
            'Annulé',
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
     * RÉFÉRENCE
     * ============================================================
     */

    #[ORM\Column(
        length: 50,
        unique: true
    )]
    #[Assert\NotBlank(
        message:
            'La référence est obligatoire.'
    )]
    #[Assert\Length(
        max: 50,
        maxMessage:
            'La référence ne peut pas dépasser {{ limit }} caractères.'
    )]
    private string $reference = '';


    /*
     * ============================================================
     * TYPE
     * ============================================================
     */

    #[ORM\Column(
        length: 30
    )]
    #[Assert\NotBlank]
    #[Assert\Choice(
        callback: [
            self::class,
            'getTypesDisponibles',
        ]
    )]
    private string $type =
        self::TYPE_ENCAISSEMENT;


    /*
     * ============================================================
     * CATÉGORIE FINANCIÈRE
     * ============================================================
     */

    #[ORM\Column(
        length: 50,
        options: [
            'default' =>
                self::CATEGORIE_AJUSTEMENT,
        ]
    )]
    #[Assert\NotBlank(
        message:
            'La catégorie financière est obligatoire.'
    )]
    #[Assert\Choice(
        callback: [
            self::class,
            'getCategoriesDisponibles',
        ]
    )]
    private string $categorie =
        self::CATEGORIE_AJUSTEMENT;


    /*
     * ============================================================
     * IMPACT SUR LE RÉSULTAT
     * ============================================================
     *
     * true :
     *
     * le mouvement participe au calcul :
     *
     * Produits - Charges
     *
     * false :
     *
     * transfert interne,
     * ajustement technique, etc.
     * ============================================================
     */

    #[ORM\Column(
        name: 'impact_resultat',
        type: Types::BOOLEAN,
        options: [
            'default' => false,
        ]
    )]
    private bool $impactResultat = false;


    /*
     * ============================================================
     * CONFIDENTIEL
     * ============================================================
     *
     * Exemples :
     *
     * salaire
     * rémunération sensible
     * dépense direction
     *
     * Le mouvement reste comptabilisé dans les soldes
     * et dans le rapport global Admin.
     *
     * Il est simplement masqué aux non-admins.
     * ============================================================
     */

    #[ORM\Column(
        type: Types::BOOLEAN,
        options: [
            'default' => false,
        ]
    )]
    private bool $confidentiel = false;


    /*
     * ============================================================
     * COMPTES
     * ============================================================
     *
     * Encaissement :
     *
     * compteDestination obligatoire.
     *
     * Décaissement :
     *
     * compteSource obligatoire.
     *
     * Transfert :
     *
     * compteSource + compteDestination.
     * ============================================================
     */

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'compte_source_id',
        nullable: true,
        onDelete: 'RESTRICT'
    )]
    private ?CompteTresorerie $compteSource =
        null;


    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'compte_destination_id',
        nullable: true,
        onDelete: 'RESTRICT'
    )]
    private ?CompteTresorerie $compteDestination =
        null;


    /*
     * ============================================================
     * MONTANT
     * ============================================================
     */

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive(
        message:
            'Le montant doit être supérieur à zéro.'
    )]
    private int $montant = 0;


    /*
     * ============================================================
     * DEVISE
     * ============================================================
     */

    #[ORM\Column(
        length: 10,
        options: [
            'default' => 'XOF',
        ]
    )]
    #[Assert\NotBlank]
    #[Assert\Length(
        max: 10
    )]
    private string $devise =
        'XOF';


    /*
     * ============================================================
     * MODE DE PAIEMENT
     * ============================================================
     */

    #[ORM\Column(
        length: 50,
        nullable: true
    )]
    #[Assert\Length(
        max: 50
    )]
    private ?string $modePaiement =
        null;


    /*
     * ============================================================
     * RÉFÉRENCE EXTERNE
     * ============================================================
     */

    #[ORM\Column(
        length: 100,
        nullable: true
    )]
    #[Assert\Length(
        max: 100
    )]
    private ?string $referenceExterne =
        null;


    /*
     * ============================================================
     * LIBELLÉ
     * ============================================================
     */

    #[ORM\Column(
        length: 255
    )]
    #[Assert\NotBlank]
    #[Assert\Length(
        max: 255
    )]
    private string $libelle = '';


    /*
     * ============================================================
     * DESCRIPTION
     * ============================================================
     */

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $description =
        null;


    /*
     * ============================================================
     * STATUT
     * ============================================================
     */

    #[ORM\Column(
        length: 30
    )]
    #[Assert\Choice(
        callback: [
            self::class,
            'getStatutsDisponibles',
        ]
    )]
    private string $statut =
        self::STATUT_EN_ATTENTE;


    /*
     * ============================================================
     * DATES
     * ============================================================
     */

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE
    )]
    private ?\DateTimeImmutable $dateOperation =
        null;


    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE
    )]
    private ?\DateTimeImmutable $dateCreation =
        null;


    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateValidation =
        null;


    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateAnnulation =
        null;


    /*
     * ============================================================
     * MOTIF ANNULATION
     * ============================================================
     */

    #[ORM\Column(
        type: Types::TEXT,
        nullable: true
    )]
    private ?string $motifAnnulation =
        null;


    /*
     * ============================================================
     * VÉRIFICATION ADMIN
     * ============================================================
     *
     * Champ purement déclaratif, sans aucun impact sur les
     * calculs ni sur les soldes : il permet à un administrateur
     * de marquer un mouvement (typiquement un décaissement)
     * comme relu et contrôlé.
     * ============================================================
     */

    #[ORM\Column(
        type: Types::BOOLEAN,
        options: [
            'default' => false,
        ]
    )]
    private bool $verifie = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'verifie_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $verifiePar = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateVerification = null;


    /*
     * ============================================================
     * PAIEMENT
     * ============================================================
     */

    #[ORM\OneToOne(
        inversedBy:
            'mouvementTresorerie'
    )]
    #[ORM\JoinColumn(
        name: 'paiement_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Paiements $paiement =
        null;


    /*
     * ============================================================
     * AGENT
     * ============================================================
     */

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'agent_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $agent =
        null;


    /*
     * ============================================================
     * INITIALISATION DES DATES
     * ============================================================
     */

    #[ORM\PrePersist]
    public function initialiserDates(): void
    {
        $maintenant =
            new \DateTimeImmutable();


        if (
            $this->dateCreation
            === null
        ) {
            $this->dateCreation =
                $maintenant;
        }


        if (
            $this->dateOperation
            === null
        ) {
            $this->dateOperation =
                $maintenant;
        }
    }


    /*
     * ============================================================
     * VALIDATION DES COMPTES
     * ============================================================
     */

    #[Assert\Callback]
    public function validerComptes(
        ExecutionContextInterface $context
    ): void {

        if (
            $this->type
            ===
            self::TYPE_ENCAISSEMENT
            &&
            $this->compteDestination
            === null
        ) {

            $context
                ->buildViolation(
                    'Sélectionnez le compte qui reçoit l’encaissement.'
                )
                ->atPath(
                    'compteDestination'
                )
                ->addViolation();
        }


        if (
            $this->type
            ===
            self::TYPE_DECAISSEMENT
            &&
            $this->compteSource
            === null
        ) {

            $context
                ->buildViolation(
                    'Sélectionnez le compte à débiter.'
                )
                ->atPath(
                    'compteSource'
                )
                ->addViolation();
        }


        if (
            $this->type
            ===
            self::TYPE_TRANSFERT
        ) {

            if (
                $this->compteSource
                === null
            ) {

                $context
                    ->buildViolation(
                        'Sélectionnez le compte source.'
                    )
                    ->atPath(
                        'compteSource'
                    )
                    ->addViolation();
            }


            if (
                $this->compteDestination
                === null
            ) {

                $context
                    ->buildViolation(
                        'Sélectionnez le compte destination.'
                    )
                    ->atPath(
                        'compteDestination'
                    )
                    ->addViolation();
            }


            if (
                $this->compteSource
                !== null
                &&
                $this->compteDestination
                !== null
                &&
                $this->compteSource
                ===
                $this->compteDestination
            ) {

                $context
                    ->buildViolation(
                        'Les comptes source et destination doivent être différents.'
                    )
                    ->atPath(
                        'compteDestination'
                    )
                    ->addViolation();
            }
        }


        /*
         * Un transfert interne ne doit jamais
         * affecter le résultat.
         */
        if (
            $this->type
            ===
            self::TYPE_TRANSFERT
            &&
            $this->impactResultat
        ) {

            $context
                ->buildViolation(
                    'Un transfert interne ne peut pas affecter le résultat.'
                )
                ->atPath(
                    'impactResultat'
                )
                ->addViolation();
        }
    }


    /*
     * ============================================================
     * ID
     * ============================================================
     */

    public function getId(): ?int
    {
        return $this->id;
    }


    /*
     * ============================================================
     * RÉFÉRENCE
     * ============================================================
     */

    public function getReference(): string
    {
        return $this->reference;
    }


    public function setReference(
        string $reference
    ): static {

        $reference =
            strtoupper(
                trim(
                    $reference
                )
            );


        $reference =
            preg_replace(
                '/[^A-Z0-9\-]+/',
                '-',
                $reference
            )
            ?? '';


        $this->reference =
            trim(
                $reference,
                '-'
            );


        return $this;
    }


    /*
     * ============================================================
     * TYPE
     * ============================================================
     */

    public function getType(): string
    {
        return $this->type;
    }


    public function setType(
        string $type
    ): static {

        $type =
            strtolower(
                trim(
                    $type
                )
            );


        if (
            !in_array(
                $type,
                self::TYPES,
                true
            )
        ) {

            throw new \InvalidArgumentException(
                'Le type de mouvement est invalide.'
            );
        }


        $this->type =
            $type;


        /*
         * Un transfert est toujours neutre
         * sur le résultat.
         */
        if (
            $type
            ===
            self::TYPE_TRANSFERT
        ) {

            $this->categorie =
                self::CATEGORIE_TRANSFERT_INTERNE;

            $this->impactResultat =
                false;
        }


        return $this;
    }


    public function getTypeLabel(): string
    {
        return
            self::TYPES_LABELS[
                $this->type
            ]
            ??
            $this->type;
    }


    public static function getTypesDisponibles(): array
    {
        return self::TYPES;
    }


    public static function getTypesPourFormulaire(): array
    {
        return array_flip(
            self::TYPES_LABELS
        );
    }


    /*
     * ============================================================
     * CATÉGORIE
     * ============================================================
     */

    public function getCategorie(): string
    {
        return $this->categorie;
    }


    public function setCategorie(
        string $categorie
    ): static {

        $categorie =
            strtolower(
                trim(
                    $categorie
                )
            );


        if (
            !in_array(
                $categorie,
                self::CATEGORIES,
                true
            )
        ) {

            throw new \InvalidArgumentException(
                'La catégorie financière est invalide.'
            );
        }


        $this->categorie =
            $categorie;


        /*
         * Catégories neutres.
         */
        if (
            in_array(
                $categorie,
                [
                    self::CATEGORIE_TRANSFERT_INTERNE,
                    self::CATEGORIE_AJUSTEMENT,
                ],
                true
            )
        ) {

            $this->impactResultat =
                false;
        } else {

            /*
             * Produits et charges :
             * impactent normalement le résultat.
             */
            $this->impactResultat =
                true;
        }


        return $this;
    }


    public function getCategorieLabel(): string
    {
        return
            self::CATEGORIES_LABELS[
                $this->categorie
            ]
            ??
            $this->categorie;
    }


    public static function getCategoriesDisponibles(): array
    {
        return self::CATEGORIES;
    }


    public static function getCategoriesPourFormulaire(): array
    {
        return array_flip(
            self::CATEGORIES_LABELS
        );
    }


    /*
     * ============================================================
     * CATÉGORIES PRODUITS
     * ============================================================
     */

    public static function getCategoriesProduits(): array
    {
        return [
            self::CATEGORIE_VENTE,
            self::CATEGORIE_AUTRE_PRODUIT,
        ];
    }


    /*
     * ============================================================
     * CATÉGORIES CHARGES
     * ============================================================
     */

    public static function getCategoriesCharges(): array
    {
        return [
            self::CATEGORIE_SALAIRE,
            self::CATEGORIE_ACHAT,
            self::CATEGORIE_CARBURANT,
            self::CATEGORIE_TRANSPORT,
            self::CATEGORIE_ENTRETIEN,
            self::CATEGORIE_ELECTRICITE,
            self::CATEGORIE_LOYER,
            self::CATEGORIE_AUTRE_CHARGE,
            self::CATEGORIE_FRAIS_BANCAIRE,
            self::CATEGORIE_REMBOURSEMENT_CREDIT,
        ];
    }

    /**
     * Catégories pertinentes pour un décaissement récurrent
     * (frais bancaires, remboursement de crédit, ou toute autre
     * charge fixe qui revient chaque mois ou chaque année).
     *
     * @return array<int, string>
     */
    public static function getCategoriesDecaissementRecurrent(): array
    {
        return [
            self::CATEGORIE_FRAIS_BANCAIRE,
            self::CATEGORIE_REMBOURSEMENT_CREDIT,
            self::CATEGORIE_LOYER,
            self::CATEGORIE_AUTRE_CHARGE,
        ];
    }


    /*
     * ============================================================
     * PRODUIT ?
     * ============================================================
     */

    public function estProduit(): bool
    {
        return
            $this->impactResultat
            &&
            $this->type
            ===
            self::TYPE_ENCAISSEMENT
            &&
            in_array(
                $this->categorie,
                self::getCategoriesProduits(),
                true
            );
    }


    /*
     * ============================================================
     * CHARGE ?
     * ============================================================
     */

    public function estCharge(): bool
    {
        return
            $this->impactResultat
            &&
            $this->type
            ===
            self::TYPE_DECAISSEMENT
            &&
            in_array(
                $this->categorie,
                self::getCategoriesCharges(),
                true
            );
    }


    /*
     * ============================================================
     * IMPACT RÉSULTAT
     * ============================================================
     */

    public function isImpactResultat(): bool
    {
        return $this->impactResultat;
    }


    public function setImpactResultat(
        bool $impactResultat
    ): static {

        /*
         * Un transfert interne
         * ne doit jamais affecter le résultat.
         */
        if (
            $this->type
            ===
            self::TYPE_TRANSFERT
        ) {

            $this->impactResultat =
                false;

            return $this;
        }


        if (
            in_array(
                $this->categorie,
                [
                    self::CATEGORIE_TRANSFERT_INTERNE,
                    self::CATEGORIE_AJUSTEMENT,
                ],
                true
            )
        ) {

            $this->impactResultat =
                false;

            return $this;
        }


        $this->impactResultat =
            $impactResultat;


        return $this;
    }


    /*
     * ============================================================
     * CONFIDENTIEL
     * ============================================================
     */

    public function isConfidentiel(): bool
    {
        return $this->confidentiel;
    }


    public function setConfidentiel(
        bool $confidentiel
    ): static {

        $this->confidentiel =
            $confidentiel;

        return $this;
    }


    /*
     * ============================================================
     * COMPTE SOURCE
     * ============================================================
     */

    public function getCompteSource(): ?CompteTresorerie
    {
        return $this->compteSource;
    }


    public function setCompteSource(
        ?CompteTresorerie $compteSource
    ): static {

        $this->compteSource =
            $compteSource;

        return $this;
    }


    /*
     * ============================================================
     * COMPTE DESTINATION
     * ============================================================
     */

    public function getCompteDestination(): ?CompteTresorerie
    {
        return $this->compteDestination;
    }


    public function setCompteDestination(
        ?CompteTresorerie $compteDestination
    ): static {

        $this->compteDestination =
            $compteDestination;

        return $this;
    }


    /*
     * ============================================================
     * MONTANT
     * ============================================================
     */

    public function getMontant(): int
    {
        return $this->montant;
    }


    public function setMontant(
        ?int $montant
    ): static {

        $this->montant =
            $montant
            ??
            0;

        return $this;
    }


    /*
     * ============================================================
     * DEVISE
     * ============================================================
     */

    public function getDevise(): string
    {
        return $this->devise;
    }


    public function setDevise(
        string $devise
    ): static {

        $this->devise =
            strtoupper(
                trim(
                    $devise
                )
            );

        return $this;
    }


    /*
     * ============================================================
     * MODE PAIEMENT
     * ============================================================
     */

    public function getModePaiement(): ?string
    {
        return $this->modePaiement;
    }


    public function setModePaiement(
        ?string $modePaiement
    ): static {

        $modePaiement =
            $modePaiement !== null
                ?
                trim(
                    $modePaiement
                )
                :
                null;


        $this->modePaiement =
            $modePaiement !== ''
                ?
                $modePaiement
                :
                null;


        return $this;
    }


    /*
     * ============================================================
     * RÉFÉRENCE EXTERNE
     * ============================================================
     */

    public function getReferenceExterne(): ?string
    {
        return $this->referenceExterne;
    }


    public function setReferenceExterne(
        ?string $referenceExterne
    ): static {

        $referenceExterne =
            $referenceExterne !== null
                ?
                trim(
                    $referenceExterne
                )
                :
                null;


        $this->referenceExterne =
            $referenceExterne !== ''
                ?
                $referenceExterne
                :
                null;


        return $this;
    }


    /*
     * ============================================================
     * LIBELLÉ
     * ============================================================
     */

    public function getLibelle(): string
    {
        return $this->libelle;
    }


    public function setLibelle(
        string $libelle
    ): static {

        $this->libelle =
            trim(
                $libelle
            );

        return $this;
    }


    /*
     * ============================================================
     * DESCRIPTION
     * ============================================================
     */

    public function getDescription(): ?string
    {
        return $this->description;
    }


    public function setDescription(
        ?string $description
    ): static {

        $description =
            $description !== null
                ?
                trim(
                    $description
                )
                :
                null;


        $this->description =
            $description !== ''
                ?
                $description
                :
                null;


        return $this;
    }


    /*
     * ============================================================
     * STATUT
     * ============================================================
     */

    public function getStatut(): string
    {
        return $this->statut;
    }


    public function setStatut(
        string $statut
    ): static {

        if (
            !in_array(
                $statut,
                self::STATUTS,
                true
            )
        ) {

            throw new \InvalidArgumentException(
                'Le statut du mouvement est invalide.'
            );
        }


        $this->statut =
            $statut;

        return $this;
    }


    public function getStatutLabel(): string
    {
        return
            self::STATUTS_LABELS[
                $this->statut
            ]
            ??
            $this->statut;
    }


    public static function getStatutsDisponibles(): array
    {
        return self::STATUTS;
    }


    public function isEnAttente(): bool
    {
        return
            $this->statut
            ===
            self::STATUT_EN_ATTENTE;
    }


    public function isValide(): bool
    {
        return
            $this->statut
            ===
            self::STATUT_VALIDE;
    }


    public function isAnnule(): bool
    {
        return
            $this->statut
            ===
            self::STATUT_ANNULE;
    }


    /*
     * ============================================================
     * VALIDATION MANUELLE
     * ============================================================
     */

    public function necessiteValidationManuelle(): bool
    {
        return
            $this->compteSource
                ?->estCompteBancaire()
            ||
            $this->compteDestination
                ?->estCompteBancaire();
    }


    /*
     * ============================================================
     * DATE OPÉRATION
     * ============================================================
     */

    public function getDateOperation(): ?\DateTimeImmutable
    {
        return $this->dateOperation;
    }


    public function setDateOperation(
        ?\DateTimeImmutable $dateOperation
    ): static {

        $this->dateOperation =
            $dateOperation;

        return $this;
    }


    /*
     * ============================================================
     * DATE CRÉATION
     * ============================================================
     */

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }


    /*
     * ============================================================
     * DATE VALIDATION
     * ============================================================
     */

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }


    public function setDateValidation(
        ?\DateTimeImmutable $dateValidation
    ): static {

        $this->dateValidation =
            $dateValidation;

        return $this;
    }


    /*
     * ============================================================
     * VALIDER
     * ============================================================
     */

    public function marquerCommeValide(): static
    {
        if (
            $this->isAnnule()
        ) {

            throw new \LogicException(
                'Un mouvement annulé ne peut pas être validé.'
            );
        }


        $this->statut =
            self::STATUT_VALIDE;


        $this->dateValidation =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * ANNULATION
     * ============================================================
     */

    public function getDateAnnulation(): ?\DateTimeImmutable
    {
        return $this->dateAnnulation;
    }


    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }


    public function marquerCommeAnnule(
        string $motif
    ): static {

        if (
            $this->isAnnule()
        ) {

            throw new \LogicException(
                'Ce mouvement est déjà annulé.'
            );
        }

        /*
         * Un mouvement validé peut être annulé : c'est au
         * service appelant (MouvementTresorerieService::
         * annulerValide) de contrepasser les soldes des
         * comptes AVANT d'appeler cette méthode.
         */


        $motif =
            trim(
                $motif
            );


        if (
            $motif === ''
        ) {

            throw new \InvalidArgumentException(
                'Le motif d’annulation est obligatoire.'
            );
        }


        $this->statut =
            self::STATUT_ANNULE;


        $this->motifAnnulation =
            $motif;


        $this->dateAnnulation =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * PAIEMENT
     * ============================================================
     */

    public function getPaiement(): ?Paiements
    {
        return $this->paiement;
    }


    public function setPaiement(
        ?Paiements $paiement
    ): static {

        $this->paiement =
            $paiement;


        if (
            $paiement !== null
            &&
            $paiement
                ->getMouvementTresorerie()
            !==
            $this
        ) {

            $paiement
                ->setMouvementTresorerie(
                    $this
                );
        }


        return $this;
    }


    /*
     * ============================================================
     * AGENT
     * ============================================================
     */

    public function getAgent(): ?User
    {
        return $this->agent;
    }


    public function setAgent(
        ?User $agent
    ): static {

        $this->agent =
            $agent;

        return $this;
    }


    /*
     * ============================================================
     * VÉRIFICATION ADMIN
     * ============================================================
     */

    public function isVerifie(): bool
    {
        return $this->verifie;
    }

    public function getVerifiePar(): ?User
    {
        return $this->verifiePar;
    }

    public function getDateVerification(): ?\DateTimeImmutable
    {
        return $this->dateVerification;
    }

    public function marquerCommeVerifie(User $admin): static
    {
        $this->verifie = true;
        $this->verifiePar = $admin;
        $this->dateVerification = new \DateTimeImmutable();

        return $this;
    }

    public function retirerVerification(): static
    {
        $this->verifie = false;
        $this->verifiePar = null;
        $this->dateVerification = null;

        return $this;
    }
}