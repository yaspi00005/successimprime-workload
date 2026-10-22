<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Petites valeurs d'état de la synchronisation : curseur de réception,
 * dernière réussite, dernier contact de l'agent, latence…
 */
#[ORM\Entity]
#[ORM\Table(name: 'synchro_etat')]
class SynchroEtat
{
    #[ORM\Id]
    #[ORM\Column(length: 40)]
    private string $cle;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $valeur = null;

    public function __construct(string $cle)
    {
        $this->cle = $cle;
    }

    public function getCle(): string { return $this->cle; }
    public function getValeur(): ?string { return $this->valeur; }

    public function setValeur(?string $valeur): self
    {
        $this->valeur = $valeur;

        return $this;
    }
}
