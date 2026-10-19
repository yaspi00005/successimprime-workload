<?php

namespace App\Security;

use App\Entity\Notification;
use App\Entity\User;

/**
 * Le livreur ne voit que ses livraisons et sa tournée (décision de Yaya,
 * 15/10/2026) : une personne dont le seul droit est « Livraisons » n'ouvre
 * que les écrans de ROUTES. Toute autre page lui répond « accès refusé »
 * (LivreurSeulSubscriber), même tapée à la main : tableau de bord, clients,
 * commandes, statistiques, recherche, messagerie des clients, liste générale des
 * livraisons et des retraits.
 *
 * Reste ouvert ce que tout le personnel a pour lui-même (Mon espace,
 * pointage, mot de passe, guide de son rôle) : rien sur l'activité de l'imprimerie.
 * Dès qu'un autre droit est coché pour la personne (clients,
 * entretien…), elle n'est plus « livreur seul » et retrouve les écrans
 * de ses droits.
 */
final class LivreurSeul
{
    /**
     * Droits qui n'ouvrent rien de plus à un livreur (ses propres statistiques : ventes et encaissements ;
     * la messagerie de l'équipe, demande de Yaya du 19/10/2026 : les groupes, photos et vocaux depuis le téléphone).
     */
    public const SANS_EFFET = ['ROLE_LIVRAISON', 'ROLE_STATS_PERSO', Droits::MESSAGERIE_INTERNE];

    /** Son menu : sa tournée et ses livraisons, rien d'autre (NavigationExtension). */
    public const MENU = ['app_livraisons_tournee', 'app_livraisons_mes_livraisons', 'app_messagerie_interne_index'];

    /**
     * Pages permises : nom de route exact, ou début de nom quand il finit
     * par « _ ».
     */
    public const ROUTES = [
        // Ses livraisons et sa tournée.
        'app_livraisons_tournee',
        'app_livraisons_tournee_',
        'app_livraisons_remise',
        'app_livraisons_mes_livraisons',
        // Photo ou signature d'une de ses livraisons (TourneeController::preuve vérifie le livreur).
        'app_livraisons_bl_preuve',
        // L'accueil le renvoie sur sa tournée (DashboardController).
        'app_home',
        // Ce que tout le personnel a pour lui-même.
        'app_mon_espace_',
        'app_pointer_',
        'app_photo_employe',
        'app_user_change_password',
        'app_aide_',
        // Messagerie de l'équipe (docs/messagerie-interne.md) : ses groupes et ses messages privés.
        'app_messagerie_interne_',
        // Ses notifications : seulement celles de ces pages (NotificationsExtension, NotificationController).
        'app_notification_',
        // Connexion, code de sécurité en ligne, verrouillage.
        'app_login',
        'app_logout',
        'app_lockscreen',
        'app_switch_user_login',
        'app_verification',
        'app_verification_renvoyer',
    ];

    /**
     * Livreur seul : il a le droit Livraisons et aucun autre droit qui
     * ouvre un écran. La Direction ne l'est jamais.
     *
     * @param list<string> $roles rôles de la personne (User::getRoles) ou droits d'un rôle
     */
    public static function est(array $roles): bool
    {
        if (\in_array(User::ROLE_ADMIN, $roles, true) || !\in_array('ROLE_LIVRAISON', $roles, true)) {
            return false;
        }

        return [] === array_diff(array_intersect($roles, Droits::sansRestriction()), self::SANS_EFFET);
    }

    /**
     * Page d'arrivée d'un livreur : sa tournée sur l'ERP local. En ligne,
     * la tournée n'existe pas (LivreurSeulSubscriber) : son espace, ou à
     * défaut son mot de passe.
     */
    public static function accueil(bool $enLigne, bool $employe): string
    {
        if (!$enLigne) {
            return 'app_livraisons_tournee';
        }

        return $employe ? 'app_mon_espace_index' : 'app_user_change_password';
    }

    /**
     * Ses notifications qu'il peut voir : sans lien, ou qui mènent à une page permise.
     *
     * @param iterable<Notification> $notifications
     *
     * @return list<Notification>
     */
    public static function notificationsVisibles(iterable $notifications): array
    {
        $visibles = [];
        foreach ($notifications as $n) {
            if (null === $n->getRoute() || self::routePermise($n->getRoute())) {
                $visibles[] = $n;
            }
        }

        return $visibles;
    }

    public static function routePermise(?string $route): bool
    {
        if (null === $route || '' === $route) {
            return false;
        }
        // Outils de développement (barre de débogage, aperçu des pages d'erreur).
        if (str_starts_with($route, '_')) {
            return true;
        }
        foreach (self::ROUTES as $permise) {
            if ($route === $permise || (str_ends_with($permise, '_') && str_starts_with($route, $permise))) {
                return true;
            }
        }

        return false;
    }
}
