<?php

namespace App\Service\EspaceClient;

use App\Entity\Commandes;

/**
 * Ce que le client choisit autour du produit dans la nouvelle commande en
 * ligne (étapes 2 et 4) : délai, réception, créneau. Les montants
 * (majoration express, frais de livraison) viennent des Réglages, jamais
 * de la page.
 */
final class OptionsCommande
{
    public function __construct(
        public readonly string $delai = Commandes::DELAI_STANDARD,
        /** Majoration express en % du prix des produits (Réglages › Espace client). */
        public readonly int $majorationExpress = 0,
        public readonly bool $livraison = false,
        /** Frais de « Livraison à Bamako » (Réglages › Livraison et objet), ou 0. */
        public readonly int $fraisLivraison = 0,
        public readonly ?string $adresse = null,
        public readonly ?string $creneau = null,
        /** Jour du créneau choisi, ou jour où la commande sera prête (retrait). */
        public readonly ?\DateTimeImmutable $dateLivraison = null,
    ) {
    }

    public function estExpress(): bool
    {
        return Commandes::DELAI_EXPRESS === $this->delai;
    }
}
