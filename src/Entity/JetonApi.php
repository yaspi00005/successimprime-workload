<?php

namespace App\Entity;

use App\Repository\JetonApiRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton d'accès à l'API (docs/api.md). Il agit au nom d'une personne,
 * avec ses droits du moment : retirer un droit à la personne le retire
 * aussi à ses jetons. Le jeton n'est montré qu'à sa création ; seule son
 * empreinte (sha256) est en base.
 */
#[ORM\Entity(repositoryClass: JetonApiRepository::class)]
#[ORM\Table(name: 'jeton_api')]
#[ORM\UniqueConstraint(name: 'uniq_jeton_api_empreinte', columns: ['empreinte'])]
class JetonApi
{
    public const PREFIXE = 'si_';

    /** Durées proposées à la création, en jours (0 : sans fin). */
    public const DUREES = [30 => '30 jours', 90 => '3 mois', 365 => '1 an', 0 => 'Sans date de fin'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $utilisateur = null;

    #[ORM\Column(length: 80)]
    private string $nom = '';

    #[ORM\Column(length: 64)]
    private string $empreinte = '';

    /** Début du jeton, pour le reconnaître dans la liste (si_ab12cd…). */
    #[ORM\Column(length: 12)]
    private string $debut = '';

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expireLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $derniereIp = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revoqueLe = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    /**
     * Nouveau jeton : renvoie le texte à montrer une seule fois.
     *
     * @return array{0: self, 1: string}
     */
    public static function creer(User $utilisateur, string $nom, ?User $auteur, int $jours, \DateTimeImmutable $maintenant): array
    {
        $texte = self::PREFIXE.bin2hex(random_bytes(20));
        $jeton = (new self())
            ->setUtilisateur($utilisateur)
            ->setNom($nom)
            ->setCreePar($auteur);
        $jeton->empreinte = self::empreinte($texte);
        $jeton->debut = substr($texte, 0, 9);
        $jeton->creeLe = $maintenant;
        $jeton->expireLe = $jours > 0 ? $maintenant->modify(\sprintf('+%d days', $jours)) : null;

        return [$jeton, $texte];
    }

    public static function empreinte(string $texte): string
    {
        return hash('sha256', $texte);
    }

    /** Le texte a la forme d'un jeton (évite une requête pour n'importe quoi). */
    public static function formeValide(string $texte): bool
    {
        return 1 === preg_match('/^'.self::PREFIXE.'[0-9a-f]{40}$/', $texte);
    }

    public function estValide(\DateTimeImmutable $maintenant): bool
    {
        return null === $this->revoqueLe
            && (null === $this->expireLe || $this->expireLe > $maintenant)
            && true === $this->utilisateur?->isActif();
    }

    public function getEtat(\DateTimeImmutable $maintenant): string
    {
        return match (true) {
            null !== $this->revoqueLe => 'revoque',
            null !== $this->expireLe && $this->expireLe <= $maintenant => 'expire',
            default => 'actif',
        };
    }

    /**
     * Note l'usage au plus une fois par minute, pour ne pas écrire en
     * base à chaque appel. Renvoie vrai s'il faut enregistrer.
     */
    public function noterUtilisation(\DateTimeImmutable $maintenant, ?string $ip): bool
    {
        if (null !== $this->utiliseLe && $this->utiliseLe > $maintenant->modify('-1 minute') && $ip === $this->derniereIp) {
            return false;
        }
        $this->utiliseLe = $maintenant;
        $this->derniereIp = null !== $ip ? mb_substr($ip, 0, 45) : null;

        return true;
    }

    public function revoquer(\DateTimeImmutable $maintenant): static
    {
        $this->revoqueLe ??= $maintenant;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): ?User
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(User $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = mb_substr(trim($nom), 0, 80);

        return $this;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function getDebut(): string
    {
        return $this->debut;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
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

    public function getExpireLe(): ?\DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function getUtiliseLe(): ?\DateTimeImmutable
    {
        return $this->utiliseLe;
    }

    public function getDerniereIp(): ?string
    {
        return $this->derniereIp;
    }

    public function getRevoqueLe(): ?\DateTimeImmutable
    {
        return $this->revoqueLe;
    }
}
