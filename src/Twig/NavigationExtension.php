<?php

namespace App\Twig;

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
     * Le premier prefixe qui correspond gagne : les plus precis d'abord.
     */
    private const PREFIXES = [
        'app_clients_soldes' => 'finances',
        'app_clients_solde' => 'finances',
        'app_home' => 'accueil',
        'app_statistiques_' => 'accueil',
        'app_devis_' => 'ventes',
        'app_commandes_' => 'ventes',
        'app_commande_catalogue_' => 'ventes',
        'app_brouillon_' => 'ventes',
        'app_livraisons_' => 'ventes',
        'app_bons_livraison_' => 'ventes',
        'app_bon_livraison_' => 'ventes',
        'app_production_' => 'atelier',
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
        'app_paiements_' => 'finances',
        'app_factures_' => 'finances',
        'app_journal_caisse_' => 'finances',
        'app_mouvement_tresorerie_' => 'finances',
        'app_decaissement_recurrent_' => 'finances',
        'app_compte_tresorerie_' => 'finances',
        'app_rapprochement_bancaire_' => 'finances',
        'app_clients_' => 'clients',
        'app_reclamation_' => 'clients',
        'app_campagne_' => 'clients',
        'app_modele_message_' => 'clients',
        'app_regle_rappel_paiement_' => 'clients',
        'app_categorie_produit_' => 'reglages',
        'app_produits_' => 'reglages',
        'app_produit_configuration_' => 'reglages',
        'app_type_impression_' => 'reglages',
        'app_support_' => 'reglages',
        'app_format_' => 'reglages',
        'app_finition_' => 'reglages',
        'app_employes_' => 'reglages',
        'app_user_' => 'reglages',
        'app_journal_activite_' => 'reglages',
        'app_parametres_paiement_' => 'reglages',
        'app_fichier_dossier_partage' => 'reglages',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('section_admin', $this->sectionPourRoute(...)),
            new TwigFunction('libelle_section_admin', $this->libelleSection(...)),
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
}
