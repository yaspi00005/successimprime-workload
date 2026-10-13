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

    /** @param list<string> $droits */
    public function setDroits(array $droits): static
    {
        $this->droits = array_values(array_intersect(array_keys(self::DROITS), $droits));

        return $this;
    }

    public function peut(string $droit): bool
    {
        // Un particulier n'a qu'un compte : il fait tout.
        if (null !== $this->client && !self::clientAPlusieursComptes($this->client)) {
            return true;
        }

        return \in_array($droit, $this->droits, true) || \in_array(self::DROIT_ADMINISTRATEUR, $this->droits, true);
    }

    /** Ses commandes attendent la validation d'un valideur de l'entreprise. */
    public function estDemandeur(): bool
    {
        return !$this->peut(self::DROIT_VALIDEUR);
    }

    /** « Valideur et payeur », « Demandeur »... */
    public function getLibelleDroits(): string
    {
        if (\in_array(self::DROIT_ADMINISTRATEUR, $this->droits, true)) {
            return 'Gère le compte · valide et paie';
        }
        $libelles = array_map(static fn (string $d): string => mb_strtolower(self::DROITS[$d]), $this->droits);

        return [] === $libelles ? 'Demandeur' : ucfirst(implode(' et ', $libelles));
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
