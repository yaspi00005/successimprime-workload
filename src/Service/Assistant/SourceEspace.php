<?php

namespace App\Service\Assistant;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\DemandeDevis;
use App\Entity\Factures;

/**
 * Ce que les outils de l'assistant lisent de l'espace client. Chaque
 * méthode reçoit la fiche du compte connecté (jamais une fiche choisie par
 * le modèle) et ne renvoie que ce que l'espace client lui montre.
 */
interface SourceEspace
{
    /** @return list<Commandes> commandes visibles dans l'espace, les plus récentes d'abord */
    public function commandes(Clients $client): array;

    /** @return array<string, mixed> EspaceClient::carte() */
    public function carte(Commandes $commande): array;

    /** @return array{total: float, enValidation: int, commandes: list<Commandes>} EspaceClient::solde() */
    public function solde(Clients $client): array;

    /** @return list<Factures> factures émises qui restent à régler */
    public function facturesARegler(Clients $client): array;

    /** @return list<array{nom: string, format: string, prix: string, batLe: ?\DateTimeInterface}> produits enregistrés du client (Mes produits) */
    public function produits(Clients $client): array;

    /** Paiement Orange Money en ligne ouvert (tuile Espace client). */
    public function paiementEnLigne(): bool;

    /**
     * Demande de devis, la même que le formulaire du site, qui monte au local.
     *
     * @param array<string, mixed> $valeurs FormulaireDemande::valider()['valeurs']
     */
    public function enregistrerDemande(array $valeurs, \DateTimeImmutable $le): DemandeDevis;
}
