<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Envois déjà appliqués, pour ne jamais appliquer deux fois le même
 * (rejeu après une coupure, réponse perdue). Purgé après 60 jours.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synchro_recu')]
class SynchroRecu
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $evenement;

    #[ORM\Column]
    private \DateTimeImmutable $recuLe;

    public function __construct(string $evenement, ?\DateTimeImmutable $le = null)
    {
        $this->evenement = $evenement;
        $this->recuLe = $le ?? new \DateTimeImmutable();
    }

    public function getEvenement(): string { return $this->evenement; }
    public function getRecuLe(): \DateTimeImmutable { return $this->recuLe; }
}
