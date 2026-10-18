<?php

namespace App\Entity\Trait;

use App\Service\Catalogue\NomCommercial;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nom commercial facultatif d'un élément du catalogue (App\Entity\AUnNomCommercial).
 * Nettoyé à l'enregistrement : espaces réduits, 120 caractères au plus,
 * vide = pas de nom commercial.
 */
trait NomCommercialTrait
{
    #[ORM\Column(length: NomCommercial::LONGUEUR, nullable: true)]
    private ?string $nomCommercial = null;

    public function getNomCommercial(): ?string
    {
        return $this->nomCommercial;
    }

    public function setNomCommercial(?string $nomCommercial): static
    {
        $this->nomCommercial = NomCommercial::nettoyer($nomCommercial);

        return $this;
    }
}
