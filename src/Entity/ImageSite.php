<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Image du site vitrine (réalisation, conseil, page, logo de partenaire).
 *
 * Réencodée à l'envoi (ImagesSite::preparer) : 1200 px au plus, sans les
 * données EXIF, en WebP (PNG si le serveur ne sait pas écrire le WebP),
 * 300 Ko au plus. Gardée en base pour descendre au site avec la
 * synchronisation ; les fichiers publics (public/uploads/site) en sont
 * tirés, et réencodés, de chaque côté.
 */
#[ORM\Entity]
#[ORM\Table(name: 'site_image')]
#[ORM\HasLifecycleCallbacks]
class ImageSite
{
    use PublicIdTrait;

    /** Poids maximal d'une image enregistrée (et donc d'un envoi de la synchronisation). */
    public const TAILLE_MAX = 300 * 1024;

    /** Côté le plus long accepté à l'arrivée. */
    public const COTE_MAX = 2400;

    public const TYPES = ['image/webp', 'image/png'];

    public const USAGE_PHOTO = 'photo';
    public const USAGE_LOGO = 'logo';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Image en base64. */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $contenu = '';

    #[ORM\Column(length: 20)]
    private string $type = 'image/webp';

    #[ORM\Column]
    private int $largeur = 0;

    #[ORM\Column]
    private int $hauteur = 0;

    /** SHA-256 des octets : nom des fichiers publics, qui changent avec l'image. */
    #[ORM\Column(length: 64)]
    private string $empreinte = '';

    /** « usage » est un mot réservé de MariaDB : la colonne porte un autre nom. */
    #[ORM\Column(name: 'usage_image', length: 10)]
    private string $usage = self::USAGE_PHOTO;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $texteAlternatif = null;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function depuis(string $octets, string $type, int $largeur, int $hauteur, string $usage = self::USAGE_PHOTO): self
    {
        $image = new self();
        $image->contenu = base64_encode($octets);
        $image->type = $type;
        $image->largeur = $largeur;
        $image->hauteur = $hauteur;
        $image->empreinte = hash('sha256', $octets);
        $image->usage = self::USAGE_LOGO === $usage ? self::USAGE_LOGO : self::USAGE_PHOTO;
        $image->verifier();

        return $image;
    }

    /**
     * Contrôle à chaque enregistrement, y compris quand l'image arrive par
     * la synchronisation : WebP ou PNG, 300 Ko au plus, empreinte juste.
     * Une image refusée bloque l'envoi (visible dans l'écran Synchronisation).
     */
    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function verifier(): void
    {
        $octets = $this->getOctets();
        self::verifierOctets($octets, $this->type);
        if (!hash_equals($this->empreinte, hash('sha256', $octets))) {
            throw new \InvalidArgumentException('Image du site : empreinte différente du contenu.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function verifierOctets(string $octets, string $type): void
    {
        if ('' === $octets || \strlen($octets) > self::TAILLE_MAX) {
            throw new \InvalidArgumentException('Image du site vide ou de plus de 300 Ko.');
        }
        $attendu = match ($type) {
            'image/webp' => \IMAGETYPE_WEBP,
            'image/png' => \IMAGETYPE_PNG,
            default => throw new \InvalidArgumentException('Image du site : seuls le WebP et le PNG sont acceptés.'),
        };
        $entete = 'image/webp' === $type
            ? str_starts_with($octets, 'RIFF') && 'WEBP' === substr($octets, 8, 4)
            : str_starts_with($octets, "\x89PNG\r\n\x1A\n");
        $infos = @getimagesizefromstring($octets);
        if (!$entete || false === $infos || $attendu !== $infos[2] || $infos[0] < 1 || $infos[1] < 1 || max($infos[0], $infos[1]) > self::COTE_MAX) {
            throw new \InvalidArgumentException('Image du site illisible ou trop grande.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOctets(): string
    {
        return (string) base64_decode($this->contenu, true);
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getExtension(): string
    {
        return 'image/png' === $this->type ? 'png' : 'webp';
    }

    public function getLargeur(): int
    {
        return $this->largeur;
    }

    public function getHauteur(): int
    {
        return $this->hauteur;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function getUsage(): string
    {
        return $this->usage;
    }

    public function estLogo(): bool
    {
        return self::USAGE_LOGO === $this->usage;
    }

    public function getTexteAlternatif(): ?string
    {
        return $this->texteAlternatif;
    }

    public function setTexteAlternatif(?string $texte): static
    {
        $texte = null === $texte ? '' : trim($texte);
        $this->texteAlternatif = '' === $texte ? null : mb_substr($texte, 0, 160);

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
