<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Relance d'un client pour une commande non soldée, faite à la main
 * depuis les créances (maquette 30). Les rappels automatiques restent
 * dans RappelPaiementEnvoye.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_relance_creance_date', columns: ['envoyee_le'])]
class RelanceCreance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Commandes $commande = null;

    #[ORM\Column(length: 20)]
    private string $canal = ModeleMessage::CANAL_WHATSAPP;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par = null;

    public function __construct()
    {
        $this->envoyeeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        return $this;
    }

    public function getEnvoyeeLe(): \DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function getPar(): ?User
    {
        return $this->par;
    }

    public function setPar(?User $par): static
    {
        $this->par = $par;

        return $this;
    }
}
