<?php

namespace App\Service\Finances;

use App\Service\Parametres\Parametres;

/**
 * Réglages des écrans Finances (tuile « Finances et budgets » des
 * Paramètres) : objectif de CA, seuil d'alerte de trésorerie, seuil
 * d'approbation des dépenses et coût d'une heure de main d'œuvre.
 * Sans réglage enregistré, les valeurs par défaut du catalogue servent.
 */
final class ReglagesFinances
{
    public const TUILE = 'finances';

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /** Objectif de CA facturé du mois (F). */
    public function objectifCa(): int
    {
        return $this->entier('objectif_ca');
    }

    /** Sous ce solde prévu, la direction est prévenue (F). */
    public function seuilTresorerie(): int
    {
        return $this->entier('seuil_tresorerie');
    }

    /** Une dépense au-delà de ce montant attend l'approbation de la direction (F). */
    public function seuilApprobation(): int
    {
        return $this->entier('seuil_approbation');
    }

    /** Coût d'une heure de main d'œuvre, pour la rentabilité (F). */
    public function coutHoraire(): int
    {
        return $this->entier('cout_horaire');
    }

    private function entier(string $cle): int
    {
        return max(0, (int) $this->parametres->valeur(self::TUILE, $cle));
    }
}
