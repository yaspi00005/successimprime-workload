<?php

namespace App\Service;

/**
 * Totaux d'un devis, d'une commande ou d'une facture avec une remise
 * globale (docs/factures-entreprises.md, « Remise globale ») :
 *
 *   Sous-total HT  = lignes HT (code promo déjà déduit) + frais de livraison
 *   Remise globale = montant saisi, au plus le sous-total
 *   Total HT       = sous-total HT − remise globale
 *   TVA            = TVA des lignes, réduite dans la même proportion que le HT
 *   Total TTC      = total HT + TVA
 *
 * Le total TTC est ce que le client doit : le reste à payer est total TTC −
 * déjà payé. Sans TVA sur les lignes, la TVA vaut 0 et le total TTC est le
 * sous-total moins la remise. Calcul pur, sans base, pour être testé.
 */
final class TotauxDocument
{
    /**
     * @param int $sousTotalHt lignes HT + frais de livraison, avant la remise globale
     * @param int $tvaLignes   somme (TTC − HT) des lignes, avant la remise globale
     * @param int $remise      remise globale saisie (F)
     *
     * @return array{sousTotalHt: int, remise: int, totalHt: int, tva: int, totalTtc: int}
     */
    public static function calculer(int $sousTotalHt, int $tvaLignes, int $remise): array
    {
        $sousTotalHt = max(0, $sousTotalHt);
        $remise = min(max(0, $remise), $sousTotalHt);
        $totalHt = $sousTotalHt - $remise;
        // La remise baisse la base de la TVA : même proportion pour la TVA.
        $tva = $sousTotalHt > 0 ? (int) round(max(0, $tvaLignes) * $totalHt / $sousTotalHt) : 0;

        return [
            'sousTotalHt' => $sousTotalHt,
            'remise' => $remise,
            'totalHt' => $totalHt,
            'tva' => $tva,
            'totalTtc' => $totalHt + $tva,
        ];
    }
}
