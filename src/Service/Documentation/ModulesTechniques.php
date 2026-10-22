<?php

namespace App\Service\Documentation;

use App\Security\ModulesRoles;
use App\Twig\NavigationExtension;

/**
 * Range chaque route, service et entité dans un module du guide technique
 * (docs/technique/README.md) : les 7 modules du menu (NavigationExtension)
 * et 5 modules techniques (Sécurité, Synchronisation, Site public, Espace
 * client, Commun).
 *
 * Tout est statique et pur, pour être testé sans base ni noyau. L'ordre
 * des règles d'une route : son adresse quand elle est technique (espace
 * client, site public, synchronisation, connexion), puis l'onglet du menu
 * qui la range (début de nom le plus long), puis la matrice « Rôles et
 * menus » (ModulesRoles), puis le nom de son contrôleur, sinon Commun.
 */
final class ModulesTechniques
{
    public const COMMUN = 'commun';

    /** @var array<string, array{libelle: string, sorte: string}> */
    public const MODULES = [
        'accueil' => ['libelle' => 'Accueil', 'sorte' => 'menu'],
        'ventes' => ['libelle' => 'Ventes', 'sorte' => 'menu'],
        'clients' => ['libelle' => 'Clients', 'sorte' => 'menu'],
        'atelier' => ['libelle' => 'Atelier', 'sorte' => 'menu'],
        'stock' => ['libelle' => 'Stock et achats', 'sorte' => 'menu'],
        'finances' => ['libelle' => 'Finances', 'sorte' => 'menu'],
        'equipe' => ['libelle' => 'Équipe', 'sorte' => 'menu'],
        'reglages' => ['libelle' => 'Réglages', 'sorte' => 'menu'],
        'securite' => ['libelle' => 'Sécurité', 'sorte' => 'technique'],
        'synchronisation' => ['libelle' => 'Synchronisation', 'sorte' => 'technique'],
        'site_public' => ['libelle' => 'Site public', 'sorte' => 'technique'],
        'espace_client' => ['libelle' => 'Espace client', 'sorte' => 'technique'],
        self::COMMUN => ['libelle' => 'Commun', 'sorte' => 'technique'],
    ];

    /** Adresses du site public (comme RecetteAcces::estPageAdmin et security.yaml). */
    private const CHEMINS_SITE_PUBLIC = '#^/((site|nos-produits|calculateur|studio-textile|atelier-3d|realisations|equipements|guide-fichiers|a-propos|nos-engagements|faq|suivre-ma-commande|suivre-ma-reclamation|comment-venir|contact|conseils|demander-un-devis|nos-actions-sociales|nous-soutenir)(/|$)|(sitemap\.xml|robots\.txt)$|assistant/(message|note|historique)$|(avis|d|f|om)/)#';

    /** Connexion, appareils, codes, changement d'utilisateur. */
    private const CHEMINS_SECURITE = '#^/(login|logout|lockscreen|changer-utilisateur|appareil/autoriser|acces/(activer|pin))(/|$|\{)#';

    /** Module de la matrice « Rôles et menus » => module du guide. */
    private const MODULES_ROLES = [
        'ventes' => 'ventes', 'clients' => 'clients', 'livraisons' => 'ventes', 'atelier' => 'atelier',
        'pao' => 'atelier', 'stock' => 'stock', 'finances' => 'finances', 'equipe' => 'equipe',
        'entretien' => 'accueil', 'site' => 'reglages', 'messagerie' => 'clients', 'corbeille' => 'reglages',
        'reglages' => 'reglages', 'mon_espace' => 'equipe',
    ];

