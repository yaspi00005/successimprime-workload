<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Appareil connecté à l'espace client d'une personne (Mon compte ›
 * Sécurité, maquette EC04) : un cookie aléatoire dont on ne garde que
 * l'empreinte sha256, le type d'appareil lu dans le navigateur (iPhone,
 * Android, PC…) et la dernière activité. Ni adresse IP ni position.
 * Tenu sur le site en ligne seulement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'appareil_client')]
#[ORM\UniqueConstraint(name: 'uniq_appareil_client', columns: ['compte_id', 'empreinte'])]
class AppareilClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompteClient $compte;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    /** « iPhone », « Android », « PC Windows »… */
    #[ORM\Column(length: 40)]
    private string $libelle;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column]
    private \DateTimeImmutable $derniereActivite;

    public function __construct(CompteClient $compte, string $empreinte, string $libelle, \DateTimeImmutable $le)
    {
        $this->compte = $compte;
        $this->empreinte = $empreinte;
        $this->libelle = mb_substr($libelle, 0, 40);
        $this->creeLe = $le;
        $this->derniereActivite = $le;
    }

    public function vu(string $libelle, \DateTimeImmutable $le): void
    {
        $this->libelle = mb_substr($libelle, 0, 40);
        $this->derniereActivite = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompte(): CompteClient { return $this->compte; }
    public function getEmpreinte(): string { return $this->empreinte; }
    public function getLibelle(): string { return $this->libelle; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getDerniereActivite(): \DateTimeImmutable { return $this->derniereActivite; }
}
