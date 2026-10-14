<?php

namespace App\Entity\Trait;

use App\Entity\Promotion;
use Doctrine\ORM\Mapping as ORM;

/**
 * Promotion appliquée à une ligne de devis ou de commande
 * (docs/promotions.md). Une seule par ligne, calculée par le serveur à
 * l'enregistrement (PromotionsSaisie) : le navigateur n'envoie que le
 * choix de l'agent (retirer la promotion, demander l'exception B2B).
 *
 * La remise de la promotion est un montant par unité facturable, comme
 * la remise saisie : elle s'y ajoute dans le total de la ligne. Le libellé
 * garde le nom et l'avantage du jour, pour l'historique, les documents et
 * les rapports, même après la fin de la promotion.
 */
trait PromotionLigneTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Promotion $promotion = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $libellePromotion = null;

    /** F par unité facturable. */
    #[ORM\Column(options: ['default' => 0])]
    private int $remisePromotion = 0;

    /** Montant retiré par la promotion sur la ligne (F, hors TVA), pour les rapports. */
    #[ORM\Column(options: ['default' => 0])]
    private int $montantPromotion = 0;

    /** L'agent a retiré la promotion : elle ne revient pas toute seule. */
    #[ORM\Column(options: ['default' => false])]
    private bool $promotionRetiree = false;

    public function getPromotion(): ?Promotion { return $this->promotion; }
    public function getLibellePromotion(): ?string { return $this->libellePromotion; }
    public function getRemisePromotion(): int { return $this->remisePromotion; }
    public function getMontantPromotion(): int { return $this->montantPromotion; }
    public function isPromotionRetiree(): bool { return $this->promotionRetiree; }
    public function setPromotionRetiree(bool $retiree): static { $this->promotionRetiree = $retiree; return $this; }

    public function appliquerPromotion(Promotion $promotion, int $remiseUnitaire): static
    {
        $this->promotion = $promotion;
        $this->libellePromotion = $promotion->libelleLigne();
        $this->remisePromotion = max(0, $remiseUnitaire);

        return $this;
    }

    /** Copie d'une ligne de devis transformée en commande : la promotion suit telle quelle. */
    public function reprendrePromotion(?Promotion $promotion, ?string $libelle, int $remiseUnitaire, int $montant, bool $retiree): static
    {
        $this->promotion = $promotion;
        $this->libellePromotion = $libelle;
        $this->remisePromotion = max(0, $remiseUnitaire);
        $this->montantPromotion = max(0, $montant);
        $this->promotionRetiree = $retiree;

        return $this;
    }

    public function retirerPromotion(): static
    {
        $this->promotion = null;
        $this->libellePromotion = null;
        $this->remisePromotion = 0;
        $this->montantPromotion = 0;

        return $this;
    }

    /** Unités facturables de la ligne (m², pièces...), pour la promotion. */
    public function getUnitesFacturables(): float
    {
        return max(0, $this->calculerFacteur());
    }

    /** Total de la ligne avant toute remise (impression et finitions). */
    public function getTotalAvantRemise(): int
    {
        return $this->calculerTotalBrut();
    }

    /**
     * Part de la remise de la ligne due à la promotion : remise totale
     * moins ce qu'aurait retiré la remise saisie seule (les deux sont
     * plafonnées au total brut).
     */
    private function calculerMontantPromotion(int $totalBrut, float $facteur): int
    {
        if ($this->remisePromotion <= 0) {
            return 0;
        }
        $seule = min($totalBrut, max(0, (int) round($this->getRemise() * $facteur)));
        $avec = min($totalBrut, max(0, (int) round(($this->getRemise() + $this->remisePromotion) * $facteur)));

        return max(0, $avec - $seule);
    }
}
