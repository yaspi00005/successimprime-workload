<?php

namespace App\Entity;

use App\Repository\AppareilAutoriseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil autorisé à ouvrir l'administration en ligne (maquettes 38
 * et CX03). Un appareil est reconnu par un jeton aléatoire gardé dans
 * un cookie ; seule son empreinte (sha256) est en base.
 *
 * Une invitation (QR valable 5 minutes) est une ligne « en_attente » ;
 * une tentative depuis un appareil inconnu est une ligne « refuse ».
 */
#[ORM\Entity(repositoryClass: AppareilAutoriseRepository::class)]
#[ORM\Table(name: 'appareil_autorise')]
#[ORM\Index(name: 'idx_appareil_etat', columns: ['etat'])]
class AppareilAutorise
{
    public const ETAT_EN_ATTENTE = 'en_attente';
    public const ETAT_AUTORISE = 'autorise';
    public const ETAT_REFUSE = 'refuse';
    public const ETAT_REVOQUE = 'revoque';
    public const ETAT_BLOQUE = 'bloque';

    /** Ce que l'appareil peut faire en ligne (les droits de la personne s'appliquent en plus). */
    public const ROLE_ADMIN = 'admin';
    public const ROLE_ENCAISSEMENT = 'encaissement';
    public const ROLE_LECTURE = 'lecture';

    public const ROLES = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_ENCAISSEMENT => 'Encaissement',
        self::ROLE_LECTURE => 'Lecture',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $utilisateur = null;

    #[ORM\Column(length: 100)]
    private string $nom = '';

    #[ORM\Column(length: 20)]
    private string $roleEnLigne = self::ROLE_LECTURE;

    #[ORM\Column(length: 20)]
    private string $etat = self::ETAT_EN_ATTENTE;

    /** sha256 du jeton du cookie, ou du jeton de l'invitation tant qu'elle attend. */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $empreinte = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expireLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $autoriseLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $navigateur = null;

    /** Identifiant tapé lors d'une tentative refusée. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $identifiantTente = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    /** Prénom et nom de la fiche employé, sinon l'identifiant. */
    public function getNomUtilisateur(): string
    {
        if (null === $this->utilisateur) {
            return '—';
        }
        $employe = $this->utilisateur->getEmploye();
        $nom = $employe ? trim($employe->getPrenom().' '.$employe->getNom()) : '';

        return '' !== $nom ? $nom : $this->utilisateur->getUserIdentifier();
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr(trim($nom), 0, 100);

        return $this;
    }

    public function getRoleEnLigne(): string
    {
        return $this->roleEnLigne;
    }

    public function getRoleEnLigneLibelle(): string
    {
        return self::ROLES[$this->roleEnLigne] ?? '—';
    }

    public function setRoleEnLigne(string $role): static
    {
        $this->roleEnLigne = \array_key_exists($role, self::ROLES) ? $role : self::ROLE_LECTURE;

        return $this;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function setEtat(string $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    public function estAutorise(): bool
    {
        return self::ETAT_AUTORISE === $this->etat;
    }

    public function getEmpreinte(): ?string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(?string $empreinte): static
    {
        $this->empreinte = $empreinte;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getExpireLe(): ?\DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function setExpireLe(?\DateTimeImmutable $expireLe): static
    {
        $this->expireLe = $expireLe;

        return $this;
    }

    public function invitationValide(\DateTimeImmutable $maintenant): bool
    {
        return self::ETAT_EN_ATTENTE === $this->etat && null !== $this->expireLe && $this->expireLe > $maintenant;
    }

    public function getAutoriseLe(): ?\DateTimeImmutable
    {
        return $this->autoriseLe;
    }

    public function setAutoriseLe(?\DateTimeImmutable $autoriseLe): static
    {
        $this->autoriseLe = $autoriseLe;

        return $this;
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

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): static
    {
        $this->ip = $ip;

        return $this;
    }

    public function getNavigateur(): ?string
    {
        return $this->navigateur;
    }

    public function setNavigateur(?string $navigateur): static
    {
        $this->navigateur = null === $navigateur ? null : mb_substr($navigateur, 0, 255);

        return $this;
    }

    /** Téléphone, tablette ou ordinateur, d'après le navigateur. */
    public function getType(): string
    {
        $ua = strtolower((string) $this->navigateur);

        return match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablette',
            str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android') => 'telephone',
            default => 'ordinateur',
        };
    }

    public function getIdentifiantTente(): ?string
    {
        return $this->identifiantTente;
    }

    public function setIdentifiantTente(?string $identifiant): static
    {
        $this->identifiantTente = null === $identifiant ? null : mb_substr($identifiant, 0, 180);

        return $this;
    }
}
