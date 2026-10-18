<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AccesEspaceClientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code d'accès à l'espace client donné par l'imprimerie (fiche client ›
 * « Envoyer l'accès à l'espace client », par WhatsApp) : 8 caractères
 * faciles à taper, valable 48 heures, une seule fois. Il sert à la première
 * connexion, et de mot de passe oublié passé par l'imprimerie.
 *
 * Seule son empreinte est gardée (sha256 du numéro et du code) : le code
 * lui-même n'existe que dans le message WhatsApp. Une ligne par client :
 * un nouveau code remplace, donc annule, le précédent.
 *
 * L'empreinte et l'échéance descendent sur le site (CatalogueSynchro,
 * « acces_espace ») ; qui a donné l'accès reste au local, l'utilisation
 * (empreinteUtilisee, utiliseLe) reste sur le site.
 */
#[ORM\Entity(repositoryClass: AccesEspaceClientRepository::class)]
#[ORM\Table(name: 'acces_espace_client')]
#[ORM\Index(name: 'idx_acces_espace_empreinte', columns: ['empreinte'])]
class AccesEspaceClient
{
    use PublicIdTrait;

    /** Ni 0/O, ni 1/I/L : rien à confondre en recopiant le message. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LONGUEUR = 8;
    public const VALIDITE_HEURES = 48;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $empreinte = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expireLe;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    /** Employé qui a donné l'accès (au local seulement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    /** Sur le site : empreinte du code déjà servi. Un nouveau code (autre empreinte) est à nouveau bon. */
    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $empreinteUtilisee = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->expireLe = $this->creeLe;
    }

    /**
     * Nouveau code pour ce numéro : remplace le précédent, qui ne sert
     * plus. Le code en clair est rendu une fois, pour le message, et n'est
     * gardé nulle part.
     */
    public function nouveauCode(string $telephone, \DateTimeImmutable $maintenant, ?User $par): string
    {
        $code = '';
        for ($i = 0; $i < self::LONGUEUR; ++$i) {
            $code .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }
        $this->empreinte = self::empreinteDe($telephone, $code);
        $this->creeLe = $maintenant;
        $this->expireLe = $maintenant->modify('+'.self::VALIDITE_HEURES.' hours');
        $this->creePar = $par;
        $this->empreinteUtilisee = null;
        $this->utiliseLe = null;

        return $code;
    }

    /**
     * sha256 du numéro (forme 223XXXXXXXX) et du code (majuscules, sans
     * espace ni tiret) : on retrouve la ligne avec ce que la personne tape,
     * sans jamais garder le code.
     */
    public static function empreinteDe(string $telephone, string $code): string
    {
        $numero = CompteClient::normaliserTelephone($telephone) ?? '';

        return hash('sha256', $numero.'|'.self::normaliserCode($code));
    }

    /** « abcd-efgh », « ABCD EFGH » : ABCDEFGH. */
    public static function normaliserCode(string $code): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code) ?? '');
    }

    /** Pour le message : ABCD-EFGH. */
    public static function afficherCode(string $code): string
    {
        return implode('-', str_split(self::normaliserCode($code), 4));
    }

    /** Le code de cette empreinte est-il encore bon (pas expiré, pas servi) ? */
    public function valide(string $empreinte, \DateTimeImmutable $maintenant): bool
    {
        return '' !== $this->empreinte
            && hash_equals($this->empreinte, $empreinte)
            && $maintenant <= $this->expireLe
            && $this->empreinte !== $this->empreinteUtilisee;
    }

    /** Le code ne sert qu'une fois. */
    public function utiliser(\DateTimeImmutable $maintenant): void
    {
        $this->empreinteUtilisee = $this->empreinte;
        $this->utiliseLe = $maintenant;
    }

    public function estUtilise(): bool
    {
        return '' !== $this->empreinte && $this->empreinte === $this->empreinteUtilisee;
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

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function getExpireLe(): \DateTimeImmutable
    {
        return $this->expireLe;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function getUtiliseLe(): ?\DateTimeImmutable
    {
        return $this->utiliseLe;
    }
}
