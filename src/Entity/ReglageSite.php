<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réglage du site vitrine qui doit aussi valoir en ligne (règle de calcul
 * des chiffres, par exemple). Réglé au local, descend par la
 * synchronisation ; la table reglage, elle, reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'site_reglage')]
class ReglageSite
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60, unique: true)]
    private string $cle = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $valeurs = [];

    public function __construct(string $cle = '')
    {
        $this->initialiserPublicId();
        $this->cle = $cle;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    /** @return array<string, mixed> */
    public function getValeurs(): array
    {
        return $this->valeurs;
    }

    /** @param array<string, mixed> $valeurs */
    public function setValeurs(array $valeurs): static
    {
        $this->valeurs = $valeurs;

        return $this;
    }
}
