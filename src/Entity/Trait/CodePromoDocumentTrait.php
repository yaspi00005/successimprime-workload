<?php

namespace App\Entity\Trait;

use App\Entity\CodePromo;
use Doctrine\ORM\Mapping as ORM;

/**
 * Code promo d'un devis ou d'une commande (docs/promotions.md, « Codes
 * promo ») : un seul par document, posé par le serveur à l'enregistrement
 * (PromotionsSaisie), jamais repris du navigateur.
 *
 * - codePromo : le code retenu, qui compte une utilisation. Seule la
 *   boutique le pose : une commande passée en ligne arrive avec le code
 *   tapé (codeSaisi) et la boutique le revérifie en la confirmant.
 * - remiseCodePromo : l'avantage sur la commande, hors TVA, réparti sur
 *   les lignes (PromotionLigneTrait::remiseCodePromo) et déjà dans les
 *   totaux. Les lignes et les factures le montrent : jamais de prix gonflé.
 * - motifCodePromo : pourquoi le code tapé n'a pas été retenu (au local,
 *   pour l'équipe).
 */
trait CodePromoDocumentTrait
{
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CodePromo $codePromo = null;

    /** Code tapé, lettres et chiffres seulement. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $codeSaisi = null;

    /** Avantage du code sur la commande (F, hors TVA), somme des parts des lignes. */
    #[ORM\Column(options: ['default' => 0])]
    private int $remiseCodePromo = 0;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $motifCodePromo = null;

    public function getCodePromo(): ?CodePromo { return $this->codePromo; }
    public function setCodePromo(?CodePromo $code): static { $this->codePromo = $code; return $this; }
    public function getCodeSaisi(): ?string { return $this->codeSaisi; }
    public function setCodeSaisi(?string $code): static { $this->codeSaisi = null !== $code && '' !== $code ? mb_substr($code, 0, 30) : null; return $this; }
    public function getRemiseCodePromo(): int { return $this->remiseCodePromo; }
    public function setRemiseCodePromo(int $montant): static { $this->remiseCodePromo = max(0, $montant); return $this; }
    public function getMotifCodePromo(): ?string { return $this->motifCodePromo; }
    public function setMotifCodePromo(?string $motif): static { $this->motifCodePromo = null !== $motif && '' !== $motif ? mb_substr($motif, 0, 160) : null; return $this; }

    /**
     * Le code à montrer : celui retenu, sinon celui tapé ; un code de lot avec
     * ses tirets (SI-7K4P-2Q). En ligne, le code retenu n'a que son empreinte :
     * c'est le code tapé qui se montre.
     */
    public function getLibelleCodePromo(): ?string
    {
        if (null !== $this->codePromo?->getCode()) {
            return $this->codePromo->getCodeAffiche();
        }
        if (null !== $this->codeSaisi && 1 === preg_match('/^SI[A-HJKMNP-Z2-9]{6}$/', $this->codeSaisi)) {
            return 'SI-'.substr($this->codeSaisi, 2, 4).'-'.substr($this->codeSaisi, 6, 2);
        }

        return $this->codeSaisi;
    }

    /** Ce que le code retire au total TTC (lignes non annulées), pour l'affichage. */
    public function getRemiseCodePromoTtc(): int
    {
        if ($this->remiseCodePromo <= 0) {
            return 0;
        }
        $total = 0;
        $lignes = $this instanceof \App\Entity\Commandes ? $this->getCommandesDetails() : $this->getDevisDetails();
        foreach ($lignes as $ligne) {
            if ($ligne instanceof \App\Entity\CommandesDetails && \App\Entity\CommandesDetails::PRODUCTION_ANNULEE === $ligne->getStatutProduction()) {
                continue;
            }
            $total += $ligne->getRemiseCodePromoTtc();
        }

        return $total;
    }

    /** Total hors TVA avant le code : ce que la facture montre au-dessus de la ligne « Code promo ». */
    public function getTotalHtAvantCodePromo(): int
    {
        return (int) $this->getTotalHt() + $this->remiseCodePromo;
    }
}
