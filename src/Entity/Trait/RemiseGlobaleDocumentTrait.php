<?php

namespace App\Entity\Trait;

/**
 * Lecture des totaux d'un devis ou d'une commande avec sa remise globale
 * (App\Service\TotauxDocument, docs/factures-entreprises.md, « Remise
 * globale ») :
 *
 * - totalHt  : sous-total HT, lignes et frais de livraison, avant la remise ;
 * - remise   : remise globale (F), au plus le sous-total ;
 * - totalTtc : ce que le client doit, remise déduite, TVA comprise.
 *
 * Total HT après remise et TVA s'en déduisent : TTC = HT après remise + TVA.
 */
trait RemiseGlobaleDocumentTrait
{
    /** Remise globale retenue : au plus le sous-total HT. */
    public function getRemiseGlobale(): int
    {
        return min(max(0, $this->getRemise()), max(0, $this->getTotalHt()));
    }

    /** Total HT après la remise globale. */
    public function getTotalHtNet(): int
    {
        return max(0, $this->getTotalHt() - $this->getRemiseGlobale());
    }

    /** TVA du document, remise déduite : 0 sans TVA sur les lignes. */
    public function getMontantTva(): int
    {
        return max(0, $this->getTotalTtc() - $this->getTotalHtNet());
    }
}
