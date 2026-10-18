<?php

namespace App\Twig;

use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Security\LivreurSeul;
use App\Security\LivreurVoter;
use App\Security\ModulesRoles;
use App\Service\PastillesMenu;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Range chaque ecran de l'admin dans l'un des 7 modules du menu
 * (Accueil, Ventes, Clients, Atelier, Finances, Equipe, Reglages),
 * d'apres le prefixe de sa route. Sert a construire le menu selon le
 * role (maquettes R01 a R05), a allumer l'entree active et a afficher
 * le fil d'Ariane de l'en-tete.
 */
final class NavigationExtension extends AbstractExtension
{
    public const SECTIONS = [
        'accueil' => 'Accueil',
        'ventes' => 'Ventes',
        'clients' => 'Clients',
        'atelier' => 'Atelier',
        'finances' => 'Finances',
        'equipe' => 'Équipe',
        'reglages' => 'Réglages',
    ];

    public const ICONES_SECTIONS = [
        'accueil' => 'fas fa-th-large',
        'ventes' => 'fas fa-tag',
        'clients' => 'fas fa-users',
        'atelier' => 'fas fa-print',
        'finances' => 'fas fa-wallet',
        'equipe' => 'fas fa-user-friends',
        'reglages' => 'fas fa-sliders-h',
    ];

