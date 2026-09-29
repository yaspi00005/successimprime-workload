<?php

namespace App\Entity;

use App\Repository\ParametresPaiementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/*
 * Table à une seule ligne (singleton) : réglages globaux liés aux
 * paiements. Pour l'instant, uniquement les taux appliqués quand un
 * client paie par mobile money (Orange Money / Wave) et couvre les
 * frais de retrait et le fonds de soutien en plus du prix de la
 * commande. Voir Paiements::$fraisRetraitInclus / $fondsSoutienInclus.
 */
#[ORM\Entity(repositoryClass: ParametresPaiementRepository::class)]
#[ORM\Table(name: 'parametres_paiement')]
class ParametresPaiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 1.0])]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(100)]
    private float $tauxFraisRetrait = 1.0;

    #[ORM\Column(options: ['default' => 1.0])]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(100)]
    private float $tauxFondsSoutien = 1.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTauxFraisRetrait(): float
    {
        return $this->tauxFraisRetrait;
    }

    public function setTauxFraisRetrait(float $tauxFraisRetrait): static
    {
        $this->tauxFraisRetrait = $tauxFraisRetrait;

        return $this;
    }

    public function getTauxFondsSoutien(): float
    {
        return $this->tauxFondsSoutien;
    }

    public function setTauxFondsSoutien(float $tauxFondsSoutien): static
    {
        $this->tauxFondsSoutien = $tauxFondsSoutien;

        return $this;
    }

    /*
     * Calcule le montant du frais de retrait pour un montant encaissé
     * donné (arrondi au franc le plus proche).
     */
    public function calculerFraisRetrait(int $montant): int
    {
        return (int) round($montant * $this->tauxFraisRetrait / 100);
    }

    /*
     * Calcule le montant du fonds de soutien pour un montant encaissé
     * donné (arrondi au franc le plus proche).
     */
    public function calculerFondsSoutien(int $montant): int
    {
        return (int) round($montant * $this->tauxFondsSoutien / 100);
    }
}