    /**
     * Début du nom de route => module, pour les routes qu'aucun onglet ne
     * range (pages ouvertes depuis une autre, appels JSON).
     */
    private const PREFIXES_ROUTES = [
        'app_synchronisation_' => 'synchronisation',
        'app_mon_espace' => 'equipe', 'app_pointer' => 'equipe', 'app_tablette' => 'equipe', 'app_conge' => 'equipe',
        'app_reclamation_' => 'finances', 'app_immobilisations_' => 'finances', 'app_echeance' => 'finances',
        'app_livraisons_' => 'ventes', 'app_bon_livraison' => 'ventes', 'app_avoir' => 'finances', 'app_documents_' => 'finances',
        'app_atelier_' => 'atelier', 'app_montage' => 'atelier', 'app_poste' => 'atelier', 'app_machines' => 'atelier',
        'app_aide' => self::COMMUN, 'app_recherche' => self::COMMUN, 'app_notification' => 'accueil',
        'app_tv' => 'reglages', 'app_ecran' => 'reglages', 'app_user_' => 'securite', 'app_appareils_' => 'securite',
        'app_jetons_api_' => 'securite', 'app_logout' => 'securite', 'app_login' => 'securite',
        'api_' => self::COMMUN, '_api_' => self::COMMUN,
    ];

    /** Mot dans le nom court du contrôleur => module (dernier recours avant Commun). */
    private const MOTS_CONTROLEURS = [
        'Espace' => 'espace_client', 'Site' => 'site_public', 'Synchro' => 'synchronisation',
        'Security' => 'securite', 'Appareil' => 'securite', 'Acces' => 'securite', 'Verification' => 'securite',
        'Stock' => 'stock', 'Atelier' => 'atelier', 'Production' => 'atelier', 'Prepresse' => 'atelier', 'PrePresse' => 'atelier',
        'Conception' => 'atelier', 'Machine' => 'atelier', 'Achat' => 'stock', 'Reception' => 'stock', 'Fournisseur' => 'stock', 'Reappro' => 'stock',
        'Tresorerie' => 'finances', 'Comptabilite' => 'finances', 'Paie' => 'equipe', 'Pointage' => 'equipe', 'Employe' => 'equipe',
        'Prime' => 'equipe', 'Devis' => 'ventes', 'Commande' => 'ventes', 'Facture' => 'finances', 'Paiement' => 'finances',
        'Caisse' => 'ventes', 'Livraison' => 'ventes', 'Client' => 'clients', 'Statistique' => 'accueil', 'Rapport' => 'accueil',
        'Parametre' => 'reglages', 'Reglage' => 'reglages', 'Sauvegarde' => 'reglages', 'Corbeille' => 'reglages',
    ];

    /** Sous-dossier de src/Service => module. */
    public const DOSSIERS_SERVICES = [
        'ActionsCaisse' => 'ventes', 'Catalogue' => 'ventes', 'Commandes' => 'ventes', 'Devis' => 'ventes',
        'Facturation' => 'finances', 'Livraisons' => 'ventes', 'Paiement' => 'finances', 'Promotions' => 'ventes',
        'Clients' => 'clients', 'Messagerie' => 'clients', 'Sondages' => 'clients', 'Assistant' => 'clients',
        'Fidelite' => 'clients', 'ProduitsClient' => 'clients',
        'Atelier' => 'atelier', 'Conception' => 'atelier', 'Prepresse' => 'atelier', 'DossiersClients' => 'atelier',
        'Stock' => 'stock', 'Achats' => 'stock', 'SuiviMetiers' => 'atelier',
        'Finances' => 'finances', 'Immobilisations' => 'finances',
        'Equipe' => 'equipe', 'EspaceEmploye' => 'equipe', 'MessagerieInterne' => 'equipe', 'Pointage' => 'equipe', 'Primes' => 'equipe',
        'Statistiques' => 'accueil', 'Rapports' => 'accueil', 'Planning' => 'accueil', 'NotificationsEquipe' => 'accueil',
        'Parametres' => 'reglages', 'Corbeille' => 'reglages', 'Sauvegarde' => 'reglages', 'Societes' => 'reglages',
        'Import' => 'reglages', 'Alertes' => 'reglages', 'Bascule' => 'reglages',
        'Synchro' => 'synchronisation', 'Site' => 'site_public', 'EspaceClient' => 'espace_client',
        'Http' => self::COMMUN, 'Push' => self::COMMUN, 'Guide' => self::COMMUN, 'Fichiers' => self::COMMUN,
        'Installation' => self::COMMUN, 'Recette' => self::COMMUN, 'Documentation' => self::COMMUN,
    ];

