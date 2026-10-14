<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Promo de réveil d'un client dormeur (docs/promotions.md, « Clients
 * dormeurs » ; docs/fidelite.md) : une remise en pourcentage donnée par
 * l'administrateur, valable un nombre de jours (ou seulement le jour même),
 * affichée dans l'espace client et appliquée par l'atelier à la commande.
 *
 * Descend sur le site (taux, dates, état) ; qui l'a donnée reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'promo_reveil')]
#[ORM\Index(name: 'idx_promo_reveil_client_fin', columns: ['client_id', 'fin'])]
class PromoReveil
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $taux = '10';

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    /** Dernier jour de validité (compris). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $fin;

    /** Jours sans commande au moment où elle a été donnée. */
    #[ORM\Column(nullable: true)]
    private ?int $joursSansCommande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creeLe;

    /** Commande sur laquelle l'atelier l'a appliquée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $utiliseeLe = null;

    #[ORM\Column(nullable: true)]
    private ?int $montantRemise = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $annuleeLe = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->debut = new \DateTimeImmutable('today');
        $this->fin = new \DateTimeImmutable('today');
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function setClient(?Clients $client): static { $this->client = $client; return $this; }
    public function getTaux(): float { return (float) $this->taux; }
    public function setTaux(float $taux): static { $this->taux = number_format(min(50, max(0, $taux)), 2, '.', ''); return $this; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function setDebut(\DateTimeImmutable $d): static { $this->debut = $d->setTime(0, 0); return $this; }
    public function getFin(): \DateTimeImmutable { return $this->fin; }
    public function setFin(\DateTimeImmutable $d): static { $this->fin = $d->setTime(0, 0); return $this; }
    public function getJoursSansCommande(): ?int { return $this->joursSansCommande; }
    public function setJoursSansCommande(?int $j): static { $this->joursSansCommande = $j; return $this; }
    public function getCreePar(): ?User { return $this->creePar; }
    public function setCreePar(?User $u): static { $this->creePar = $u; return $this; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function getUtiliseeLe(): ?\DateTimeImmutable { return $this->utiliseeLe; }
    public function getMontantRemise(): ?int { return $this->montantRemise; }
    public function getAnnuleeLe(): ?\DateTimeImmutable { return $this->annuleeLe; }

    public function utiliser(Commandes $commande, int $montant, \DateTimeImmutable $le): static
    {
        $this->commande = $commande;
        $this->montantRemise = $montant;
        $this->utiliseeLe = $le;

        return $this;
    }

    public function annuler(\DateTimeImmutable $le): static
    {
        $this->annuleeLe = $le;

        return $this;
    }

    /** Encore utilisable ce jour-là. */
    public function estValableLe(\DateTimeInterface $jour): bool
    {
        $j = $jour->format('Y-m-d');

        return null === $this->utiliseeLe && null === $this->annuleeLe
            && $this->debut->format('Y-m-d') <= $j && $j <= $this->fin->format('Y-m-d');
    }

    public function estDuJourSeulement(): bool
    {
        return $this->debut->format('Y-m-d') === $this->fin->format('Y-m-d');
    }
}
