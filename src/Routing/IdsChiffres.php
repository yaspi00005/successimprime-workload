<?php

namespace App\Routing;

/**
 * Quels paramètres des URL portent un numéro de fiche à chiffrer
 * (docs/ids-chiffres.md). Le même nom est chiffré à la création du lien
 * (RouteurIdsChiffres), accepté par la route (ChargeurRoutesIdsChiffres)
 * et déchiffré à l'arrivée (IdsChiffresSubscriber) : un nom de trop ne
 * casse rien, il fait juste l'aller-retour.
 */
final class IdsChiffres
{
    /** Dans le chemin (« /commandes/{id} ») ou dans la requête (« ?commande= »). */
    public const NOMS = [
        'id' => true,
        'ligne' => true,
        'paiement' => true,
        'destinataire' => true,
        'suspension' => true,
        'configuration' => true,
        'commande' => true,
        'facture' => true,
        'devis' => true,
        'client' => true,
        'avoir' => true,
        'produit' => true,
        'article' => true,
        'fournisseur' => true,
        'achat' => true,
        'livreur' => true,
        'agent' => true,
        'prospect' => true,
        'personne' => true,
        'employe' => true,
        'machine' => true,
        'ordre' => true,
        'depense' => true,
        'conception' => true,
        'entite_id' => true,
    ];

    /** Le numéro de fiche porté par une valeur (entier ou chiffres, 8 au plus), sinon null. */
    public static function numero(mixed $valeur): ?int
    {
        if (\is_int($valeur)) {
            return $valeur > 0 && $valeur <= 0xFFFFFFFF ? $valeur : null;
        }
        if (\is_string($valeur) && '' !== $valeur && \strlen($valeur) <= 8 && ctype_digit($valeur) && '0' !== $valeur[0]) {
            return (int) $valeur;
        }

        return null;
    }

    /** Les routes du framework, de l'API et de la barre de débogage gardent leurs numéros. */
    public static function routeConcernee(?string $route): bool
    {
        return null !== $route && '' !== $route && !str_starts_with($route, '_') && !str_starts_with($route, 'api_');
    }
}
