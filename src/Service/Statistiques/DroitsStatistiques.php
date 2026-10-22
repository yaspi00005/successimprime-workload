<?php

namespace App\Service\Statistiques;

/**
 * Ce qu'une personne peut voir dans les statistiques, les exports et le
 * rapport de la semaine. Règle du propriétaire : « toute info sensible
 * doit tenir compte des droits et rôles, tout le monde ne doit pas tout
 * voir ». Un chiffre caché n'est jamais calculé ni envoyé à la page, au
 * fichier exporté ou au rapport.
 *
 *  - global : chiffres de toute l'entreprise et par agent, catégorie ou
 *    machine (commandes, m², nouveaux clients) — ROLE_STATS_GLOBAL ;
 *  - ventes : montants des ventes de l'entreprise (CA facturé, panier,
 *    CA par client) — ROLE_STATS_GLOBAL et ROLE_STATS_VENTES, comme
 *    l'écran Statistiques et rapports ;
 *  - paiements : encaissé, taux d'encaissement, créances — ROLE_PAIEMENT_VOIR ;
 *  - tresorerie : encaissements et décaissements des comptes — ROLE_TRESORERIE_VOIR ;
 *  - marges : marge et coûts de revient — ROLE_COUT_MARGE ;
 *  - perso : ses propres ventes, devis, encaissements et travaux — ROLE_STATS_PERSO ;
 *  - admin : fixer les objectifs — ROLE_ADMIN (la Direction).
 */
final class DroitsStatistiques
{
    public const ROLES = [
        'global' => 'ROLE_STATS_GLOBAL',
        'ventes' => 'ROLE_STATS_VENTES',
        'paiements' => 'ROLE_PAIEMENT_VOIR',
        'tresorerie' => 'ROLE_TRESORERIE_VOIR',
        'marges' => 'ROLE_COUT_MARGE',
        'perso' => 'ROLE_STATS_PERSO',
        'admin' => 'ROLE_ADMIN',
    ];

    /**
     * @param callable(string): bool $aLeRole is_granted dans un contrôleur,
     *                                        rôles atteignables dans une commande
     *
     * @return array{global: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, perso: bool, admin: bool}
     */
    public static function calculer(callable $aLeRole): array
    {
        $droits = array_map(static fn (string $role): bool => (bool) $aLeRole($role), self::ROLES);
        // Les montants de vente de l'entreprise ne vont pas sans les chiffres globaux.
        $droits['ventes'] = $droits['ventes'] && $droits['global'];

        return $droits;
    }

    /**
     * Pour une personne hors requête (tâche planifiée) : ses rôles
     * atteignables, hiérarchie comprise (RoleHierarchyInterface).
     *
     * @param list<string> $roles
     *
     * @return array{global: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, perso: bool, admin: bool}
     */
    public static function depuisRoles(array $roles): array
    {
        return self::calculer(static fn (string $role): bool => \in_array($role, $roles, true));
    }

    /**
     * Aucun droit : ce que voit une personne sans droit de statistiques.
     *
     * @return array{global: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, perso: bool, admin: bool}
     */
    public static function aucun(): array
    {
        return array_map(static fn (): bool => false, self::ROLES);
    }
}
