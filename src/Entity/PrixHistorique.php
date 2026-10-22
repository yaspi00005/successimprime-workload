<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'un changement de prix fait depuis la grille tarifaire
 * (maquette 37) : quoi, avant, après, par qui et quand.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_prix_historique_le', columns: ['change_le'])]
class PrixHistorique
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** 'configuration' ou 'finition' */
    #[ORM\Column(length: 20)]
    private string $cible;

    #[ORM\Column]
    private int $cibleId;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(length: 30)]
    private string $champ;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $ancien;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $nouveau;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par;

    #[ORM\Column]
    private \DateTimeImmutable $changeLe;

    public function __construct(string $cible, int $cibleId, string $libelle, string $champ, ?string $ancien, ?string $nouveau, ?User $par)
    {
        $this->cible = $cible;
        $this->cibleId = $cibleId;
        $this->libelle = mb_substr($libelle, 0, 255);
        $this->champ = $champ;
        $this->ancien = $ancien;
        $this->nouveau = $nouveau;
        $this->par = $par;
        $this->changeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCible(): string
    {
        return $this->cible;
    }

    public function getCibleId(): int
    {
        return $this->cibleId;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getChamp(): string
    {
        return $this->champ;
    }

    public function getAncien(): ?string
    {
        return $this->ancien;
    }

    public function getNouveau(): ?string
    {
        return $this->nouveau;
    }

    public function getPar(): ?User
    {
        return $this->par;
    }

    public function getChangeLe(): \DateTimeImmutable
    {
        return $this->changeLe;
    }
}
