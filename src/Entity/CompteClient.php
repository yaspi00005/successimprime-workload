<?php

namespace App\Entity;

use App\Repository\CompteClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Accès d'un client à son espace (maquettes CX01 et CX04). Le client se
 * connecte avec son numéro de téléphone et un code reçu par SMS, ou un
 * mot de passe s'il en a choisi un. Il ne voit que ses propres données.
 *
 * Une entreprise (maquette 19) a plusieurs comptes sur la même fiche
 * client, chacun avec ses droits : demandeur (commande seulement, sa
 * commande attend un valideur), valideur, payeur, administrateur du
 * compte. Le compte ouvert avec le numéro de la fiche a tous les droits.
 * Un compte « Lecture · suivi » (maquette EC04) voit les commandes, les
 * livraisons et les fichiers, et n'écrit rien.
 */
#[ORM\Entity(repositoryClass: CompteClientRepository::class)]
#[ORM\Table(name: 'compte_client')]
class CompteClient implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE = 'ROLE_ESPACE_CLIENT';

    public const DROIT_VALIDEUR = 'valideur';
    public const DROIT_PAYEUR = 'payeur';
    public const DROIT_ADMINISTRATEUR = 'administrateur';

    public const DROITS = [
        self::DROIT_VALIDEUR => 'Valideur',
        self::DROIT_PAYEUR => 'Payeur',
        self::DROIT_ADMINISTRATEUR => 'Gère le compte',
    ];

    /**
     * Accès en lecture seule (réception, gardien…) : voit les commandes,
     * les livraisons et les fichiers ; ne commande, ne valide, n'accepte,
     * ne paie et ne modifie rien. Exclut les autres droits.
     */
    public const DROIT_LECTURE = 'lecture';
    public const LIBELLE_LECTURE = 'Lecture · suivi';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** Nom de la personne (comptes d'une entreprise). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nom = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $droits = [self::DROIT_VALIDEUR, self::DROIT_PAYEUR, self::DROIT_ADMINISTRATEUR];

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $ajoutePar = null;

    /** Chiffres seulement, 223 compris (ex. 22376123456). */
    #[ORM\Column(length: 20, unique: true)]
    private string $telephone = '';

    #[ORM\Column(nullable: true)]
    private ?string $motDePasse = null;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    /** Fonction dans l'entreprise, en texte libre (Intendant, Comptabilité…). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $fonction = null;

    /**
     * Augmentée par « Déconnecter les autres appareils » : une session
     * ouverte avec une version plus ancienne est fermée à sa prochaine
     * requête, et les cookies « rester connecté » ne valent plus.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $versionSession = 1;

    /**
     * « Comment je suis prévenu » (maquette EC03) : canal par catégorie,
     * voir PreferencesNotifications. Null : réglages par défaut.
     *
     * @var array<string, list<string>>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferencesNotifications = null;

    /** « Tout marquer comme lu » : les notifications d'avant sont lues. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notificationsLuesJusqua = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    /**
     * Numéro du Mali sous une forme unique : 8 chiffres locaux précédés
     * de 223. Renvoie null si ce n'est pas un numéro valable.
     */
    public static function normaliserTelephone(string $saisi): ?string
    {
        $chiffres = preg_replace('/\D/', '', $saisi) ?? '';
        if (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }
        if (8 === \strlen($chiffres)) {
            $chiffres = '223'.$chiffres;
        }

        return preg_match('/^223\d{8}$/', $chiffres) ? $chiffres : null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $nom = null !== $nom ? mb_substr(trim($nom), 0, 80) : null;
        $this->nom = '' === $nom ? null : $nom;

        return $this;
    }

    /** Nom à montrer : la personne, sinon le titulaire de la fiche. */
    public function getNomAffiche(): string
    {
        return $this->nom ?? (trim((string) $this->client?->getPrenom().' '.$this->client?->getNom()) ?: (string) $this->client?->getRaisonSociale());
    }

    /** @return list<string> */
    public function getDroits(): array
    {
        return $this->droits;
    }

    /** @param list<string> $droits « lecture » l'emporte : les autres droits sont retirés. */
    public function setDroits(array $droits): static
    {
        $this->droits = \in_array(self::DROIT_LECTURE, $droits, true)
            ? [self::DROIT_LECTURE]
            : array_values(array_intersect(array_keys(self::DROITS), $droits));

        return $this;
    }

    public function peut(string $droit): bool
    {
        if ($this->estLectureSeule()) {
            return false;
        }
        // Un particulier n'a qu'un compte : il fait tout.
        if (null !== $this->client && !self::clientAPlusieursComptes($this->client)) {
            return true;
        }

        return \in_array($droit, $this->droits, true) || \in_array(self::DROIT_ADMINISTRATEUR, $this->droits, true);
    }

    /** « Lecture · suivi » : rien de ce qui écrit (commander, valider, payer, modifier). */
    public function estLectureSeule(): bool
    {
        return \in_array(self::DROIT_LECTURE, $this->droits, true);
    }

    /** Commander, valider un BAT, écrire un message ou une réclamation : tout le monde sauf la lecture seule. */
    public function peutEcrire(): bool
    {
        return !$this->estLectureSeule();
    }

    /** Ses commandes attendent la validation d'un valideur de l'entreprise. */
    public function estDemandeur(): bool
    {
        return !$this->estLectureSeule() && !$this->peut(self::DROIT_VALIDEUR);
    }

    /** « Valideur et payeur », « Demandeur »... */
    public function getLibelleDroits(): string
    {
        if ($this->estLectureSeule()) {
            return self::LIBELLE_LECTURE;
        }
        if (\in_array(self::DROIT_ADMINISTRATEUR, $this->droits, true)) {
            return 'Gère le compte · valide et paie';
        }
        $libelles = array_map(static fn (string $d): string => mb_strtolower(self::DROITS[$d]), $this->droits);

        return [] === $libelles ? 'Demandeur' : ucfirst(implode(' et ', $libelles));
    }

    /**
     * Ce que la personne peut faire, en clair (maquette EC04) :
     * « administrateur du compte · commandes, BAT, factures, utilisateurs ».
     */
    public function getResumeDroits(): string
    {
        if ($this->estLectureSeule()) {
            return 'lecture · suivi des commandes et livraisons';
        }
        if ($this->peut(self::DROIT_ADMINISTRATEUR)) {
            return 'administrateur du compte · commandes, BAT, factures, utilisateurs';
        }
        $parts = [$this->peut(self::DROIT_VALIDEUR) ? 'commandes et devis' : 'demandes de commande', 'BAT'];
        if ($this->peut(self::DROIT_PAYEUR)) {
            $parts[] = 'factures et paiements';
        }

        return implode(', ', $parts);
    }

    /** Initiales de la personne : « IM » pour Ibrahim Maïga. */
    public function getInitiales(): string
    {
        return self::initiales($this->getNomAffiche());
    }

    /** « Hôtel Niger » → « HN », « Réception » → « R » (petits mots ignorés). */
    public static function initiales(string $nom): string
    {
        $mots = array_values(array_filter(
            preg_split('/[\s\-]+/u', trim($nom)) ?: [],
            static fn (string $m): bool => '' !== $m && !\in_array(mb_strtolower($m), ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'sa', 'sarl', 'sas'], true),
        ));
        if ([] === $mots) {
            return '?';
        }
        $lettre = static fn (string $m): string => mb_strtoupper(mb_substr((string) preg_replace('/^[ldLD][\'’]/u', '', $m), 0, 1));

        return \count($mots) > 1 ? $lettre($mots[0]).$lettre($mots[\count($mots) - 1]) : $lettre($mots[0]);
    }

    /** Seules les fiches entreprise ou B2B ont plusieurs utilisateurs. */
    public static function clientAPlusieursComptes(Clients $client): bool
    {
        return $client->isEntreprise() || $client->isB2B();
    }

    public function getAjoutePar(): ?self
    {
        return $this->ajoutePar;
    }

    public function setAjoutePar(?self $compte): static
    {
        $this->ajoutePar = $compte;

        return $this;
    }

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = self::normaliserTelephone($telephone) ?? throw new \InvalidArgumentException('Numéro de téléphone invalide.');

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->motDePasse;
    }

    public function aUnMotDePasse(): bool
    {
        return null !== $this->motDePasse;
    }

    public function setMotDePasse(?string $empreinte): static
    {
        $this->motDePasse = $empreinte;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getDerniereConnexion(): ?\DateTimeImmutable
    {
        return $this->derniereConnexion;
    }

    public function setDerniereConnexion(?\DateTimeImmutable $date): static
    {
        $this->derniereConnexion = $date;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $fonction = null !== $fonction ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $fonction)), 0, 60) : null;
        $this->fonction = '' === $fonction ? null : $fonction;

        return $this;
    }

    public function getVersionSession(): int
    {
        return $this->versionSession;
    }

    /** « Déconnecter les autres appareils ». */
    public function nouvelleVersionSession(): int
    {
        return ++$this->versionSession;
    }

    /** @return array<string, list<string>>|null */
    public function getPreferencesNotifications(): ?array
    {
        return $this->preferencesNotifications;
    }

    /** @param array<string, list<string>>|null $preferences déjà contrôlées (PreferencesNotifications::lire) */
    public function setPreferencesNotifications(?array $preferences): static
    {
        $this->preferencesNotifications = $preferences;

        return $this;
    }

    public function getNotificationsLuesJusqua(): ?\DateTimeImmutable
    {
        return $this->notificationsLuesJusqua;
    }

    public function setNotificationsLuesJusqua(?\DateTimeImmutable $le): static
    {
        $this->notificationsLuesJusqua = $le;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->telephone;
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function eraseCredentials(): void
    {
    }

    /** Pas de mot de passe dans la session : seulement son empreinte courte. */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'telephone' => $this->telephone, 'motDePasse' => null !== $this->motDePasse ? hash('crc32c', $this->motDePasse) : null];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->telephone = $data['telephone'];
        $this->motDePasse = $data['motDePasse'];
        $this->creeLe = new \DateTimeImmutable();
    }
}
