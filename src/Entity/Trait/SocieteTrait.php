<?php

namespace App\Entity\Trait;

use App\Entity\Societe;
use Doctrine\ORM\Mapping as ORM;

/**
 * Société d'un document (App\Entity\AppartientASociete). Obligatoire en
 * base ; vide seulement entre la création de l'objet et l'enregistrement,
 * où RattachementSociete la pose.
 *
 * La valeur par défaut en base est la société principale : une ligne
 * insérée à la main (donnees-demo/demo.sql, import) y est rattachée.
 */
trait SocieteTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, options: ['default' => Societe::ID_PRINCIPALE])]
    private ?Societe $societe = null;

    public function getSociete(): ?Societe
    {
        return $this->societe;
    }

    public function setSociete(Societe $societe): static
    {
        $this->societe = $societe;

        return $this;
    }
}
