<?php

namespace App\Controller\Finances;

/**
 * Ce que la personne peut voir sur les écrans Finances : soldes et
 * prévisions (trésorerie), chiffre d'affaires global, paiements, coûts
 * et marges, et ce qui est réservé à la direction (approbation des
 * dépenses, comptabilité).
 */
trait DroitsFinances
{
    /**
     * @return array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie: bool}
     */
    private function droits(): array
    {
        return [
            'tresorerie' => $this->isGranted('ROLE_TRESORERIE_VOIR'),
            'ca' => $this->isGranted('ROLE_STATS_GLOBAL'),
            'paiements' => $this->isGranted('ROLE_PAIEMENT_VOIR'),
            'marges' => $this->isGranted('ROLE_COUT_MARGE'),
            'admin' => $this->isGranted('ROLE_ADMIN'),
            'saisie' => $this->isGranted('ROLE_TRESORERIE_SAISIR'),
        ];
    }
}
