<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une pièce posée sur un tirage (maquette PD02) : un exemplaire d'un ordre
 * de production, à sa place sur le rouleau (en cm, coin en haut à gauche,
 * y croît dans le sens du rouleau), tournée ou non, avec son rang
 * d'impression. Le statut (à imprimer, en cours, imprimée) servira au
 * suivi en direct (PD04).
 */
#[ORM\Entity]
#[ORM\Table(name: 'piece_tirage')]
class PieceTirage
{
    public const STATUT_A_IMPRIMER = 'a_imprimer';
    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_IMPRIMEE = 'imprimee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pieces')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TirageRouleau $tirage;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrdreProduction $ordre;

    #[ORM\Column]
    private int $rang;

    /** 1 à la quantité de la ligne. */
    #[ORM\Column]
    private int $exemplaire;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $xCm;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $yCm;

    /** Taille telle que posée (en travers × le long du rouleau). */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $largeurCm;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $hauteurCm;

    #[ORM\Column(options: ['default' => false])]
    private bool $tourne = false;

    #[ORM\Column(length: 12)]
    private string $statut = self::STATUT_A_IMPRIMER;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imprimeeLe = null;

    public function __construct(TirageRouleau $tirage, OrdreProduction $ordre, int $exemplaire)
    {
        $this->tirage = $tirage;
        $this->ordre = $ordre;
        $this->exemplaire = max(1, $exemplaire);
        $this->rang = 1;
        $this->xCm = $this->yCm = $this->largeurCm = $this->hauteurCm = '0';
        $tirage->ajouterPiece($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTirage(): TirageRouleau
    {
        return $this->tirage;
    }

    public function getOrdre(): OrdreProduction
    {
        return $this->ordre;
    }

    public function getRang(): int
    {
        return $this->rang;
    }

    public function getExemplaire(): int
    {
        return $this->exemplaire;
    }

    public function placer(int $rang, float $x, float $y, float $largeur, float $hauteur, bool $tourne): static
    {
        $this->rang = max(1, $rang);
        $this->xCm = (string) round($x, 2);
        $this->yCm = (string) round($y, 2);
        $this->largeurCm = (string) round($largeur, 2);
        $this->hauteurCm = (string) round($hauteur, 2);
        $this->tourne = $tourne;

        return $this;
    }

    public function getXCm(): float
    {
        return (float) $this->xCm;
    }

    public function getYCm(): float
    {
        return (float) $this->yCm;
    }

    public function getLargeurCm(): float
    {
        return (float) $this->largeurCm;
    }

    public function getHauteurCm(): float
    {
        return (float) $this->hauteurCm;
    }

    public function isTourne(): bool
    {
        return $this->tourne;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function mettreEnCours(): static
    {
        $this->statut = self::STATUT_EN_COURS;

        return $this;
    }

    public function marquerImprimee(\DateTimeImmutable $le): static
    {
        $this->statut = self::STATUT_IMPRIMEE;
        $this->imprimeeLe = $le;

        return $this;
    }

    public function getImprimeeLe(): ?\DateTimeImmutable
    {
        return $this->imprimeeLe;
    }

    /** Clé lisible dans le placement : « <id de l'ordre>-<exemplaire> ». */
    public function cle(): string
    {
        return $this->ordre->getId().'-'.$this->exemplaire;
    }
}
