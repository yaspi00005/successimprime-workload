<?php

namespace App\Service;

use App\Entity\Commandes;

final class BonusPlafondClientService
{
    /**
     * Applique une seule fois le bonus de plafond
     * correspondant à 1 % du montant TTC de la commande.
     */
    public function appliquer(
        Commandes $commande
    ): int {
        /*
         * ============================================================
         * SÉCURITÉ : UNE SEULE APPLICATION PAR COMMANDE
         * ============================================================
         */

        if (
            $commande->isBonusPlafondApplique()
        ) {
            return 0;
        }


        $client =
            $commande->getClients();


        if ($client === null) {
            return 0;
        }


        /*
         * ============================================================
         * MONTANT DE LA COMMANDE
         * ============================================================
         */

        $montantCommande =
            max(
                0,
                (int) $commande->getTotalTtc()
            );


        if ($montantCommande <= 0) {
            return 0;
        }


        /*
         * ============================================================
         * BONUS = 1 %
         * ============================================================
         */

        $bonus =
            (int) round(
                $montantCommande * 0.01
            );


        if ($bonus <= 0) {
            return 0;
        }


        /*
         * ============================================================
         * ANCIEN PLAFOND
         * ============================================================
         */

        $ancienPlafond =
            max(
                0,
                (int) $client->getPlafondCredit()
            );


        /*
         * ============================================================
         * NOUVEAU PLAFOND
         * ============================================================
         */

        $nouveauPlafond =
            $ancienPlafond
            +
            $bonus;


        $client->setPlafondCredit(
            $nouveauPlafond
        );


        /*
         * ============================================================
         * MÉMORISER LE BONUS SUR LA COMMANDE
         * ============================================================
         */

        $commande
            ->setBonusPlafondApplique(
                true
            )
            ->setMontantBonusPlafond(
                $bonus
            );


        return $bonus;
    }
}