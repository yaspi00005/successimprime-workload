<?php

namespace App\Entity;

use App\Repository\CompteTresorerieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(
    repositoryClass: CompteTresorerieRepository::class
)]
#[ORM\Table(
    name: 'compte_tresorerie'
)]
#[ORM\Index(
    name: 'idx_compte_tresorerie_type',
    columns: ['type']
)]
#[ORM\Index(
    name: 'idx_compte_tresorerie_portee',
    columns: ['portee']
)]
#[ORM\Index(
    name: 'idx_compte_tresorerie_proprietaire',
    columns: ['proprietaire_id']
)]
#[ORM\HasLifecycleCallbacks]
class CompteTresorerie
{
    /*
     * ============================================================
     * TYPES DE COMPTE
     * ============================================================
     */

    public const TYPE_CAISSE =
        'caisse';

    public const TYPE_ORANGE_MONEY =
        'orange_money';

    public const TYPE_WAVE =
        'wave';

    public const TYPE_BANQUE =
        'banque';


    public const TYPES = [
        self::TYPE_CAISSE,
        self::TYPE_ORANGE_MONEY,
        self::TYPE_WAVE,
        self::TYPE_BANQUE,
    ];


    public const TYPES_LABELS = [
        self::TYPE_CAISSE =>
            'Caisse',

        self::TYPE_ORANGE_MONEY =>
            'Orange Money',

        self::TYPE_WAVE =>
            'Wave',

        self::TYPE_BANQUE =>
            'Compte bancaire',
    ];


    /*
     * ============================================================
     * PORTÉE DU COMPTE
     * ============================================================
     *
     * PERSONNELLE
     * ----------
     *
     * Exemple :
     *
     * Caisse Mariam
     * Caisse Fatim
     *
     * Un seul propriétaire.
     *
     *
     * PARTAGÉE
     * -------
     *
     * Exemple :
     *
     * Orange Money principal
     * Wave principal
     *
     * Plusieurs agents peuvent l'utiliser.
     * Chaque mouvement conserve son agent.
     *
     *
     * ADMIN
     * -----
     *
     * Exemple :
     *
     * Caisse Administration
     * Banque BDM
     * Ecobank
     *
     * Réservé aux opérations administratives.
     * ============================================================
     */

    public const PORTEE_PERSONNELLE =
        'personnelle';

    public const PORTEE_PARTAGEE =
        'partagee';

    public const PORTEE_ADMIN =
        'admin';


    public const PORTEES = [
        self::PORTEE_PERSONNELLE,
        self::PORTEE_PARTAGEE,
        self::PORTEE_ADMIN,
    ];