    /**
     * Entités rangées à la main (début du nom court => module) : celles que
     * l'usage par les contrôleurs rangerait mal (comptes, synchronisation,
     * contenu du site, réglages).
     */
    public const ENTITES = [
        'User' => 'securite', 'RoleUtilisateur' => 'securite', 'AppareilAutorise' => 'securite', 'JetonApi' => 'securite',
        'JournalActivite' => 'securite',
        'Synchro' => 'synchronisation',
        'CompteClient' => 'espace_client', 'AccesEspaceClient' => 'espace_client', 'AppareilClient' => 'espace_client',
        'AbonnementPushClient' => 'espace_client', 'SuspensionEspace' => 'espace_client', 'NotificationClient' => 'espace_client',
        'ReglementEnLigne' => 'espace_client', 'TransactionOrangeMoney' => 'espace_client', 'PropositionClient' => 'espace_client',
        'ImageSite' => 'site_public', 'PageSite' => 'site_public', 'Realisation' => 'site_public', 'AvisClient' => 'site_public',
        'ArticleConseil' => 'site_public', 'Partenaire' => 'site_public', 'MembreEquipe' => 'site_public',
        'ActionSociale' => 'site_public', 'ReglageSite' => 'site_public', 'Annonce' => 'site_public',
        'DemandeDevis' => 'site_public', 'CreationAtelier' => 'site_public', 'ModeleAtelier' => 'site_public',
        'Reglage' => 'reglages', 'Corbeille' => 'reglages', 'ImportDonnees' => 'reglages', 'Societe' => 'reglages',
        'Entreprise' => 'reglages', 'AlerteSysteme' => 'reglages', 'ParametresPaiement' => 'reglages',
        'Achats' => 'stock', 'AchatDetail' => 'stock', 'Fournisseurs' => 'stock', 'ArticleFournisseur' => 'stock',
        'ReclamationFournisseur' => 'stock', 'Stock' => 'stock', 'Articles' => 'stock', 'FamilleArticle' => 'stock',
        'Pointage' => 'equipe', 'PointPointage' => 'equipe', 'AppareilPointage' => 'equipe', 'Employes' => 'equipe',
        'BulletinPaie' => 'equipe', 'CongeEmploye' => 'equipe', 'DemandeEmploye' => 'equipe', 'PrimeAgent' => 'equipe',
        'EvaluationPrime' => 'equipe', 'BaremePrime' => 'equipe',
        'MouvementTresorerie' => 'finances', 'CompteTresorerie' => 'finances', 'Depense' => 'finances', 'BudgetDepense' => 'finances',
        'Echeance' => 'finances', 'EcritureDiverse' => 'finances', 'Immobilisation' => 'finances', 'DotationAmortissement' => 'finances',
        'RapprochementBancaire' => 'finances', 'LigneRapprochementBancaire' => 'finances', 'LigneReleveOrangeMoney' => 'finances',
        'DecaissementRecurrent' => 'finances', 'SoldeOuverture' => 'finances', 'Factures' => 'finances', 'Avoir' => 'finances',
        'Paiements' => 'finances', 'RelanceCreance' => 'finances',
        'Devis' => 'ventes', 'Commandes' => 'ventes', 'ClotureCaisse' => 'ventes', 'ActionCaisse' => 'ventes',
        'BonLivraison' => 'ventes', 'Produits' => 'ventes', 'ProduitConfiguration' => 'ventes', 'PrixSpecial' => 'ventes',
        'Promotion' => 'ventes', 'CodePromo' => 'ventes',
    ];

