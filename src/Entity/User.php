<?php

namespace App\Entity;

use App\Repository\UserRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;


#[ORM\Entity(
    repositoryClass: UserRepository::class
)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(
    name: 'UNIQ_IDENTIFIER_USERNAME',
    fields: ['username']
)]
class User implements
    UserInterface,
    PasswordAuthenticatedUserInterface
{
    /*
     * ============================================================
     * RÔLES UTILISATEUR ATTRIBUABLES
     * ============================================================
     *
     * IMPORTANT :
     *
     * Seuls les rôles ci-dessous sont enregistrés
     * directement dans la colonne JSON "roles".
     *
     * Les permissions techniques comme :
     *
     * ROLE_CLIENT
     * ROLE_DEVIS
     * ROLE_COMMANDE
     * ROLE_FACTURE
     * ROLE_PAIEMENT
     * ROLE_TRESORERIE_VOIR
     * ROLE_TRESORERIE_SAISIR
     * ROLE_PREPRESSE
     * ROLE_PRODUCTION_VOIR
     * ROLE_PRODUCTION_GERER
     *
     * seront héritées depuis security.yaml.
     *
     * Elles ne doivent pas être choisies directement
     * dans la gestion des utilisateurs.
     * ============================================================
     */


    /*
     * ============================================================
     * ADMINISTRATEUR
     * ============================================================
     *
     * Accès global :
     *
     * - utilisateurs ;
     * - paramètres ;
     * - clients ;
     * - devis ;
     * - commandes ;
     * - factures ;
     * - paiements ;
     * - trésorerie ;
     * - statistiques globales ;
     * - graphisme ;
     * - prépresse ;
     * - production ;
     * - stock ;
     * - inventaire ;
     * - achats ;
     * - fournisseurs ;
     * - livraison ;
     * - RH ;
     * - etc.
     * ============================================================
     */

    public const ROLE_ADMIN =
        'ROLE_ADMIN';


    /*
     * ============================================================
     * CAISSE / COMMANDES
     * ============================================================
     *
     * Profil opérationnel commercial.
     *
     * Peut gérer :
     *
     * - clients ;
     * - devis ;
     * - commandes ;
     * - factures ;
     * - paiements ;
     * - encaissements ;
     * - décaissements ;
     * - suivi de commande ;
     * - livraison selon les permissions configurées.
     *
     * Chaque utilisateur de ce profil pourra également
     * consulter SES statistiques personnelles :
     *
     * - journalier ;
     * - hebdomadaire ;
     * - mensuel ;
     * - annuel.
     *
     * Il ne voit PAS automatiquement :
     *
     * - chiffre d'affaires global ;
     * - bénéfices ;
     * - marges ;
     * - total global des entrées ;
     * - total global des sorties ;
     * - stock.
     * ============================================================
     */

    public const ROLE_CAISSE_COMMANDE =
        'ROLE_CAISSE_COMMANDE';


    /*
     * ============================================================
     * INFOGRAPHISTE / PRÉPRESSE
     * ============================================================
     *
     * Ce profil correspond aux infographistes.
     *
     * Il peut accéder :
     *
     * - aux commandes nécessaires à son travail ;
     * - aux fichiers clients ;
     * - aux miniatures ;
     * - aux informations techniques ;
     * - au graphisme ;
     * - au BAT ;
     * - au contrôle prépresse ;
     * - à l'envoi vers la production ;
     * - à la production.
     *
     * IMPORTANT :
     *
     * LE PRÉPRESSE EST RÉSERVÉ AUX INFOGRAPHISTES.
     *
     * ROLE_PRODUCTION ne devra donc jamais hériter
     * de la permission ROLE_PREPRESSE.
     * ============================================================
     */

    public const ROLE_GRAPHISTE =
        'ROLE_GRAPHISTE';


    /*
     * ============================================================
     * PRODUCTION
     * ============================================================
     *
     * Accès uniquement à la production.
     *
     * Peut notamment :
     *
     * - consulter les ordres ;
     * - voir les fichiers utiles ;
     * - voir les miniatures ;
     * - voir les dimensions ;
     * - voir le support ;
     * - voir le format ;
     * - voir les finitions ;
     * - voir les observations ;
     * - gérer les étiquettes ;
     * - démarrer un travail ;
     * - terminer un travail.
     *
     * N'A PAS ACCÈS :
     *
     * - au prépresse ;
     * - à la caisse ;
     * - aux paiements ;
     * - aux statistiques financières ;
     * - au stock ;
     * - à l'administration.
     * ============================================================
     */

    public const ROLE_PRODUCTION =
        'ROLE_PRODUCTION';


    /*
     * ============================================================
     * LIVREUR
     * ============================================================
     *
     * Accès uniquement à la livraison.
     *
     * Un livreur qui se connecte arrive directement sur la
     * page des livraisons et n'a accès à aucune autre section
     * de l'application.
     * ============================================================
     */

    public const ROLE_LIVREUR =
        'ROLE_LIVREUR';


    /*
     * ============================================================
     * STATISTIQUES GLOBALES
     * ============================================================
     *
     * Permission complémentaire sensible.
     *
     * Permet notamment de consulter :
     *
     * - chiffre d'affaires global ;
     * - total des entrées ;
     * - total des sorties ;
     * - bénéfices ;
     * - marges ;
     * - résultats ;
     * - statistiques financières consolidées ;
     * - statistiques comparatives globales.
     *
     * Cette permission peut être associée à
     * ROLE_CAISSE_COMMANDE, ROLE_GRAPHISTE
     * ou ROLE_PRODUCTION si nécessaire.
     *
     * ROLE_ADMIN la possédera automatiquement
     * via security.yaml.
     * ============================================================
     */

    public const ROLE_STATS_GLOBAL =
        'ROLE_STATS_GLOBAL';


    /*
     * ============================================================
     * INFORMATIONS SENSIBLES
     * ============================================================
     *
     * Comme ROLE_STATS_GLOBAL, ces autorisations s'activent ou se
     * desactivent une par une pour chaque utilisateur, en plus de
     * son profil principal (demande de Yaya du 10/10/2026).
     * ============================================================
     */

    public const ROLE_STATS_VENTES =
        'ROLE_STATS_VENTES';

    public const ROLE_COUT_MARGE =
        'ROLE_COUT_MARGE';

    public const ROLE_PAIEMENT_VOIR =
        'ROLE_PAIEMENT_VOIR';


    /*
     * ============================================================
     * RÔLE TECHNIQUE SYMFONY
     * ============================================================
     *
     * Tous les utilisateurs authentifiés possèdent
     * automatiquement ROLE_USER.
     *
     * Il n'est jamais enregistré dans la base.
     * ============================================================
     */

    public const ROLE_USER =
        'ROLE_USER';


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
     * IDENTIFIANT
     * ============================================================
     */

    #[ORM\Column(
        length: 180
    )]
    private ?string $username = null;


    /*
     * ============================================================
     * RÔLES
     * ============================================================
     */

    #[ORM\Column]
    private array $roles = [];


    /*
     * Role de base (Reglages, Roles et droits) et les droits ajoutes ou
     * retires pour cette personne seulement (fiche utilisateur, U02).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?RoleUtilisateur $profil = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $droitsAjoutes = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $droitsRetires = [];

    /** Objectif de ventes du mois en F TTC (Mes statistiques, U05). */
    #[ORM\Column(nullable: true)]
    private ?int $objectifVentesMois = null;


    /*
     * ============================================================
     * MOT DE PASSE HASHÉ
     * ============================================================
     */

    #[ORM\Column]
    private ?string $password = null;


    /*
     * ============================================================
     * COMMANDES
     * ============================================================
     */

    /**
     * @var Collection<int, Commandes>
     */
    #[ORM\OneToMany(
        targetEntity: Commandes::class,
        mappedBy: 'agents'
    )]
    private Collection $commandes;


    /*
     * ============================================================
     * COMPTE ACTIF
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => true,
        ]
    )]
    private bool $actif = true;


    /*
     * ============================================================
     * EMPLOYÉ
     * ============================================================
     *
     * Un utilisateur doit être associé à un employé.
     *
     * Un employé peut exister sans compte utilisateur.
     *
     * La relation OneToOne empêche plusieurs comptes
     * pour le même employé.
     * ============================================================
     */

    #[ORM\OneToOne(
        inversedBy: 'user',
        cascade: ['persist']
    )]
    #[ORM\JoinColumn(
        nullable: false,
        unique: true
    )]
    private ?Employes $employe = null;


    /*
     * ============================================================
     * DATE DE CRÉATION
     * ============================================================
     */

    #[ORM\Column]
    private ?\DateTimeImmutable $dateAdd = null;


    /*
     * ============================================================
     * DERNIÈRE MODIFICATION
     * ============================================================
     */

    #[ORM\Column]
    private ?\DateTimeImmutable $dateUpdate = null;


    /*
     * ============================================================
     * CHANGEMENT DE MOT DE PASSE OBLIGATOIRE
     * ============================================================
     */

    #[ORM\Column(
        options: [
            'default' => true,
        ]
    )]
    private bool $mustChangePassword = true;


    /*
     * ============================================================
     * DATE DU DERNIER CHANGEMENT DU MOT DE PASSE
     * ============================================================
     */

    #[ORM\Column(
        nullable: true
    )]
    private ?\DateTimeImmutable $passwordChangedAt = null;


    /*
     * ============================================================
     * DERNIÈRE CONNEXION
     * ============================================================
     */

    #[ORM\Column(
        nullable: true
    )]
    private ?\DateTimeImmutable $lastLoginAt = null;


    /*
     * ============================================================
     * DATE DE DÉSACTIVATION
     * ============================================================
     */

    #[ORM\Column(
        nullable: true
    )]
    private ?\DateTimeImmutable $disabledAt = null;


    /*
     * ============================================================
     * MOTIF DE DÉSACTIVATION
     * ============================================================
     */

    #[ORM\Column(
        length: 255,
        nullable: true
    )]
    private ?string $motifDesactivation = null;


    /*
     * ============================================================
     * PAIEMENTS ENCAISSÉS PAR L'UTILISATEUR
     * ============================================================
     *
     * Permet notamment de calculer les statistiques
     * personnelles de chaque encaisseur :
     *
     * - aujourd'hui ;
     * - semaine ;
     * - mois ;
     * - année.
     *
     * Relation inverse de :
     *
     * Paiements::$encaissePar
     * ============================================================
     */

    /**
     * @var Collection<int, Paiements>
     */
    #[ORM\OneToMany(
        targetEntity: Paiements::class,
        mappedBy: 'encaissePar'
    )]
    private Collection $paiements;


    /*
     * ============================================================
     * CONSTRUCTEUR
     * ============================================================
     */

    public function __construct()
    {
        $this->commandes =
            new ArrayCollection();

        $this->paiements =
            new ArrayCollection();

        $this->actif =
            true;

        $this->mustChangePassword =
            true;

        $this->dateAdd =
            new \DateTimeImmutable();

        $this->dateUpdate =
            new \DateTimeImmutable();
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
     * USERNAME
     * ============================================================
     */

    public function getUsername(): ?string
    {
        return $this->username;
    }


    public function setUsername(
        string $username
    ): static {

        $username =
            trim(
                mb_strtolower(
                    $username
                )
            );


        if ($username === '') {

            throw new \InvalidArgumentException(
                'Le nom d’utilisateur ne peut pas être vide.'
            );
        }


        $this->username =
            $username;

        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * IDENTIFIANT SYMFONY
     * ============================================================
     */

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }


    /*
     * ============================================================
     * EFFACEMENT CREDENTIALS
     * ============================================================
     */

    public function eraseCredentials(): void
    {
    }


    /*
     * ============================================================
     * RÔLES AUTORISÉS
     * ============================================================
     *
     * Cette liste correspond uniquement aux rôles
     * qu'un administrateur peut attribuer depuis
     * l'interface utilisateur.
     * ============================================================
     */

    public static function getRolesAutorises(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_CAISSE_COMMANDE,
            self::ROLE_GRAPHISTE,
            self::ROLE_PRODUCTION,
            self::ROLE_LIVREUR,
            ...array_keys(self::getPermissionsSensibles()),
        ];
    }


    /**
     * Informations sensibles qu'on montre ou cache a chaque
     * utilisateur, avec ce qu'elles donnent a voir.
     *
     * @return array<string, array{libelle: string, detail: string}>
     */
    public static function getPermissionsSensibles(): array
    {
        return [
            self::ROLE_STATS_GLOBAL => [
                'libelle' => 'Statistiques globales',
                'detail' => 'chiffre d\'affaires, encaissements et créances de l\'accueil, écrans Statistiques',
            ],
            self::ROLE_STATS_VENTES => [
                'libelle' => 'Indicateurs commerciaux',
                'detail' => 'devis envoyés, taux de transformation et panier moyen (écran Ventes)',
            ],
            self::ROLE_COUT_MARGE => [
                'libelle' => 'Coût de revient et marge',
                'detail' => 'coût et marge des lignes de commande et des produits du client',
            ],
            self::ROLE_PAIEMENT_VOIR => [
                'libelle' => 'Paiements',
                'detail' => 'paiements des commandes et écran Caisse (toujours inclus pour Caisse / Commandes)',
            ],
        ];
    }


    /*
     * ============================================================
     * LIBELLÉS DES RÔLES
     * ============================================================
     */

    public static function getLibellesRoles(): array
    {
        return [
            self::ROLE_ADMIN =>
                'Administrateur',

            self::ROLE_CAISSE_COMMANDE =>
                'Caisse / Commandes',

            self::ROLE_GRAPHISTE =>
                'Infographiste / Prépresse',

            self::ROLE_PRODUCTION =>
                'Production',

            self::ROLE_LIVREUR =>
                'Livreur',

            ...array_map(
                static fn (array $permission): string => $permission['libelle'],
                self::getPermissionsSensibles()
            ),
        ];
    }


    /*
     * ============================================================
     * RÔLES
     * ============================================================
     */

    public function getRoles(): array
    {
        $roles =
            $this->roles;


        /*
         * Hors administrateur, les droits viennent du role de base,
         * plus ceux ajoutes, moins ceux retires pour la personne.
         */
        if (!in_array(self::ROLE_ADMIN, $roles, true)) {
            $roles = array_merge($roles, $this->getDroitsEffectifs());
        }


        /*
         * Tout utilisateur authentifié possède
         * automatiquement ROLE_USER.
         */
        $roles[] =
            self::ROLE_USER;


        return array_values(
            array_unique(
                $roles
            )
        );
    }


    /*
     * ============================================================
     * ATTRIBUTION DES RÔLES
     * ============================================================
     *
     * Règles :
     *
     * 1. ROLE_ADMIN est exclusif.
     *
     * 2. Un utilisateur normal possède
     *    UN SEUL profil principal parmi :
     *
     *    - ROLE_CAISSE_COMMANDE
     *    - ROLE_GRAPHISTE
     *    - ROLE_PRODUCTION
     *
     * 3. ROLE_STATS_GLOBAL peut être ajouté
     *    comme permission complémentaire.
     *
     * Exemples autorisés :
     *
     * ROLE_CAISSE_COMMANDE
     *
     * ROLE_CAISSE_COMMANDE
     * + ROLE_STATS_GLOBAL
     *
     * ROLE_GRAPHISTE
     *
     * ROLE_GRAPHISTE
     * + ROLE_STATS_GLOBAL
     *
     * ROLE_PRODUCTION
     *
     * Exemples interdits :
     *
     * ROLE_CAISSE_COMMANDE + ROLE_GRAPHISTE
     *
     * ROLE_GRAPHISTE + ROLE_PRODUCTION
     *
     * ROLE_CAISSE_COMMANDE + ROLE_PRODUCTION
     * ============================================================
     */

    /**
     * @param list<string> $roles
     */
    public function setRoles(
        array $roles
    ): static {

        /*
         * ========================================================
         * NORMALISATION
         * ========================================================
         */

        $roles =
            array_map(
                static fn (
                    mixed $role
                ): string =>
                    strtoupper(
                        trim(
                            (string) $role
                        )
                    ),
                $roles
            );


        /*
         * Suppression des valeurs vides.
         */
        $roles =
            array_filter(
                $roles,
                static fn (
                    string $role
                ): bool =>
                    $role !== ''
            );


        /*
         * ROLE_USER est ajouté automatiquement.
         *
         * Il ne doit jamais être enregistré
         * dans la colonne JSON.
         */
        $roles =
            array_filter(
                $roles,
                static fn (
                    string $role
                ): bool =>
                    $role !== self::ROLE_USER
            );


        /*
         * ========================================================
         * SÉCURITÉ SERVEUR
         * ========================================================
         *
         * Le navigateur ne décide jamais des rôles acceptés.
         *
         * Même en modifiant manuellement une requête,
         * seules les valeurs autorisées ici peuvent
         * être enregistrées.
         * ========================================================
         */

        $roles =
            array_values(
                array_unique(
                    array_intersect(
                        $roles,
                        self::getRolesAutorises()
                    )
                )
            );


        /*
         * ========================================================
         * ADMINISTRATEUR
         * ========================================================
         *
         * ROLE_ADMIN représente l'accès global.
         *
         * Il est donc enregistré seul.
         * ========================================================
         */

        if (
            in_array(
                self::ROLE_ADMIN,
                $roles,
                true
            )
        ) {

            $this->roles = [
                self::ROLE_ADMIN,
            ];


            $this->dateUpdate =
                new \DateTimeImmutable();


            return $this;
        }


        /*
         * ========================================================
         * PROFILS PRINCIPAUX
         * ========================================================
         */

        $profilsPrincipaux = [
            self::ROLE_CAISSE_COMMANDE,
            self::ROLE_GRAPHISTE,
            self::ROLE_PRODUCTION,
            self::ROLE_LIVREUR,
        ];


        $profilsSelectionnes =
            array_values(
                array_intersect(
                    $roles,
                    $profilsPrincipaux
                )
            );


        /*
         * Aucun profil principal.
         */
        if (
            count(
                $profilsSelectionnes
            ) === 0
        ) {

            throw new \InvalidArgumentException(
                'Veuillez attribuer un profil principal à l’utilisateur.'
            );
        }


        /*
         * Plusieurs profils principaux.
         */
        if (
            count(
                $profilsSelectionnes
            ) > 1
        ) {

            throw new \InvalidArgumentException(
                'Un utilisateur ne peut avoir qu’un seul profil principal.'
            );
        }


        /*
         * ========================================================
         * CONSTRUCTION DES RÔLES FINAUX
         * ========================================================
         */

        $rolesFinals = [
            $profilsSelectionnes[0],
        ];


        /*
         * Informations sensibles cochees en complement.
         */
        foreach (array_keys(self::getPermissionsSensibles()) as $permission) {
            if (in_array($permission, $roles, true)) {
                $rolesFinals[] = $permission;
            }
        }


        $this->roles =
            array_values(
                array_unique(
                    $rolesFinals
                )
            );


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    public function getProfil(): ?RoleUtilisateur
    {
        return $this->profil;
    }

    /**
     * Change le role de base. Le role Symfony du profil remplace
     * l'ancien dans la colonne roles, les informations sensibles
     * cochees a part sont gardees.
     */
    public function setProfil(?RoleUtilisateur $profil): static
    {
        $this->profil = $profil;

        if (null !== $profil) {
            $this->roles = $profil->estAdmin()
                ? [self::ROLE_ADMIN]
                : [$profil->getCode()];
            $this->dateUpdate = new \DateTimeImmutable();
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDroitsAjoutes(): array
    {
        return \App\Security\Droits::filtrer($this->droitsAjoutes);
    }

    /**
     * @return list<string>
     */
    public function getDroitsRetires(): array
    {
        return \App\Security\Droits::filtrer($this->droitsRetires);
    }

    /**
     * Enregistre les droits coches sur la fiche : ce qui differe du
     * role de base devient un droit ajoute ou retire.
     *
     * @param array<mixed> $droitsCoches
     */
    public function definirDroits(array $droitsCoches): static
    {
        $coches = \App\Security\Droits::filtrer($droitsCoches);
        $duRole = $this->profil?->getDroits() ?? [];

        // Les cases ne vivent plus dans la colonne roles (ancien ecran).
        $this->roles = array_values(array_diff($this->roles, array_keys(\App\Security\Droits::catalogue())));
        $this->droitsAjoutes = array_values(array_diff($coches, $duRole));
        $this->droitsRetires = array_values(array_diff($duRole, $coches));
        $this->dateUpdate = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getDroitsEffectifs(): array
    {
        if ($this->profil?->estAdmin() || in_array(self::ROLE_ADMIN, $this->roles, true)) {
            return array_keys(\App\Security\Droits::catalogue());
        }

        $droits = array_merge($this->profil?->getDroits() ?? [], $this->getDroitsAjoutes());

        return \App\Security\Droits::filtrer(array_diff($droits, $this->getDroitsRetires()));
    }

    /**
     * Etat d'un droit pour la legende de la fiche : herite du role,
     * ajoute ou retire pour la personne, ou non accorde.
     */
    public function etatDroit(string $droit): string
    {
        $duRole = $this->profil?->aLeDroit($droit) ?? false;

        return match (true) {
            $duRole && in_array($droit, $this->getDroitsRetires(), true) => 'retire',
            $duRole => 'herite',
            in_array($droit, $this->getDroitsAjoutes(), true) => 'ajoute',
            default => 'non',
        };
    }

    public function getObjectifVentesMois(): ?int
    {
        return $this->objectifVentesMois;
    }

    public function setObjectifVentesMois(?int $objectif): static
    {
        $this->objectifVentesMois = null !== $objectif && $objectif > 0 ? $objectif : null;

        return $this;
    }

    public function nombreDroitsPersonnalises(): int
    {
        return count($this->getDroitsAjoutes()) + count($this->getDroitsRetires());
    }


    /*
     * ============================================================
     * TESTER UN RÔLE DIRECT
     * ============================================================
     *
     * ATTENTION :
     *
     * Cette méthode regarde les rôles présents dans User.
     *
     * Pour tester les rôles hérités de security.yaml
     * dans un contrôleur ou Twig, utiliser :
     *
     * is_granted(...)
     *
     * ou :
     *
     * denyAccessUnlessGranted(...)
     * ============================================================
     */

    public function hasRole(
        string $role
    ): bool {

        return in_array(
            strtoupper(
                trim(
                    $role
                )
            ),
            $this->getRoles(),
            true
        );
    }


    /*
     * ============================================================
     * ADMINISTRATEUR
     * ============================================================
     */

    public function isAdmin(): bool
    {
        return $this->hasRole(
            self::ROLE_ADMIN
        );
    }


    /*
     * ============================================================
     * PROFIL CAISSE / COMMANDES
     * ============================================================
     */

    public function isCaisseCommande(): bool
    {
        return $this->hasRole(
            self::ROLE_CAISSE_COMMANDE
        );
    }


    /*
     * ============================================================
     * INFOGRAPHISTE
     * ============================================================
     */

    public function isGraphiste(): bool
    {
        return $this->hasRole(
            self::ROLE_GRAPHISTE
        );
    }


    /*
     * ============================================================
     * PRODUCTION
     * ============================================================
     */

    public function isProduction(): bool
    {
        return $this->hasRole(
            self::ROLE_PRODUCTION
        );
    }


    /*
     * ============================================================
     * LIVREUR
     * ============================================================
     */

    public function isLivreur(): bool
    {
        return $this->hasRole(
            self::ROLE_LIVREUR
        );
    }


    /*
     * ============================================================
     * STATISTIQUES GLOBALES
     * ============================================================
     */

    public function peutVoirStatistiquesGlobales(): bool
    {
        return
            $this->isAdmin()
            ||
            $this->hasRole(
                self::ROLE_STATS_GLOBAL
            );
    }


    /*
     * ============================================================
     * PASSWORD
     * ============================================================
     */

    public function getPassword(): ?string
    {
        return $this->password;
    }


    /*
     * Cette méthode reçoit toujours
     * un mot de passe déjà hashé.
     */
    public function setPassword(
        string $password
    ): static {

        if (
            trim(
                $password
            ) === ''
        ) {

            throw new \InvalidArgumentException(
                'Le mot de passe hashé ne peut pas être vide.'
            );
        }


        $this->password =
            $password;


        return $this;
    }


    /*
     * ============================================================
     * MOT DE PASSE TEMPORAIRE
     * ============================================================
     */

    public function definirMotDePasseTemporaire(
        string $passwordHashe
    ): static {

        $this->setPassword(
            $passwordHashe
        );


        $this->mustChangePassword =
            true;


        /*
         * Le mot de passe temporaire n'est pas
         * considéré comme le mot de passe personnel
         * choisi par l'utilisateur.
         */
        $this->passwordChangedAt =
            null;


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * NOUVEAU MOT DE PASSE PERSONNEL
     * ============================================================
     */

    public function definirNouveauMotDePasse(
        string $passwordHashe
    ): static {

        $this->setPassword(
            $passwordHashe
        );


        $this->mustChangePassword =
            false;


        $this->passwordChangedAt =
            new \DateTimeImmutable();


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * SERIALISATION SESSION
     * ============================================================
     */

    public function __serialize(): array
    {
        $data =
            (array) $this;


        /*
         * Empêche la session Symfony d'embarquer
         * directement le hash complet du password.
         */
        if (
            $this->password !== null
        ) {

            $data[
                "\0"
                . self::class
                . "\0password"
            ] =
                hash(
                    'crc32c',
                    $this->password
                );
        }


        return $data;
    }


    /*
     * ============================================================
     * COMMANDES
     * ============================================================
     */

    /**
     * @return Collection<int, Commandes>
     */
    public function getCommandes():
        Collection
    {
        return $this->commandes;
    }


    public function addCommande(
        Commandes $commande
    ): static {

        if (
            !$this->commandes
                ->contains(
                    $commande
                )
        ) {

            $this->commandes
                ->add(
                    $commande
                );


            $commande
                ->setAgents(
                    $this
                );
        }


        return $this;
    }


    public function removeCommande(
        Commandes $commande
    ): static {

        if (
            $this->commandes
                ->removeElement(
                    $commande
                )
        ) {

            if (
                $commande->getAgents()
                === $this
            ) {

                $commande
                    ->setAgents(
                        null
                    );
            }
        }


        return $this;
    }


    /*
     * ============================================================
     * PAIEMENTS ENCAISSÉS
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

        if (
            !$this->paiements
                ->contains(
                    $paiement
                )
        ) {

            $this->paiements
                ->add(
                    $paiement
                );


            /*
             * Synchronisation du côté propriétaire.
             */
            if (
                $paiement->getEncaissePar()
                !== $this
            ) {

                $paiement
                    ->setEncaissePar(
                        $this
                    );
            }
        }


        return $this;
    }


    public function removePaiement(
        Paiements $paiement
    ): static {

        if (
            $this->paiements
                ->removeElement(
                    $paiement
                )
        ) {

            /*
             * Suppression de la relation uniquement
             * si cet utilisateur est bien l'encaisseur.
             */
            if (
                $paiement->getEncaissePar()
                === $this
            ) {

                $paiement
                    ->setEncaissePar(
                        null
                    );
            }
        }


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


    /*
     * Alias pratique pour les contrôles de sécurité.
     */
    public function isEnabled(): bool
    {
        return $this->actif;
    }


    /*
     * ============================================================
     * SET ACTIF
     * ============================================================
     */

    public function setActif(
        bool $actif
    ): static {

        /*
         * Aucun changement.
         */
        if (
            $this->actif === $actif
        ) {

            return $this;
        }


        if ($actif) {

            return $this->reactiver();
        }


        return $this->desactiver();
    }


    /*
     * ============================================================
     * DÉSACTIVATION
     * ============================================================
     */

    public function desactiver(
        ?string $motif = null
    ): static {

        $this->actif =
            false;


        $this->disabledAt =
            new \DateTimeImmutable();


        if (
            $motif !== null
        ) {

            $motif =
                trim(
                    $motif
                );


            if (
                $motif === ''
            ) {

                $motif =
                    null;
            }
        }


        $this->motifDesactivation =
            $motif;


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * RÉACTIVATION
     * ============================================================
     */

    public function reactiver(): static
    {
        $this->actif =
            true;


        $this->disabledAt =
            null;


        $this->motifDesactivation =
            null;


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * EMPLOYÉ
     * ============================================================
     */

    public function getEmploye():
        ?Employes
    {
        return $this->employe;
    }


    public function setEmploye(
        ?Employes $employe
    ): static {

        $this->employe =
            $employe;


        $this->dateUpdate =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * DATE CRÉATION
     * ============================================================
     */

    public function getDateAdd():
        ?\DateTimeImmutable
    {
        return $this->dateAdd;
    }


    public function setDateAdd(
        \DateTimeImmutable $dateAdd
    ): static {

        $this->dateAdd =
            $dateAdd;


        return $this;
    }


    /*
     * ============================================================
     * DATE UPDATE
     * ============================================================
     */

    public function getDateUpdate():
        ?\DateTimeImmutable
    {
        return $this->dateUpdate;
    }


    public function setDateUpdate(
        \DateTimeImmutable $dateUpdate
    ): static {

        $this->dateUpdate =
            $dateUpdate;


        return $this;
    }


    /*
     * ============================================================
     * CHANGEMENT DE MOT DE PASSE OBLIGATOIRE
     * ============================================================
     */

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }


    /*
     * Getter compatible Twig :
     *
     * user.mustChangePassword
     */
    public function isMustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }


    public function setMustChangePassword(
        bool $mustChangePassword
    ): static {

        $this->mustChangePassword =
            $mustChangePassword;


        return $this;
    }


    /*
     * ============================================================
     * PASSWORD CHANGED AT
     * ============================================================
     */

    public function getPasswordChangedAt():
        ?\DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }


    public function setPasswordChangedAt(
        ?\DateTimeImmutable $passwordChangedAt
    ): static {

        $this->passwordChangedAt =
            $passwordChangedAt;


        return $this;
    }


    /*
     * ============================================================
     * DERNIÈRE CONNEXION
     * ============================================================
     */

    public function getLastLoginAt():
        ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }


    public function setLastLoginAt(
        ?\DateTimeImmutable $lastLoginAt
    ): static {

        $this->lastLoginAt =
            $lastLoginAt;


        return $this;
    }


    /*
     * ============================================================
     * ENREGISTRER UNE CONNEXION
     * ============================================================
     */

    public function enregistrerConnexion(): static
    {
        $this->lastLoginAt =
            new \DateTimeImmutable();


        return $this;
    }


    /*
     * ============================================================
     * DISABLED AT
     * ============================================================
     */

    public function getDisabledAt():
        ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }


    public function setDisabledAt(
        ?\DateTimeImmutable $disabledAt
    ): static {

        $this->disabledAt =
            $disabledAt;


        return $this;
    }


    /*
     * ============================================================
     * MOTIF DÉSACTIVATION
     * ============================================================
     */

    public function getMotifDesactivation():
        ?string
    {
        return $this->motifDesactivation;
    }


    public function setMotifDesactivation(
        ?string $motifDesactivation
    ): static {

        if (
            $motifDesactivation !== null
        ) {

            $motifDesactivation =
                trim(
                    $motifDesactivation
                );


            if (
                $motifDesactivation === ''
            ) {

                $motifDesactivation =
                    null;
            }
        }


        $this->motifDesactivation =
            $motifDesactivation;


        return $this;
    }

    /*
     * ============================================================
     * ACCÈS À L'APPLICATION (maquettes E02, E04, E05)
     * ============================================================
     *
     * Chaque employé a un compte, même sans accès : ses pointages, sa
     * paie et ses demandes y sont rattachés. Sans accès, la connexion est
     * refusée ; il pointe sur la tablette de l'entrée. L'accès s'ouvre par
     * une invitation WhatsApp : la personne choisit un code PIN à
     * 4 chiffres et son téléphone est enregistré.
     */

    public const ACCES_ACTIF = 'actif';
    public const ACCES_SANS = 'sans';
    public const ACCES_INVITE = 'invite';
    public const ACCES_SUSPENDU = 'suspendu';

    #[ORM\Column(length: 12, options: ['default' => 'actif'])]
    private string $acces = self::ACCES_ACTIF;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pin = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $pinEssais = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pinBloqueJusqua = null;

    /** Empreinte (sha256) du lien d'invitation : le lien lui-même n'est jamais stocké. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $invitationEmpreinte = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitationEnvoyeeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitationExpireLe = null;

    /** Fin du contrat : l'accès est coupé ce jour-là, automatiquement. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $finAccesLe = null;

    public function getAcces(): string { return $this->acces; }
    public function setAcces(string $acces): static
    {
        $this->acces = \in_array($acces, [self::ACCES_ACTIF, self::ACCES_SANS, self::ACCES_INVITE, self::ACCES_SUSPENDU], true) ? $acces : self::ACCES_SANS;

        return $this;
    }

    public function getPin(): ?string { return $this->pin; }
    public function setPin(?string $pinHashe): static { $this->pin = $pinHashe; $this->pinEssais = 0; $this->pinBloqueJusqua = null; return $this; }
    public function getPinEssais(): int { return $this->pinEssais; }
    public function getPinBloqueJusqua(): ?\DateTimeImmutable { return $this->pinBloqueJusqua; }

    /** Un essai de PIN raté ; au 5e, le PIN est bloqué 15 minutes. */
    public function pinRate(\DateTimeImmutable $maintenant): void
    {
        if (++$this->pinEssais >= 5) {
            $this->pinEssais = 0;
            $this->pinBloqueJusqua = $maintenant->modify('+15 minutes');
        }
    }

    public function pinReussi(): void { $this->pinEssais = 0; $this->pinBloqueJusqua = null; }

    public function getInvitationEmpreinte(): ?string { return $this->invitationEmpreinte; }
    public function getInvitationEnvoyeeLe(): ?\DateTimeImmutable { return $this->invitationEnvoyeeLe; }
    public function getInvitationExpireLe(): ?\DateTimeImmutable { return $this->invitationExpireLe; }

    public function inviter(string $empreinte, \DateTimeImmutable $le, \DateTimeImmutable $expire): static
    {
        $this->invitationEmpreinte = $empreinte;
        $this->invitationEnvoyeeLe = $le;
        $this->invitationExpireLe = $expire;
        $this->acces = self::ACCES_INVITE;

        return $this;
    }

    public function oublierInvitation(): static
    {
        $this->invitationEmpreinte = null;
        $this->invitationExpireLe = null;

        return $this;
    }

    public function getFinAccesLe(): ?\DateTimeImmutable { return $this->finAccesLe; }
    public function setFinAccesLe(?\DateTimeImmutable $d): static { $this->finAccesLe = $d; return $this; }

    /** Le compte peut-il se connecter à l'application ? */
    public function peutSeConnecter(\DateTimeImmutable $maintenant): bool
    {
        return $this->actif
            && $this->acces === self::ACCES_ACTIF
            && ($this->finAccesLe === null || $this->finAccesLe > $maintenant->setTime(0, 0));
    }
}