    public const PORTEES_LABELS = [
        self::PORTEE_PERSONNELLE =>
            'Compte personnel',

        self::PORTEE_PARTAGEE =>
            'Compte partagé',

        self::PORTEE_ADMIN =>
            'Compte administration',
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
     * CODE
     * ============================================================
     */

    #[ORM\Column(
        length: 50,
        unique: true
    )]
    #[Assert\NotBlank]
    #[Assert\Length(
        max: 50
    )]
    private string $code = '';


    /*
     * ============================================================
     * NOM
     * ============================================================
     */

    #[ORM\Column(
        length: 150
    )]
    #[Assert\NotBlank]
    #[Assert\Length(
        max: 150
    )]
    private string $nom = '';


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
        self::TYPE_CAISSE;


    /*
     * ============================================================
     * PORTÉE
     * ============================================================
     */

    #[ORM\Column(
        length: 20,
        options: [
            'default' =>
                self::PORTEE_ADMIN,
        ]
    )]
    #[Assert\NotBlank]
    #[Assert\Choice(
        callback: [
            self::class,
            'getPorteesDisponibles',
        ]
    )]
    private string $portee =
        self::PORTEE_ADMIN;


    /*
     * ============================================================
     * PROPRIÉTAIRE
     * ============================================================
     *
     * Utilisé uniquement pour :
     *
     * portee = personnelle
     *
     * Exemple :
     *
     * Caisse Mariam
     * propriétaire = Mariam
     *
     * Orange Money partagé :
     * propriétaire = NULL
     *
     * Banque Admin :
     * propriétaire = NULL
     * ============================================================
     */

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'proprietaire_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $proprietaire =
        null;


    /*
     * ============================================================
     * BANQUE
     * ============================================================
     */

    #[ORM\Column(
        length: 150,
        nullable: true
    )]
    #[Assert\Length(
        max: 150
    )]
    private ?string $nomBanque =
        null;


    /*
     * ============================================================
     * NUMÉRO DE COMPTE
     * ============================================================
     */

    #[ORM\Column(
        length: 100,
        nullable: true
    )]
    #[Assert\Length(
        max: 100
    )]
    private ?string $numeroCompte =
        null;


    /*
     * ============================================================
     * TITULAIRE
     * ============================================================
     */

    #[ORM\Column(
        length: 150,
        nullable: true
    )]
    #[Assert\Length(
        max: 150
    )]
    private ?string $titulaireCompte =
        null;


    /*
     * ============================================================
     * SOLDE INITIAL
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    #[Assert\PositiveOrZero]
    private int $soldeInitial = 0;


    /*
     * ============================================================
     * SOLDE ACTUEL
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => 0,
        ]
    )]
    private int $soldeActuel = 0;


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
     * DÉCOUVERT
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => false,
        ]
    )]
    private bool $autoriserDecouvert =
        false;


    /*
     * ============================================================
     * ACTIF
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => true,
        ]
    )]
    private bool $actif =
        true;


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
     * DATES
     * ============================================================
     */

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE
    )]
    private ?\DateTimeImmutable $dateCreation =
        null;


    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateModification =
        null;


    /*
     * ============================================================
     * VALIDATION MÉTIER
     * ============================================================
     */

    #[Assert\Callback]
    public function validerConfiguration(
        ExecutionContextInterface $context
    ): void {
        /*
         * ========================================================
         * COMPTE PERSONNEL
         * ========================================================
         *
         * Un propriétaire est obligatoire.
         * ========================================================
         */

        if (
            $this->portee
            === self::PORTEE_PERSONNELLE
            &&
            $this->proprietaire
            === null
        ) {
            $context
                ->buildViolation(
                    'Un compte personnel doit obligatoirement avoir un propriétaire.'
                )
                ->atPath(
                    'proprietaire'
                )
                ->addViolation();
        }


        /*
         * ========================================================
         * COMPTE PARTAGÉ / ADMIN
         * ========================================================
         *
         * Aucun propriétaire individuel.
         * ========================================================
         */

        if (
            in_array(
                $this->portee,
                [
                    self::PORTEE_PARTAGEE,
                    self::PORTEE_ADMIN,
                ],
                true
            )
            &&
            $this->proprietaire
            !== null
        ) {
            $context
                ->buildViolation(
                    'Un compte partagé ou administratif ne doit pas avoir de propriétaire individuel.'
                )
                ->atPath(
                    'proprietaire'
                )
                ->addViolation();
        }


        /*
         * ========================================================
         * BANQUE
         * ========================================================
         */

        if (
            $this->type
            === self::TYPE_BANQUE
            &&
            $this->portee
            !== self::PORTEE_ADMIN
        ) {
            $context
                ->buildViolation(
                    'Un compte bancaire doit être un compte administratif.'
                )
                ->atPath(
                    'portee'
                )
                ->addViolation();
        }


        /*
         * ========================================================
         * ORANGE MONEY / WAVE
         * ========================================================
         *
         * Dans ton organisation :
         * ils sont partagés.
         * ========================================================
         */

        if (
            in_array(
                $this->type,
                [
                    self::TYPE_ORANGE_MONEY,
                    self::TYPE_WAVE,
                ],
                true
            )
            &&
            $this->portee
            !== self::PORTEE_PARTAGEE
        ) {
            $context
                ->buildViolation(
                    'Orange Money et Wave doivent être configurés comme comptes partagés.'
                )
                ->atPath(
                    'portee'
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
     * CODE
     * ============================================================
     */

    public function getCode(): string
    {
        return $this->code;
    }


    public function setCode(
        string $code
    ): static {
        $code =
            strtoupper(
                trim(
                    $code
                )
            );

        $code =
            preg_replace(
                '/[^A-Z0-9]+/',
                '-',
                $code
            )
            ?? '';

        $this->code =
            trim(
                $code,
                '-'
            );

        return $this;
    }


    /*
     * ============================================================
     * NOM
     * ============================================================
     */

    public function getNom(): string
    {
        return $this->nom;
    }


    public function setNom(
        string $nom
    ): static {
        $this->nom =
            trim(
                $nom
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
                sprintf(
                    'Le type de compte "%s" est invalide.',
                    $type
                )
            );
        }

        $this->type =
            $type;


        /*
         * Les informations bancaires sont supprimées
         * si le compte n'est plus bancaire.
         */

        if (
            $type !==
            self::TYPE_BANQUE
        ) {
            $this->nomBanque =
                null;
        }


        /*
         * Banque :
         * toujours Admin.
         */

        if (
            $type ===
            self::TYPE_BANQUE
        ) {
            $this->portee =
                self::PORTEE_ADMIN;

            $this->proprietaire =
                null;
        }


        /*
         * Orange Money / Wave :
         * toujours partagés.
         */

        if (
            in_array(
                $type,
                [
                    self::TYPE_ORANGE_MONEY,
                    self::TYPE_WAVE,
                ],
                true
            )
        ) {
            $this->portee =
                self::PORTEE_PARTAGEE;

            $this->proprietaire =
                null;
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
     * PORTÉE
     * ============================================================
     */

    public function getPortee(): string
    {
        return $this->portee;
    }


    public function setPortee(
        string $portee
    ): static {
        $portee =
            strtolower(
                trim(
                    $portee
                )
            );

        if (
            !in_array(
                $portee,
                self::PORTEES,
                true
            )
        ) {
            throw new \InvalidArgumentException(
                'La portée du compte est invalide.'
            );
        }


        /*
         * Banque :
         * Admin obligatoire.
         */

        if (
            $this->type
            === self::TYPE_BANQUE
        ) {
            $this->portee =
                self::PORTEE_ADMIN;

            $this->proprietaire =
                null;

            return $this;
        }


        /*
         * Orange Money / Wave :
         * partagés obligatoirement.
         */

        if (
            in_array(
                $this->type,
                [
                    self::TYPE_ORANGE_MONEY,
                    self::TYPE_WAVE,
                ],
                true
            )
        ) {
            $this->portee =
                self::PORTEE_PARTAGEE;

            $this->proprietaire =
                null;

            return $this;
        }


        $this->portee =
            $portee;


        /*
         * Un compte non personnel
         * ne conserve jamais un propriétaire.
         */

        if (
            $portee
            !== self::PORTEE_PERSONNELLE
        ) {
            $this->proprietaire =
                null;
        }


        return $this;
    }


    public function getPorteeLabel(): string
    {
        return
            self::PORTEES_LABELS[
                $this->portee
            ]
            ??
            $this->portee;
    }


    public static function getPorteesDisponibles(): array
    {
        return self::PORTEES;
    }


    public static function getPorteesPourFormulaire(): array
    {
        return array_flip(
            self::PORTEES_LABELS
        );
    }


    /*
     * ============================================================
     * PROPRIÉTAIRE
     * ============================================================
     */

    public function getProprietaire(): ?User
    {
        return $this->proprietaire;
    }


    public function setProprietaire(
        ?User $proprietaire
    ): static {
        /*
         * Un propriétaire est accepté
         * uniquement sur un compte personnel.
         */

        if (
            $this->portee
            !== self::PORTEE_PERSONNELLE
        ) {
            $this->proprietaire =
                null;

            return $this;
        }


        $this->proprietaire =
            $proprietaire;

        return $this;
    }


    /*
     * ============================================================
     * OUTILS PORTÉE
     * ============================================================
     */

    public function estPersonnel(): bool
    {
        return
            $this->portee
            ===
            self::PORTEE_PERSONNELLE;
    }


    public function estPartage(): bool
    {
        return
            $this->portee
            ===
            self::PORTEE_PARTAGEE;
    }


    public function estAdministratif(): bool
    {
        return
            $this->portee
            ===
            self::PORTEE_ADMIN;
    }


    /*
     * ============================================================
     * APPARTIENT À UN UTILISATEUR ?
     * ============================================================
     */

    public function appartientA(
        ?User $user
    ): bool {
        return
            $user !== null
            &&
            $this->estPersonnel()
            &&
            $this->proprietaire
            ===
            $user;
    }


    /*
     * ============================================================
     * BANQUE
     * ============================================================
     */

    public function getNomBanque(): ?string
    {
        return $this->nomBanque;
    }


    public function setNomBanque(
        ?string $nomBanque
    ): static {
        $nomBanque =
            $nomBanque !== null
                ?
                trim(
                    $nomBanque
                )
                :
                null;

        $this->nomBanque =
            $nomBanque !== ''
                ?
                $nomBanque
                :
                null;

        return $this;
    }


    /*
     * ============================================================
     * NUMÉRO COMPTE
     * ============================================================
     */

    public function getNumeroCompte(): ?string
    {
        return $this->numeroCompte;
    }


    public function setNumeroCompte(
        ?string $numeroCompte
    ): static {
        $numeroCompte =
            $numeroCompte !== null
                ?
                trim(
                    $numeroCompte
                )
                :
                null;

        $this->numeroCompte =
            $numeroCompte !== ''
                ?
                $numeroCompte
                :
                null;

        return $this;
    }


    /*
     * ============================================================
     * TITULAIRE
     * ============================================================
     */

    public function getTitulaireCompte(): ?string
    {
        return $this->titulaireCompte;
    }


    public function setTitulaireCompte(
        ?string $titulaireCompte
    ): static {
        $titulaireCompte =
            $titulaireCompte !== null
                ?
                trim(
                    $titulaireCompte
                )
                :
                null;

        $this->titulaireCompte =
            $titulaireCompte !== ''
                ?
                $titulaireCompte
                :
                null;

        return $this;
    }


    /*
     * ============================================================
     * SOLDE INITIAL
     * ============================================================
     */

    public function getSoldeInitial(): int
    {
        return $this->soldeInitial;
    }


    public function setSoldeInitial(
        ?int $soldeInitial
    ): static {
        $soldeInitial =
            max(
                0,
                $soldeInitial
                ?? 0
            );


        /*
         * À la création uniquement :
         * le solde actuel démarre
         * avec le solde initial.
         */

        if (
            $this->id
            === null
        ) {
            $this->soldeActuel =
                $soldeInitial;
        }


        $this->soldeInitial =
            $soldeInitial;

        return $this;
    }


    /*
     * ============================================================
     * SOLDE ACTUEL
     * ============================================================
     */

    public function getSoldeActuel(): int
    {
        return $this->soldeActuel;
    }


    public function setSoldeActuel(
        int $soldeActuel
    ): static {
        if (
            !$this->autoriserDecouvert
            &&
            $soldeActuel < 0
        ) {
            throw new \LogicException(
                'Le solde du compte ne peut pas être négatif.'
            );
        }

        $this->soldeActuel =
            $soldeActuel;

        return $this;
    }


    /*
     * ============================================================
     * CRÉDITER
     * ============================================================
     */

    public function crediter(
        int $montant
    ): static {
        if (
            $montant <= 0
        ) {
            throw new \InvalidArgumentException(
                'Le montant à créditer doit être supérieur à zéro.'
            );
        }


        $this->soldeActuel +=
            $montant;

        return $this;
    }


    /*
     * ============================================================
     * DÉBITER
     * ============================================================
     */

    public function debiter(
        int $montant
    ): static {
        if (
            $montant <= 0
        ) {
            throw new \InvalidArgumentException(
                'Le montant à débiter doit être supérieur à zéro.'
            );
        }


        $nouveauSolde =
            $this->soldeActuel
            -
            $montant;


        if (
            !$this->autoriserDecouvert
            &&
            $nouveauSolde < 0
        ) {
            throw new \LogicException(
                sprintf(
                    'Solde insuffisant sur le compte "%s".',
                    $this->nom
                )
            );
        }


        $this->soldeActuel =
            $nouveauSolde;

        return $this;
    }


    /*
     * ============================================================
     * PEUT ÊTRE DÉBITÉ ?
     * ============================================================
     */

    public function peutEtreDebite(
        int $montant
    ): bool {
        if (
            $montant <= 0
        ) {
            return false;
        }


        return
            $this->autoriserDecouvert
            ||
            $this->soldeActuel
            >=
            $montant;
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
        ?string $devise
    ): static {
        $devise =
            strtoupper(
                trim(
                    $devise
                    ?? ''
                )
            );


        $this->devise =
            $devise !== ''
                ?
                $devise
                :
                'XOF';

        return $this;
    }


    /*
     * ============================================================
     * DÉCOUVERT
     * ============================================================
     */

    public function isAutoriserDecouvert(): bool
    {
        return $this->autoriserDecouvert;
    }


    public function setAutoriserDecouvert(
        bool $autoriserDecouvert
    ): static {
        $this->autoriserDecouvert =
            $autoriserDecouvert;

        return $this;
    }


    /*
     * ============================================================
     * ACTIF
     * ============================================================
     */

    public function isActif(): bool
    {
        return $this->actif;
    }


    public function setActif(
        bool $actif
    ): static {
        $this->actif =
            $actif;

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
     * DATES
     * ============================================================
     */

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }


    public function setDateCreation(
        \DateTimeImmutable $dateCreation
    ): static {
        $this->dateCreation =
            $dateCreation;

        return $this;
    }


    public function getDateModification(): ?\DateTimeImmutable
    {
        return $this->dateModification;
    }


    public function setDateModification(
        ?\DateTimeImmutable $dateModification
    ): static {
        $this->dateModification =
            $dateModification;

        return $this;
    }


    /*
     * ============================================================
     * OUTILS TYPE
     * ============================================================
     */

    public function estCompteBancaire(): bool
    {
        return
            $this->type
            ===
            self::TYPE_BANQUE;
    }


    public function estCaisse(): bool
    {
        return
            $this->type
            ===
            self::TYPE_CAISSE;
    }


    public function estMobileMoney(): bool
    {
        return in_array(
            $this->type,
            [
                self::TYPE_ORANGE_MONEY,
                self::TYPE_WAVE,
            ],
            true
        );
    }


    /*
     * ============================================================
     * LIFECYCLE
     * ============================================================
     */

    #[ORM\PrePersist]
    public function initialiserDates(): void
    {
        $maintenant =
            new \DateTimeImmutable();

        $this->dateCreation ??=
            $maintenant;

        $this->dateModification =
            $maintenant;
    }


    #[ORM\PreUpdate]
    public function actualiserDateModification(): void
    {
        $this->dateModification =
            new \DateTimeImmutable();
    }


    /*
     * ============================================================
     * __TOSTRING
     * ============================================================
     */

   public function __toString(): string
{
    $suffixe =
        $this->getPorteeLabel();

    if (
        $this->estPersonnel()
        &&
        $this->proprietaire !== null
    ) {
        $nomProprietaire =
            $this->proprietaire
                ->getUsername();

        $suffixe =
            sprintf(
                '%s — %s',
                $suffixe,
                $nomProprietaire
                ?: 'Utilisateur #'
                    . $this->proprietaire
                        ->getId()
            );
    }

    return sprintf(
        '%s — %s — %s',
        $this->nom,
        $this->getTypeLabel(),
        $suffixe
    );
}
}