<?php

namespace App\Service\Rapports;

/**
 * Ce que chaque destinataire trouve dans son rapport par e-mail, d'après
 * ses droits (docs/rapports-mail.md). Règle du propriétaire : « toute
 * info sensible doit tenir compte des droits et rôles, tout le monde ne
 * doit pas tout voir ». Une section fermée n'est ni calculée, ni écrite
 * dans l'e-mail, ni dans le PDF joint.
 *
 *  - rapport : recevoir les rapports et voir l'activité de l'entreprise
 *    (volumes : devis, commandes, livraisons, BAT, nouveaux clients,
 *    réclamations, retards) — « Chiffre d'affaires global », comme les
 *    chiffres de l'accueil et le rapport de la semaine de la direction ;
 *  - ventes : CA facturé face à l'objectif — avec « Indicateurs
 *    commerciaux » (comme Statistiques) ;
 *  - paiements : encaissé par moyen de paiement, taux d'encaissement,
 *    créances — « Paiements » ;
 *  - tresorerie : dépenses et soldes des comptes — « Trésorerie et soldes » ;
 *  - marges : taux de marge du mois — « Marges et coûts de revient » ;
 *  - clients : noms des clients (nouveaux clients, réclamations, plus
 *    gros débiteurs) — « Clients » ; sans lui, des nombres seulement ;
 *  - rh : présences, retards et absences, avec les noms — « Administrateur RH » ;
 *  - stock : articles sous le seuil — la Direction (le stock lui reste).
 */
final class DroitsRapport
{
    /** Le droit qui permet de recevoir les rapports (et d'ouvrir l'aperçu). */
    public const DROIT = 'ROLE_STATS_GLOBAL';

    public const ROLES = [
        'rapport' => self::DROIT,
        'ventes' => 'ROLE_STATS_VENTES',
        'paiements' => 'ROLE_PAIEMENT_VOIR',
        'tresorerie' => 'ROLE_TRESORERIE_VOIR',
        'marges' => 'ROLE_COUT_MARGE',
        'clients' => 'ROLE_CLIENT',
        'rh' => 'ROLE_RH',
        'stock' => 'ROLE_ADMIN',
    ];

    /** Libellés des parties, pour dire à chacun ce que sa version contient. */
    public const LIBELLES = [
        'rapport' => 'activité',
        'ventes' => 'chiffre d’affaires',
        'paiements' => 'encaissements et créances',
        'tresorerie' => 'dépenses et trésorerie',
        'marges' => 'marge',
        'clients' => 'noms des clients',
        'rh' => 'présences de l’équipe',
        'stock' => 'stock',
    ];

    /**
     * @param callable(string): bool $aLeRole is_granted dans un contrôleur,
     *                                        rôles atteignables dans une commande
     *
     * @return array{rapport: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, clients: bool, rh: bool, stock: bool}
     */
    public static function calculer(callable $aLeRole): array
    {
        $droits = array_map(static fn (string $role): bool => (bool) $aLeRole($role), self::ROLES);
        if (!$droits['rapport']) {
            // Sans le droit du rapport, aucune partie : il ne reçoit rien.
            return array_map(static fn (): bool => false, self::ROLES);
        }

        return $droits;
    }

    /**
     * Pour un destinataire hors requête (tâche planifiée) : ses rôles
     * atteignables, hiérarchie comprise (RoleHierarchyInterface).
     *
     * @param list<string> $roles
     *
     * @return array{rapport: bool, ventes: bool, paiements: bool, tresorerie: bool, marges: bool, clients: bool, rh: bool, stock: bool}
     */
    public static function depuisRoles(array $roles): array
    {
        return self::calculer(static fn (string $role): bool => \in_array($role, $roles, true));
    }

    /**
     * « activité, chiffre d’affaires, encaissements et créances ».
     *
     * @param array<string, bool> $droits
     */
    public static function contenu(array $droits): string
    {
        $parties = array_keys(array_filter($droits));

        return implode(', ', array_map(static fn (string $p): string => self::LIBELLES[$p], array_values(array_intersect(array_keys(self::LIBELLES), $parties))));
    }

    /**
     * « Direction (tout) », « Sans le chiffre d'affaires ni le stock »…
     *
     * @param array<string, bool> $droits
     */
    public static function version(array $droits): string
    {
        if (!($droits['rapport'] ?? false)) {
            return 'Aucune (pas le droit « Chiffre d’affaires global »)';
        }
        $manque = array_keys(array_filter(array_intersect_key($droits, self::LIBELLES), static fn (bool $v): bool => !$v));
        if ([] === $manque) {
            return 'Complète';
        }

        return 'Sans '.implode(', ', array_map(static fn (string $p): string => self::LIBELLES[$p], $manque));
    }

    /** Empreinte des droits : deux personnes aux mêmes droits reçoivent les mêmes chiffres d'entreprise. */
    public static function signature(array $droits): string
    {
        ksort($droits);

        return implode(',', array_keys(array_filter($droits)));
    }
}
