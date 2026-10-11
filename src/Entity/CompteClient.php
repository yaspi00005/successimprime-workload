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
 */
#[ORM\Entity(repositoryClass: CompteClientRepository::class)]
#[ORM\Table(name: 'compte_client')]
class CompteClient implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE = 'ROLE_ESPACE_CLIENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Clients $client = null;

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
