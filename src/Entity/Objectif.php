<?php

namespace App\Entity;

use App\Repository\ObjectifRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Objectif d'un mois pour un indicateur (Statistiques › Objectifs) :
 * global, ou pour une catégorie de produits, un agent ou une machine
 * (axe + id de la cible). Sans ligne en base, l'objectif global de CA
 * reprend le réglage « Objectif de CA facturé par mois » et celui d'un
 * agent son « Objectif de ventes du mois » (fiche utilisateur).
 * Voir App\Service\Statistiques\Objectifs. Table locale, non synchronisée.
 */
#[ORM\Entity(repositoryClass: ObjectifRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_objectif_mois_indicateur_cible', columns: ['mois', 'indicateur', 'axe', 'cible'])]
class Objectif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Premier jour du mois. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $mois;

    #[ORM\Column(length: 30)]
    private string $indicateur;

    /** 'global', 'categorie', 'agent' ou 'machine'. */
    #[ORM\Column(length: 20)]
    private string $axe;

    /** Id de la catégorie, de l'agent ou de la machine ; 0 pour global. */
    #[ORM\Column]
    private int $cible;

    /** F, nombre, m² ou % selon l'indicateur. */
    #[ORM\Column]
    private int $valeur = 0;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    public function __construct(\DateTimeImmutable $mois, string $indicateur, string $axe = 'global', int $cible = 0)
    {
        $this->mois = $mois->modify('first day of this month')->setTime(0, 0);
        $this->indicateur = $indicateur;
        $this->axe = $axe;
        $this->cible = 'global' === $axe ? 0 : $cible;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMois(): \DateTimeImmutable { return $this->mois; }
    public function getIndicateur(): string { return $this->indicateur; }
    public function getAxe(): string { return $this->axe; }
    public function getCible(): int { return $this->cible; }
    public function getValeur(): int { return $this->valeur; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function getModifiePar(): ?User { return $this->modifiePar; }

    /** Clé « indicateur:axe:cible » des tableaux d'objectifs. */
    public function getCle(): string
    {
        return $this->indicateur.':'.$this->axe.':'.$this->cible;
    }

    public function modifier(int $valeur, ?User $par, \DateTimeImmutable $le): static
    {
        $this->valeur = max(0, $valeur);
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }
}
