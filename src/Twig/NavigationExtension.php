<?php

namespace App\Twig;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Range chaque ecran de l'admin dans l'une des 6 entrees du menu
 * (Accueil, Ventes, Atelier, Finances, Clients, Reglages), d'apres le
 * prefixe de sa route. Sert a allumer l'entree active du menu et a
 * afficher le fil d'Ariane de l'en-tete.
 */
final class NavigationExtension extends AbstractExtension
{
    public const SECTIONS = [
        'accueil' => 'Accueil',
        'ventes' => 'Ventes',
        'atelier' => 'Atelier',
        'finances' => 'Finances',
        'clients' => 'Clients',
        'reglages' => 'Réglages',
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
            ['libelle' => 'Mes statistiques', 'route' => 'app_mes_statistiques', 'role' => 'ROLE_STATS_PERSO', 'prefixes' => ['app_mes_statistiques']],
            ['libelle' => 'Statistiques et rapports', 'route' => 'app_statistiques_index', 'role' => 'ROLE_STATS_GLOBAL', 'prefixes' => ['app_statistiques_']],
        ],
        'ventes' => [
            ['libelle' => 'Devis et commandes', 'route' => 'app_ventes_index', 'role' => ['ROLE_COMMANDE', 'ROLE_DEVIS'], 'prefixes' => ['app_ventes_', 'app_commandes_', 'app_commande_catalogue_', 'app_brouillon_', 'app_devis_']],
            ['libelle' => 'À retirer', 'route' => 'app_livraisons_a_retirer', 'role' => 'ROLE_LIVRAISON', 'prefixes' => ['app_livraisons_a_retirer']],
            ['libelle' => 'Livraisons', 'route' => 'app_livraisons_index', 'role' => 'ROLE_LIVRAISON', 'prefixes' => ['app_livraisons_', 'app_bons_livraison_', 'app_bon_livraison_']],
            ['libelle' => 'Mes actions', 'route' => 'app_actions_index', 'role' => 'ROLE_PAIEMENT_ENCAISSER', 'prefixes' => ['app_actions_']],
        ],
        'atelier' => [
            ['libelle' => 'Production', 'route' => 'app_atelier_production', 'role' => 'ROLE_PRODUCTION_VOIR', 'prefixes' => ['app_atelier_production', 'app_production_']],
            ['libelle' => 'Planning machines', 'route' => 'app_atelier_planning', 'role' => 'ROLE_PRODUCTION_VOIR', 'prefixes' => ['app_atelier_planning']],
            ['libelle' => 'Prépresse et BAT', 'route' => 'app_controle_pre_presse_index', 'role' => 'ROLE_PREPRESSE', 'prefixes' => ['app_controle_pre_presse_']],
            ['libelle' => 'Articles et stock', 'route' => 'app_stock_articles_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_stock_', 'app_article_', 'app_achat_', 'app_fournisseur_', 'app_consommables_', 'app_lot_etiquette_', 'app_suivi_etiquette']],
            ['libelle' => 'Machines et maintenance', 'route' => 'app_machines_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_machines_']],
        ],
        'finances' => [
            ['libelle' => 'Caisse', 'route' => 'app_caisse_index', 'role' => 'ROLE_PAIEMENT_ENCAISSER', 'prefixes' => ['app_caisse_', 'app_journal_caisse_', 'app_clients_solde']],
            ['libelle' => 'À valider', 'route' => 'app_a_valider_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_a_valider_']],
            ['libelle' => 'Paiements', 'route' => 'app_paiements_index', 'role' => 'ROLE_PAIEMENT_VOIR', 'prefixes' => ['app_paiements_']],
            ['libelle' => 'Factures et créances', 'route' => 'app_creances_index', 'role' => 'ROLE_FACTURE', 'prefixes' => ['app_creances_', 'app_factures_']],
            ['libelle' => 'Trésorerie et dépenses', 'route' => 'app_tresorerie_index', 'role' => 'ROLE_TRESORERIE_VOIR', 'prefixes' => ['app_tresorerie_', 'app_mouvement_tresorerie_', 'app_decaissement_recurrent_', 'app_compte_tresorerie_', 'app_rapprochement_bancaire_']],
            ['libelle' => 'Comptabilité', 'route' => 'app_comptabilite_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_comptabilite_']],
        ],
        'clients' => [
            ['libelle' => 'Fiches clients', 'route' => 'app_clients_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_clients_', 'app_reclamation_']],
            ['libelle' => 'Prospects', 'route' => 'app_prospects_index', 'role' => 'ROLE_CLIENT', 'prefixes' => ['app_prospects_']],
            ['libelle' => 'Campagnes WhatsApp et SMS', 'route' => 'app_campagne_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_campagne_', 'app_modele_message_', 'app_regle_rappel_paiement_']],
        ],
        'reglages' => [
            ['libelle' => 'Paramètres', 'route' => 'app_reglages_parametres_index', 'role' => ['ROLE_ADMIN', 'ROLE_PARAMETRES', 'ROLE_RH', 'ROLE_COUT_MARGE', 'ROLE_PRODUCTION_GERER', 'ROLE_PREPRESSE'], 'prefixes' => ['app_reglages_parametres_']],
            ['libelle' => 'Grille tarifaire', 'route' => 'app_grille_tarifaire_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_grille_tarifaire_', 'app_produits_', 'app_categorie_produit_', 'app_produit_configuration_', 'app_type_impression_', 'app_support_', 'app_format_', 'app_finition_', 'app_parametres_paiement_', 'app_fichier_dossier_partage']],
            ['libelle' => 'Coûts de revient', 'route' => 'app_couts_revient_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_couts_revient_']],
            ['libelle' => 'Utilisateurs et droits', 'route' => 'app_utilisateurs_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_utilisateurs_', 'app_user_', 'app_employes_', 'app_journal_activite_']],
            ['libelle' => 'Actions commerciales', 'route' => 'app_actions_equipe_journal', 'role' => 'ROLE_ACTIONS_GERER', 'prefixes' => ['app_actions_equipe_']],
            ['libelle' => 'Demandes du personnel', 'route' => 'app_demandes_index', 'role' => 'ROLE_RH', 'prefixes' => ['app_demandes_']],
            ['libelle' => 'Pointage', 'route' => 'app_pointage_jour', 'role' => 'ROLE_RH', 'prefixes' => ['app_pointage_']],
            ['libelle' => 'Entreprises et documents', 'route' => 'app_entreprises_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_entreprises_']],
            ['libelle' => 'Sécurité', 'route' => 'app_appareils_index', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_appareils_']],
            ['libelle' => 'Écran TV', 'route' => 'app_ecran_tv_reglages', 'role' => 'ROLE_ADMIN', 'prefixes' => ['app_ecran_tv_']],
        ],
    ];

    /**
     * Le premier prefixe qui correspond gagne : les plus precis d'abord.
     */
    private const PREFIXES = [
        'app_clients_soldes' => 'finances',
        'app_clients_solde' => 'finances',
        'app_home' => 'accueil',
        'app_statistiques_' => 'accueil',
        'app_ventes_' => 'ventes',
        'app_devis_' => 'ventes',
        'app_commandes_' => 'ventes',
        'app_commande_catalogue_' => 'ventes',
        'app_brouillon_' => 'ventes',
        'app_livraisons_' => 'ventes',
        'app_bons_livraison_' => 'ventes',
        'app_bon_livraison_' => 'ventes',
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
        'app_caisse_' => 'finances',
        'app_a_valider_' => 'finances',
        'app_creances_' => 'finances',
        'app_tresorerie_' => 'finances',
        'app_comptabilite_' => 'finances',
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
        'app_campagne_' => 'clients',
        'app_modele_message_' => 'clients',
        'app_regle_rappel_paiement_' => 'clients',
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
        'app_employes_' => 'reglages',
        'app_user_' => 'reglages',
        'app_journal_activite_' => 'reglages',
        'app_parametres_paiement_' => 'reglages',
        'app_fichier_dossier_partage' => 'reglages',
        'app_ecran_tv_' => 'reglages',
        'app_appareils_' => 'reglages',
        'app_entreprises_' => 'reglages',
        'app_reglages_parametres_' => 'reglages',
        'app_pointage_' => 'reglages',
        'app_demandes_' => 'reglages',
        'app_actions_equipe_' => 'reglages',
        'app_actions_' => 'ventes',
    ];

    public function __construct(
        private readonly ?AuthorizationCheckerInterface $autorisations = null,
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
     * roles listes suffit), avec leur rang d'origine dans 'rang'.
     *
     * @return list<array<string, mixed>>
     */
    public function ongletsAutorises(?string $section): array
    {
        $autorises = [];

        foreach ($this->ongletsSection($section) as $rang => $onglet) {
            $roles = (array) $onglet['role'];
            $autorise = [] === $roles;

            foreach ($roles as $role) {
                $autorise = $autorise || (null !== $this->autorisations && $this->autorisations->isGranted($role));
            }

            if ($autorise) {
                $autorises[] = $onglet + ['rang' => $rang];
            }
        }

        return $autorises;
    }

    /**
     * Rang de l'onglet de la section qui couvre cette route, ou null.
     */
    public function ongletActif(?string $route): ?int
    {
        $section = $this->sectionPourRoute($route);

        if (null === $section) {
            return null;
        }

        foreach ($this->ongletsSection($section) as $rang => $onglet) {
            foreach ($onglet['prefixes'] as $prefixe) {
                if (str_starts_with((string) $route, $prefixe)) {
                    return $rang;
                }
            }
        }

        return null;
    }
}
