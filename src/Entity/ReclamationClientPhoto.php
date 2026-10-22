<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Photo jointe à une réclamation. Réduite et réencodée à l'envoi
 * (ReclamationsClients::reduire), sans les données EXIF (position GPS) ;
 * gardée en base pour monter au local avec la réclamation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reclamation_client_photo')]
class ReclamationClientPhoto
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ReclamationClient $reclamation = null;

    /** Image en base64. */
    #[ORM\Column(type: Types::TEXT, length: 16777215)]
    private string $contenu = '';

    /** image/jpeg ou image/png. */
    #[ORM\Column(length: 20)]
    private string $type = 'image/jpeg';

    #[ORM\Column]
    private int $largeur = 0;

    #[ORM\Column]
    private int $hauteur = 0;

    public function __construct()
    {
        $this->initialiserPublicId();
    }

    public static function pour(ReclamationClient $reclamation, string $octets, string $type, int $largeur, int $hauteur): self
    {
        $p = new self();
        $p->reclamation = $reclamation;
        $p->contenu = base64_encode($octets);
        $p->type = 'image/png' === $type ? 'image/png' : 'image/jpeg';
        $p->largeur = $largeur;
        $p->hauteur = $hauteur;
        $reclamation->getPhotos()->add($p);

        return $p;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReclamation(): ?ReclamationClient
    {
        return $this->reclamation;
    }

    public function getOctets(): string
    {
        return (string) base64_decode($this->contenu, true);
    }

    /** Toujours une image, même si la ligne arrive d'ailleurs par la synchronisation. */
    public function getType(): string
    {
        return 'image/png' === $this->type ? 'image/png' : 'image/jpeg';
    }

    public function getLargeur(): int
    {
        return $this->largeur;
    }

    public function getHauteur(): int
    {
        return $this->hauteur;
    }
}