    /**
     * Ecrans de chaque section, dans l'ordre des maquettes 20 a 41.
     * Tant qu'un ecran neuf n'est pas construit, son onglet ouvre la page
     * actuelle la plus proche ; les ecrans sans equivalent n'apparaissent
     * qu'une fois construits. Le premier onglet autorise est la porte
     * d'entree de la section dans le menu.
     *
     * @var array<string, list<array{libelle: string, route: string, parametres?: array<string, mixed>, role: string|list<string>|null, prefixes: list<string>}>>
     */
    public const ONGLETS = [
        'accueil' => [
            ['libelle' => 'Tableau de bord', 'route' => 'app_home', 'role' => null, 'prefixes' => ['app_home']],
            ['libelle' => 'Planning', 'route' => 'app_planning_index', 'role' => null, 'prefixes' => ['app_planning_']],
            ['libelle' => 'Entretien', 'route' => 'app_entretien_index', 'role' => ['ROLE_ENTRETIEN', 'ROLE_RH'], 'prefixes' => ['app_entretien_']],
            ['libelle' => 'Mes statistiques', 'route' => 'app_mes_statistiques', 'role' => 'ROLE_STATS_PERSO', 'prefixes' => ['app_mes_statistiques']],
            ['libelle' => 'Statistiques et rapports', 'route' => 'app_statistiques_index', 'role' => 'ROLE_STATS_GLOBAL', 'prefixes' => ['app_statistiques_']],
            ['libelle' => 'Rapport de la semaine', 'route' => 'app_rapport_semaine', 'role' => ['ROLE_STATS_GLOBAL', 'ROLE_STATS_PERSO'], 'prefixes' => ['app_rapport_semaine']],
            ['libelle' => 'Rapports par e-mail', 'route' => 'app_rapports_mail', 'role' => 'ROLE_STATS_GLOBAL', 'prefixes' => ['app_rapports_mail']],
        ],
        'ventes' => [
            ['libelle' => 'Devis et commandes', 'route' => 'app_ventes_index', 'role' => ['ROLE_COMMANDE', 'ROLE_DEVIS'], 'prefixes' => ['app_ventes_', 'app_commandes_', 'app_commande_catalogue_', 'app_brouillon_', 'app_devis_']],
            ['libelle' => 'À retirer', 'route' => 'app_livraisons_a_retirer', 'role' => 'ROLE_LIVRAISON', 'prefixes' => ['app_livraisons_a_retirer']],
            ['libelle' => 'Planning des livraisons', 'route' => 'app_livraisons_planning', 'role' => ['ROLE_ADMIN', 'ROLE_COMMANDE'], 'prefixes' => ['app_livraisons_planning', 'app_livraisons_bl']],
            ['libelle' => 'Ma tournée', 'route' => 'app_livraisons_tournee', 'role' => 'ROLE_LIVRAISON', 'prefixes' => ['app_livraisons_tournee', 'app_livraisons_remise']],
            ['libelle' => 'Livraisons', 'route' => 'app_livraisons_index', 'role' => 'ROLE_LIVRAISON', 'prefixes' => ['app_livraisons_', 'app_bons_livraison_', 'app_bon_livraison_']],
            // Le livreur : celles qu'il a remises ou manquées (LivreurVoter, pas la Direction).
            ['libelle' => 'Mes livraisons', 'route' => 'app_livraisons_mes_livraisons', 'role' => LivreurVoter::LIVREUR, 'prefixes' => ['app_livraisons_mes_livraisons']],
            // Direction, caisse, prépresse : livrée ou pas, quand, par qui.
            ['libelle' => 'Suivi des livraisons', 'route' => 'app_livraisons_suivi', 'role' => ['ROLE_COMMANDE', 'ROLE_PREPRESSE'], 'prefixes' => ['app_livraisons_suivi']],
            ['libelle' => 'Mes actions', 'route' => 'app_actions_index', 'role' => 'ROLE_PAIEMENT_ENCAISSER', 'prefixes' => ['app_actions_']],
            ['libelle' => 'Demandes du site', 'route' => 'app_demandes_site_index', 'role' => 'ROLE_DEVIS', 'prefixes' => ['app_demandes_site_']],
            // Atelier 3D (docs/atelier-3d.md) : modèles validés par les clients, vues et images d'origine.
            ['libelle' => 'Créations Studio textile', 'route' => 'app_creations_atelier_index', 'role' => ['ROLE_DEVIS', 'ROLE_COMMANDE'], 'prefixes' => ['app_creations_atelier_']],
            ['libelle' => 'Promotions', 'route' => 'app_promotions_index', 'role' => ['ROLE_PROMOTIONS', 'ROLE_COMMANDE', 'ROLE_DEVIS'], 'prefixes' => ['app_promotions_']],
        ],
        'clients' => [
            ['libelle' => 'Fiches clients', 'route' => 'app_clients_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_clients_', 'app_reclamation_']],
            ['libelle' => 'Prospects', 'route' => 'app_prospects_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_prospects_']],
            ['libelle' => 'Réclamations', 'route' => 'app_reclamations_clients_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_reclamations_clients_']],
            ['libelle' => 'Messages', 'route' => 'app_messages_clients_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_messages_clients_']],
            ['libelle' => 'Sondages et avis', 'route' => 'app_sondages_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_sondages_']],
            ['libelle' => 'Campagnes WhatsApp et SMS', 'route' => 'app_campagne_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_campagne_', 'app_modele_message_', 'app_regle_rappel_paiement_']],
            ['libelle' => 'Crédits SMS et e-mail', 'route' => 'app_credits_messages_index', 'role' => 'ROLE_CREDITS_MESSAGES', 'prefixes' => ['app_credits_messages_']],
            ['libelle' => 'Fidélité', 'route' => 'app_fidelite_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_fidelite_']],
            ['libelle' => 'Anniversaires', 'route' => 'app_anniversaires_index', 'role' => 'ROLE_PROMOTIONS', 'prefixes' => ['app_anniversaires_']],
            ['libelle' => 'Assistant du site', 'route' => 'app_assistant_conversations', 'role' => 'ROLE_ASSISTANT', 'prefixes' => ['app_assistant_']],
        ],
        'atelier' => [
            ['libelle' => 'Production', 'route' => 'app_atelier_production', 'role' => 'ROLE_PRODUCTION_VOIR', 'prefixes' => ['app_atelier_production', 'app_production_']],
            ['libelle' => 'Planning machines', 'route' => 'app_atelier_planning', 'role' => 'ROLE_PRODUCTION_VOIR', 'prefixes' => ['app_atelier_planning']],
            ['libelle' => 'Prépresse et BAT', 'route' => 'app_controle_pre_presse_index', 'role' => 'ROLE_PREPRESSE', 'prefixes' => ['app_controle_pre_presse_', 'app_produits_clients_prepresse_']],
            ['libelle' => 'Conception PAO', 'route' => 'app_conception_tableau', 'role' => 'ROLE_PREPRESSE', 'prefixes' => ['app_conception_']],
            ['libelle' => 'Demandes de conception', 'route' => 'app_demandes_conception_index', 'role' => ['ROLE_PREPRESSE', 'ROLE_COMMANDE'], 'prefixes' => ['app_demandes_conception_']],
            ['libelle' => 'Ma file machine', 'route' => 'app_ma_file_index', 'role' => 'ROLE_PRODUCTION_VOIR', 'prefixes' => ['app_ma_file_']],
            ['libelle' => 'Articles et stock', 'route' => 'app_stock_articles_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_stock_', 'app_article_', 'app_achat_', 'app_fournisseur_', 'app_consommables_', 'app_lot_etiquette_', 'app_suivi_etiquette']],
            ['libelle' => 'Machines et maintenance', 'route' => 'app_machines_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_machines_']],
            ['libelle' => 'Relevés de compteurs', 'route' => 'app_atelier_releves', 'role' => ['ROLE_PRODUCTION_GERER', 'ROLE_COMPTABILITE'], 'prefixes' => ['app_atelier_releves']],
        ],
        'finances' => [
            ['libelle' => 'Vue d\'ensemble', 'route' => 'app_finances_vue_ensemble', 'role' => ['ROLE_TRESORERIE_VOIR', 'ROLE_STATS_GLOBAL', 'ROLE_PAIEMENT_VOIR', 'ROLE_COUT_MARGE'], 'prefixes' => ['app_finances_vue_ensemble', 'app_finances_du_jour']],
            ['libelle' => 'Encaissements', 'route' => 'app_finances_encaissements', 'role' => ['ROLE_PAIEMENT_VOIR', 'ROLE_FACTURE'], 'prefixes' => ['app_finances_encaissements', 'app_paiements_', 'app_creances_', 'app_factures_']],
            ['libelle' => 'Caisse', 'route' => 'app_caisse_index', 'role' => 'ROLE_PAIEMENT_ENCAISSER', 'prefixes' => ['app_caisse_', 'app_journal_caisse_', 'app_clients_solde']],
            ['libelle' => 'À valider', 'route' => 'app_a_valider_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_a_valider_']],
            ['libelle' => 'Dépenses', 'route' => 'app_finances_depenses', 'role' => ['ROLE_TRESORERIE_VOIR', 'ROLE_TRESORERIE_SAISIR'], 'prefixes' => ['app_finances_depenses']],
            ['libelle' => 'Fournisseurs', 'route' => 'app_finances_fournisseurs', 'role' => 'ROLE_TRESORERIE_VOIR', 'prefixes' => ['app_finances_fournisseurs']],
            ['libelle' => 'Trésorerie', 'route' => 'app_finances_tresorerie', 'role' => 'ROLE_TRESORERIE_VOIR', 'prefixes' => ['app_finances_tresorerie', 'app_tresorerie_', 'app_mouvement_tresorerie_', 'app_decaissement_recurrent_', 'app_compte_tresorerie_', 'app_rapprochement_bancaire_']],
            ['libelle' => 'Rentabilité', 'route' => 'app_finances_rentabilite', 'role' => 'ROLE_COUT_MARGE', 'prefixes' => ['app_finances_rentabilite']],
            ['libelle' => 'Comptabilité', 'route' => 'app_comptabilite_index', 'role' => 'ROLE_COMPTABILITE', 'prefixes' => ['app_comptabilite_']],
            ['libelle' => 'Immobilisations', 'route' => 'app_immobilisations_patrimoine', 'role' => 'ROLE_COMPTABILITE', 'prefixes' => ['app_immobilisations_']],
        ],
        'equipe' => [
            ['libelle' => 'Employés', 'route' => 'app_equipe_employes_index', 'role' => 'ROLE_RH', 'prefixes' => ['app_equipe_employes_', 'app_acces_', 'app_employes_']],
            ['libelle' => 'Pointage', 'route' => 'app_pointage_jour', 'role' => 'ROLE_RH', 'prefixes' => ['app_pointage_']],
            ['libelle' => 'Équipes en direct', 'route' => 'app_atelier_equipes', 'role' => 'ROLE_RH', 'prefixes' => ['app_atelier_equipes']],
            ['libelle' => 'Demandes du personnel', 'route' => 'app_demandes_index', 'role' => 'ROLE_RH', 'prefixes' => ['app_demandes_']],
            ['libelle' => 'Paie', 'route' => 'app_paie_index', 'role' => 'ROLE_RH', 'prefixes' => ['app_paie_']],
            ['libelle' => 'Actions commerciales', 'route' => 'app_actions_equipe_journal', 'role' => 'ROLE_ACTIONS_GERER', 'prefixes' => ['app_actions_equipe_']],
            ['libelle' => 'Performance et primes', 'route' => 'app_primes_index', 'role' => 'PRIMES_EQUIPE', 'prefixes' => ['app_primes_']],
        ],
        'reglages' => [
            ['libelle' => 'Paramètres', 'route' => 'app_reglages_parametres_index', 'role' => ['ROLE_ADMIN', 'ROLE_PARAMETRES', 'ROLE_RH', 'ROLE_COUT_MARGE', 'ROLE_PRODUCTION_GERER', 'ROLE_PREPRESSE'], 'prefixes' => ['app_reglages_parametres_', 'app_bareme_']],
            ['libelle' => 'Grille tarifaire', 'route' => 'app_grille_tarifaire_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_grille_tarifaire_', 'app_produits_', 'app_categorie_produit_', 'app_produit_configuration_', 'app_type_impression_', 'app_support_', 'app_format_', 'app_finition_', 'app_parametres_paiement_', 'app_fichier_dossier_partage']],
            ['libelle' => 'Coûts de revient', 'route' => 'app_couts_revient_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_couts_revient_']],
            ['libelle' => 'Utilisateurs et droits', 'route' => 'app_utilisateurs_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_utilisateurs_', 'app_user_', 'app_journal_activite_']],
            ['libelle' => 'Entreprises et documents', 'route' => 'app_entreprises_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_entreprises_']],
            ['libelle' => 'Sécurité', 'route' => 'app_appareils_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_appareils_', 'app_jetons_api_', 'app_sauvegardes_', 'app_synchronisation_']],
            ['libelle' => 'Écran TV', 'route' => 'app_ecran_tv_reglages', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_ecran_tv_']],
            ['libelle' => 'Site vitrine', 'route' => 'app_site_contenu_index', 'role' => 'ROLE_SITE_VITRINE', 'prefixes' => ['app_site_contenu_']],
            // Corbeille (docs/corbeille.md) : la Direction et le droit « Corbeille », sur l'ERP local (CorbeilleVoter).
            ['libelle' => 'Corbeille', 'route' => 'app_corbeille_index', 'role' => 'CORBEILLE', 'prefixes' => ['app_corbeille_']],
        ],
    ];

    /**
     * Menu court (maquettes R02, R03, R05) : les ecrans du quotidien, a
     * plat, dans cet ordre. Chacun n'apparait que si la personne a le
     * droit de l'ouvrir (celui de son onglet dans ONGLETS).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MENU_COURT = [
        'app_home' => ['Ma journée', 'fas fa-th-large'],
        'app_livraisons_tournee' => ['Ma tournée', 'fas fa-route'],
        'app_livraisons_mes_livraisons' => ['Mes livraisons', 'fas fa-clipboard-check'],
        'app_livraisons_a_retirer' => ['À livrer ou retirer', 'fas fa-box'],
        'app_caisse_index' => ['Caisse', 'fas fa-cash-register'],
        'app_actions_index' => ['Mes actions', 'far fa-bell'],
        'app_clients_index' => ['Clients', 'fas fa-user-friends'],
        'app_ventes_index' => ['Devis et commandes', 'far fa-file-alt'],
        'app_finances_encaissements' => ['Encaissements', 'far fa-credit-card'],
        'app_livraisons_suivi' => ['Suivi des livraisons', 'fas fa-truck'],
        'app_controle_pre_presse_index' => ['Fichiers et BAT', 'far fa-file'],
        'app_conception_tableau' => ['Mes travaux PAO', 'fas fa-pencil-ruler'],
        'app_atelier_production' => ['Production', 'fas fa-sliders-h'],
        'app_ma_file_index' => ['Ma file machine', 'fas fa-print'],
        'app_stock_articles_index' => ['Stock', 'fas fa-cube'],
        'app_entretien_index' => ['Entretien', 'fas fa-broom'],
    ];

    /**
     * Barre du bas sur telephone (maquette R05). Direction : les sections
     * principales. Les autres roles : leurs premiers ecrans du menu court,
     * sous un nom court.
     */
    public const BARRE_DIRECTION = [
        'accueil' => ['Résumé', 'fas fa-home'],
        'ventes' => ['Ventes', 'fas fa-handshake'],
        'finances' => ['Caisse', 'fas fa-wallet'],
        'clients' => ['Clients', 'fas fa-users'],
    ];

    public const LIBELLES_BARRE = [
        'app_home' => 'Accueil',
        'app_livraisons_tournee' => 'Tournée',
        'app_livraisons_mes_livraisons' => 'Livraisons',
        'app_livraisons_a_retirer' => 'Livrer',
        'app_caisse_index' => 'Caisse',
        'app_actions_index' => 'Actions',
        'app_clients_index' => 'Clients',
        'app_ventes_index' => 'Ventes',
        'app_finances_encaissements' => 'Encaisser',
        'app_livraisons_suivi' => 'Suivi',
        'app_controle_pre_presse_index' => 'Fichiers',
        'app_conception_tableau' => 'Travaux',
        'app_atelier_production' => 'Production',
        'app_ma_file_index' => 'Ma file',
        'app_stock_articles_index' => 'Stock',
        'app_entretien_index' => 'Entretien',
    ];

    /** Modules ouverts d'un clic, sans volet : leur page d'accueil mene a tout. */
    private const SANS_VOLET = ['reglages'];

    /**
     * Le premier prefixe qui correspond gagne : les plus precis d'abord.
     */
    private const PREFIXES = [
        'app_clients_soldes' => 'finances',
        'app_clients_solde' => 'finances',
        'app_home' => 'accueil',
        'app_statistiques_' => 'accueil',
        'app_planning_' => 'accueil',
        'app_entretien_' => 'accueil',
        'app_ventes_' => 'ventes',
        'app_devis_' => 'ventes',
        'app_commandes_' => 'ventes',
        'app_commande_catalogue_' => 'ventes',
        'app_brouillon_' => 'ventes',
        'app_livraisons_' => 'ventes',
        'app_bons_livraison_' => 'ventes',
        'app_bon_livraison_' => 'ventes',
        'app_atelier_equipes' => 'equipe',
        'app_production_' => 'atelier',
        'app_atelier_' => 'atelier',
        'app_controle_pre_presse_' => 'atelier',
        'app_consommables_' => 'atelier',
        'app_stock_' => 'atelier',
        'app_article_' => 'atelier',
        'app_achat_' => 'atelier',
        'app_fournisseur_' => 'atelier',
        'app_machines_' => 'atelier',
        'app_lot_etiquette_' => 'atelier',
        'app_suivi_etiquette' => 'atelier',
        'app_fichier_upload_' => 'atelier',
        'app_finances_' => 'finances',
        'app_caisse_' => 'finances',
        'app_a_valider_' => 'finances',
        'app_creances_' => 'finances',
        'app_tresorerie_' => 'finances',
        'app_comptabilite_' => 'finances',
        'app_immobilisations_' => 'finances',
        'app_paiements_' => 'finances',
        'app_factures_' => 'finances',
        'app_journal_caisse_' => 'finances',
        'app_mouvement_tresorerie_' => 'finances',
        'app_decaissement_recurrent_' => 'finances',
        'app_compte_tresorerie_' => 'finances',
        'app_rapprochement_bancaire_' => 'finances',
        'app_clients_' => 'clients',
        'app_prospects_' => 'clients',
        'app_reclamation_' => 'clients',
        'app_messages_clients_' => 'clients',
        'app_sondages_' => 'clients',
        'app_campagne_' => 'clients',
        'app_credits_messages_' => 'clients',
        'app_modele_message_' => 'clients',
        'app_regle_rappel_paiement_' => 'clients',
        'app_fidelite_' => 'clients',
        'app_anniversaires_' => 'clients',
        'app_assistant_' => 'clients',
        'app_promotions_' => 'ventes',
        'app_categorie_produit_' => 'reglages',
        'app_produits_' => 'reglages',
        'app_grille_tarifaire_' => 'reglages',
        'app_couts_revient_' => 'reglages',
        'app_produit_configuration_' => 'reglages',
        'app_type_impression_' => 'reglages',
        'app_support_' => 'reglages',
        'app_format_' => 'reglages',
        'app_finition_' => 'reglages',
        'app_utilisateurs_' => 'reglages',
        'app_mes_statistiques' => 'accueil',
        'app_rapport_semaine' => 'accueil',
        'app_rapports_mail' => 'accueil',
        'app_employes_' => 'equipe',
        'app_equipe_employes_' => 'equipe',
        'app_acces_' => 'equipe',
        'app_user_' => 'reglages',
        'app_journal_activite_' => 'reglages',
        'app_parametres_paiement_' => 'reglages',
        'app_fichier_dossier_partage' => 'reglages',
        'app_ecran_tv_' => 'reglages',
        'app_site_contenu_' => 'reglages',
        'app_appareils_' => 'reglages',
        'app_sauvegardes_' => 'reglages',
        'app_corbeille_' => 'reglages',
        'app_synchronisation_' => 'reglages',
        'app_entreprises_' => 'reglages',
        'app_reglages_parametres_' => 'reglages',
        'app_pointage_' => 'equipe',
        'app_bareme_' => 'reglages',
        'app_conception_' => 'atelier',
        'app_ma_file_' => 'atelier',
        'app_demandes_site_' => 'ventes',
        'app_creations_atelier_' => 'ventes',
        'app_demandes_conception_' => 'atelier',
        'app_demandes_' => 'equipe',
        'app_paie_' => 'equipe',
        'app_actions_equipe_' => 'equipe',
        'app_primes_' => 'equipe',
        'app_actions_' => 'ventes',
    ];

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $autorisations = null,
        private readonly ?Security $security = null,
        private readonly ?PastillesMenu $pastilles = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('section_admin', $this->sectionPourRoute(...)),
            new TwigFunction('libelle_section_admin', $this->libelleSection(...)),
            new TwigFunction('onglets_section_admin', $this->ongletsSection(...)),
            new TwigFunction('onglet_actif_admin', $this->ongletActif(...)),
            new TwigFunction('onglets_autorises_admin', $this->ongletsAutorises(...)),
            new TwigFunction('menu_admin', $this->menu(...)),
            new TwigFunction('style_menu_admin', $this->styleMenu(...)),
            new TwigFunction('barre_mobile_admin', $this->barreMobile(...)),
            new TwigFunction('module_en_lecture', $this->moduleEnLecture(...)),
        ];
    }

    public function sectionPourRoute(?string $route): ?string
    {
        if (null === $route || '' === $route) {
            return null;
        }

        foreach (self::PREFIXES as $prefixe => $section) {
            if (str_starts_with($route, $prefixe)) {
                return $section;
            }
        }

        return null;
    }

    public function libelleSection(?string $section): ?string
    {
        return self::SECTIONS[$section] ?? null;
    }

    /**
     * @return list<array{libelle: string, route: string, parametres?: array<string, mixed>, role: ?string, prefixes: list<string>}>
     */
    public function ongletsSection(?string $section): array
    {
        return self::ONGLETS[$section] ?? [];
    }

    /**
     * Onglets de la section que l'utilisateur peut ouvrir (un seul des
     * roles listes suffit), avec leur rang d'origine dans 'rang'. Avec
     * $droits, pour ces droits-la plutot que ceux de la personne connectee
     * (apercu du menu d'un role, maquette R04). Le livreur seul n'a que
     * sa tournee et ses livraisons (LivreurSeul::MENU).
     *
     * @param list<string>|null $droits
     *
     * @return list<array<string, mixed>>
     */
    public function ongletsAutorises(?string $section, ?array $droits = null): array
    {
        $autorises = [];
        $livreurSeul = null !== $droits ? LivreurSeul::est($droits) : $this->livreurSeulConnecte();

        foreach ($this->ongletsSection($section) as $rang => $onglet) {
            $roles = (array) $onglet['role'];
            $autorise = [] === $roles;

            foreach ($roles as $role) {
                $autorise = $autorise || (null !== $droits
                    ? (LivreurVoter::LIVREUR === $role ? LivreurVoter::livre($droits) : \in_array($role, $droits, true))
                    : null !== $this->autorisations && $this->autorisations->isGranted($role));
            }

            if ($autorise && $livreurSeul && !\in_array($onglet['route'], LivreurSeul::MENU, true)) {
                $autorise = false;
            }

            if ($autorise) {
                $autorises[] = $onglet + ['rang' => $rang];
            }
        }

        return $autorises;
    }

    /**
     * Rang de l'onglet de la section qui couvre cette route, ou null. Le
     * prefixe le plus precis gagne (« Suivi des livraisons » plutot que
     * « Livraisons ») ; a egalite, le premier onglet.
     */
    public function ongletActif(?string $route): ?int
    {
        $section = $this->sectionPourRoute($route);

        if (null === $section) {
            return null;
        }

        $actif = null;
        $longueur = -1;
        foreach ($this->ongletsSection($section) as $rang => $onglet) {
            foreach ($onglet['prefixes'] as $prefixe) {
                if (\strlen($prefixe) > $longueur && str_starts_with((string) $route, $prefixe)) {
                    $actif = $rang;
                    $longueur = \strlen($prefixe);
                }
            }
        }

        return $actif;
    }

    private function livreurSeulConnecte(): bool
    {
        $utilisateur = $this->security?->getUser();

        return $utilisateur instanceof User && LivreurSeul::est($utilisateur->getRoles());
    }

    /**
     * Forme du menu de la personne connectee : celle de son role (Reglages
     * > Roles et menus), tous les modules en volets pour l'administrateur.
     */
    public function styleMenu(): string
    {
        $utilisateur = $this->security?->getUser();
        if (!$utilisateur instanceof User || \in_array(User::ROLE_ADMIN, $utilisateur->getRoles(), true)) {
            return RoleUtilisateur::MENU_VOLETS;
        }

        return $utilisateur->getProfil()?->getMenu() ?? RoleUtilisateur::MENU_COURT;
    }

    /**
     * Menu selon le role (maquettes R01 a R03) : les modules en volets,
     * avec leurs ecrans autorises, ou le menu court a plat. Avec $droits,
     * le menu qu'aurait un role qui a ces droits (apercu, sans pastilles).
     *
     * @param list<string>|null $droits
     *
     * @return array{style: string, entrees: list<array<string, mixed>>}
     */
    public function menu(?string $route, ?string $style = null, ?array $droits = null): array
    {
        $style ??= $this->styleMenu();
        $pastilles = null === $droits ? ($this->pastilles?->parRoute() ?? []) : [];
        $sectionActive = $this->sectionPourRoute($route);
        $rangActif = $this->ongletActif($route);

        $ecrans = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            foreach ($this->ongletsAutorises($section, $droits) as $onglet) {
                $ecrans[$onglet['route']] ??= [
                    'libelle' => $onglet['libelle'],
                    'route' => $onglet['route'],
                    'parametres' => $onglet['parametres'] ?? [],
                    'section' => $section,
                    'actif' => $sectionActive === $section && $rangActif === $onglet['rang'],
                    'pastille' => $pastilles[$onglet['route']] ?? 0,
                ];
            }
        }

        if (RoleUtilisateur::MENU_VOLETS !== $style) {
            $entrees = [];
            foreach (self::MENU_COURT as $routeEcran => [$libelle, $icone]) {
                if (isset($ecrans[$routeEcran])) {
                    $entrees[] = ['libelle' => $libelle, 'icone' => $icone] + $ecrans[$routeEcran];
                }
            }

            return ['style' => $style, 'entrees' => $entrees];
        }

        $modules = [];
        foreach (self::SECTIONS as $section => $libelle) {
            $items = array_values(array_filter($ecrans, static fn (array $e): bool => $e['section'] === $section));
            if ([] === $items) {
                continue;
            }
            $modules[] = [
                'cle' => $section,
                'libelle' => $libelle,
                'icone' => self::ICONES_SECTIONS[$section],
                'route' => $items[0]['route'],
                'parametres' => $items[0]['parametres'],
                'actif' => $sectionActive === $section,
                'pastille' => array_sum(array_column($items, 'pastille')),
                'items' => \in_array($section, self::SANS_VOLET, true) || 1 === \count($items) ? [] : $items,
            ];
        }

        return ['style' => $style, 'entrees' => $modules];
    }

    /**
     * Barre du bas sur telephone (maquette R05) : quatre ecrans au plus, puis
     * « Plus » qui ouvre le menu complet. Celui qui fait une tournee la voit
     * a la place de l'accueil ; un employe a le pointage s'il reste une place,
     * et « Moi » (son espace) quand la barre montre deja tout son menu.
     *
     * @return array{entrees: list<array<string, mixed>>, fin: array{libelle: string, icone: string, route: ?string, actif: bool}}
     */
    public function barreMobile(?string $route): array
    {
        $plus = ['libelle' => 'Plus', 'icone' => 'fas fa-bars', 'route' => null, 'actif' => false];
        $menu = $this->menu($route);

        if (RoleUtilisateur::MENU_VOLETS === $menu['style']) {
            $section = $this->sectionPourRoute($route);
            $entrees = [];
            foreach (self::BARRE_DIRECTION as $cle => [$libelle, $icone]) {
                $premier = $this->ongletsAutorises($cle)[0] ?? null;
                if (null !== $premier) {
                    $entrees[] = [
                        'libelle' => $libelle,
                        'icone' => $icone,
                        'route' => $premier['route'],
                        'parametres' => $premier['parametres'] ?? [],
                        'actif' => $section === $cle,
                        'pastille' => 0,
                    ];
                }
            }

            return ['entrees' => $entrees, 'fin' => $plus];
        }

        $ecrans = $menu['entrees'];
        $routes = array_column($ecrans, 'route');
        if (\in_array('app_livraisons_tournee', $routes, true)) {
            $ecrans = array_values(array_filter($ecrans, static fn (array $e): bool => 'app_home' !== $e['route']));
        }

        $entrees = [];
        foreach (\array_slice($ecrans, 0, 4) as $ecran) {
            $entrees[] = ['libelle' => self::LIBELLES_BARRE[$ecran['route']] ?? $ecran['libelle']] + $ecran;
        }

        $utilisateur = $this->security?->getUser();
        $employe = $utilisateur instanceof User ? $utilisateur->getEmploye() : null;
        if (null !== $employe && \count($entrees) < 4) {
            $entrees[] = [
                'libelle' => 'Pointage',
                'icone' => 'fas fa-qrcode',
                'route' => 'app_pointer_index',
                'parametres' => [],
                'actif' => str_starts_with((string) $route, 'app_pointer_'),
                'pastille' => 0,
            ];
        }

        $restants = array_diff($routes, array_column($entrees, 'route'), ['app_home']);
        if (null !== $employe && [] === $restants) {
            return ['entrees' => $entrees, 'fin' => [
                'libelle' => 'Moi',
                'icone' => 'far fa-user',
                'route' => 'app_mon_espace_index',
                'actif' => str_starts_with((string) $route, 'app_mon_espace_'),
            ]];
        }

        return ['entrees' => $entrees, 'fin' => $plus];
    }

    /**
     * Nom du module en lecture seule pour la personne connectee sur cette
     * page (bandeau « Lecture seule »), ou null.
     */
    public function moduleEnLecture(?string $route): ?string
    {
        $utilisateur = $this->security?->getUser();
        if (null === $route || !$utilisateur instanceof User || \in_array(User::ROLE_ADMIN, $utilisateur->getRoles(), true)) {
            return null;
        }
        $module = ModulesRoles::moduleEnLecture($route, $utilisateur->getRoles());

        return null === $module ? null : ModulesRoles::catalogue()[$module]['libelle'];
    }
}
