<?php

namespace App\Service\Conception;

use App\Service\Conception\Generateur\GenerateurImages;
use App\Service\Parametres\Parametres;

/**
 * Réglages de la tuile Conception (Paramètres), lus par l'espace client
 * et l'atelier. Le site reçoit les valeurs publiques du local.
 */
final class ReglagesConception
{
    public function __construct(
        private readonly Parametres $parametres,
        private readonly GenerateurImages $generateur,
    ) {
    }

    /** Proposée au client : interrupteur ouvert et fournisseur prêt (clé posée). */
    public function iaDisponible(): bool
    {
        return (bool) ($this->parametres->valeur('conception', 'ia_active') ?? true) && $this->generateur->estDisponible();
    }

    public function prixImage(): int
    {
        return max(0, (int) ($this->parametres->valeur('conception', 'prix_image') ?? 1000));
    }

    public function finalisation(): int
    {
        return max(0, (int) ($this->parametres->valeur('conception', 'finalisation') ?? 5000));
    }

    /** @return list<int> */
    public function packs(): array
    {
        return TarifConception::packs((string) ($this->parametres->valeur('conception', 'packs') ?? ''));
    }

    public function plafondJour(): int
    {
        return max(0, (int) ($this->parametres->valeur('conception', 'plafond_jour') ?? 200));
    }

    public function limiteHeure(): int
    {
        return max(1, (int) ($this->parametres->valeur('conception', 'limite_heure') ?? 10));
    }

    /** Coût d'une image chez le fournisseur : au local, pour la direction seulement. */
    public function coutImage(): int
    {
        return max(0, (int) ($this->parametres->valeur('conception', 'cout_image') ?? 0));
    }

    public function generateur(): GenerateurImages
    {
        return $this->generateur;
    }
}
