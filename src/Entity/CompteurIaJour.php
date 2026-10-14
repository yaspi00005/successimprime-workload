<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Images IA lancées sur le site un jour donné : le plafond du jour
 * (Paramètres › Conception) se vérifie et se réserve en une seule
 * requête sur cette ligne.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compteur_ia_jour')]
class CompteurIaJour
{
    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $jour;

    #[ORM\Column]
    private int $images = 0;

    public function __construct(\DateTimeImmutable $jour)
    {
        $this->jour = $jour->setTime(0, 0);
    }

    public function getJour(): \DateTimeImmutable { return $this->jour; }
    public function getImages(): int { return $this->images; }
}