    /** Module d'une route : son nom, son adresse et, en dernier recours, le nom de son contrôleur. */
    public static function moduleRoute(string $nom, string $chemin, ?string $controleur = null): string
    {
        if (str_starts_with($chemin, '/api/synchro')) {
            return 'synchronisation';
        }
        if (str_starts_with($chemin, '/espace')) {
            return 'espace_client';
        }
        if ('/' === $chemin || preg_match(self::CHEMINS_SITE_PUBLIC, $chemin) || str_contains($chemin, '/pdf-public/')) {
            return 'site_public';
        }
        if (preg_match(self::CHEMINS_SECURITE, $chemin)) {
            return 'securite';
        }

        $onglet = self::sectionDuMenu($nom);
        if (null !== $onglet) {
            return $onglet;
        }

        $meilleur = null;
        $longueur = 0;
        foreach (ModulesRoles::catalogue() as $cle => $definition) {
            foreach ($definition['prefixes'] ?? [] as $prefixe) {
                if (str_starts_with($nom, $prefixe) && \strlen($prefixe) > $longueur) {
                    $meilleur = self::MODULES_ROLES[$cle] ?? null;
                    $longueur = \strlen($prefixe);
                }
            }
        }
        if (null !== $meilleur) {
            return $meilleur;
        }

        foreach (self::PREFIXES_ROUTES as $prefixe => $module) {
            if (str_starts_with($nom, $prefixe)) {
                return $module;
            }
        }

        return null === $controleur ? self::COMMUN : (self::moduleParMot(self::nomCourt($controleur), self::MOTS_CONTROLEURS) ?? self::COMMUN);
    }

    /** Section du menu (accueil, ventes...) dont un onglet range la route : début de nom le plus long. */
    public static function sectionDuMenu(string $nom): ?string
    {
        $meilleur = null;
        $longueur = 0;
        foreach (NavigationExtension::ONGLETS as $section => $onglets) {
            foreach ($onglets as $onglet) {
                foreach ($onglet['prefixes'] as $prefixe) {
                    if (str_starts_with($nom, $prefixe) && \strlen($prefixe) > $longueur) {
                        $meilleur = $section;
                        $longueur = \strlen($prefixe);
                    }
                }
            }
        }

        return $meilleur;
    }

    /** Module d'un service d'après son sous-dossier de src/Service ; null pour un service à la racine. */
    public static function moduleService(string $classe): ?string
    {
        if (!preg_match('#^App\\\\Service\\\\([A-Za-z0-9]+)\\\\#', $classe, $m)) {
            return null;
        }

        return self::DOSSIERS_SERVICES[$m[1]] ?? null;
    }

    /** Module d'une entité rangée à la main (début de nom le plus long), sinon null. */
    public static function moduleEntite(string $classe): ?string
    {
        $court = self::nomCourt($classe);
        $meilleur = null;
        $longueur = 0;
        foreach (self::ENTITES as $debut => $module) {
            if (str_starts_with($court, $debut) && \strlen($debut) > $longueur) {
                $meilleur = $module;
                $longueur = \strlen($debut);
            }
        }

        return $meilleur;
    }

    /**
     * Module le plus fréquent d'une liste (égalité : celui du menu d'abord,
     * puis l'ordre de MODULES), null si la liste est vide.
     *
     * @param array<string, int> $compte module => nombre
     */
    public static function dominant(array $compte): ?string
    {
        unset($compte[self::COMMUN]);
        if ([] === $compte) {
            return null;
        }
        $ordre = array_flip(array_keys(self::MODULES));
        uksort($compte, static fn (string $a, string $b): int => [$compte[$b], $ordre[$a] ?? 99] <=> [$compte[$a], $ordre[$b] ?? 99]);

        return array_key_first($compte);
    }

    /** Module d'une classe d'après un mot de son nom court (le plus long d'abord). */
    public static function moduleParMot(string $nomCourt, ?array $mots = null): ?string
    {
        $mots ??= self::MOTS_CONTROLEURS;
        uksort($mots, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($mots as $mot => $module) {
            if (str_contains($nomCourt, $mot)) {
                return $module;
            }
        }

        return null;
    }

    public static function nomCourt(string $classe): string
    {
        $classe = explode('::', $classe)[0];
        $position = strrpos($classe, '\\');

        return false === $position ? $classe : substr($classe, $position + 1);
    }

    public static function libelle(string $module): string
    {
        return self::MODULES[$module]['libelle'] ?? $module;
    }
}
