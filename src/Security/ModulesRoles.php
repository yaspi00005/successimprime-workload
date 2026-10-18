<?php

namespace App\Security;

/**
 * Matrice Reglages › Roles et menus (maquette R04) : pour chaque module,
 * un role peut « Modifier », seulement « Voir », ou rien (« — », le module
 * disparait de son menu).
 *
 * Un niveau n'est qu'une facon de cocher plusieurs droits fins d'un coup
 * (Droits::catalogue) : « Modifier » donne tous les droits du module ;
 * « Voir » donne le droit de lecture du module quand il existe (production,
 * tresorerie), sinon les droits du module avec une restriction « lecture
 * seule » que LectureSeuleSubscriber fait respecter. Les montants (marges,
 * paiements, chiffre d'affaires) restent des droits a part : on peut les
 * masquer meme quand le module est visible.
 */
final class ModulesRoles
{
    public const MODIFIER = 'modifier';
    public const VOIR = 'voir';
    public const AUCUN = 'aucun';

    public const NIVEAUX = [
        self::MODIFIER => 'Modifier',
        self::VOIR => 'Voir',
        self::AUCUN => '—',
    ];

    /** Module que seule la Direction ouvre (pas de case a changer). */
    public const FIXE_DIRECTION = 'direction';

    /** Module ouvert a tout le personnel (pas de case a changer). */
    public const FIXE_TOUS = 'tous';

    /**
     * @return array<string, array{libelle: string, detail: string, droits: list<string>, voir?: list<string>, lecture?: string, prefixes?: list<string>, fixe?: string}>
     */
    public static function catalogue(): array
    {
        return [
            'ventes' => [
                'libelle' => 'Ventes et caisse',
                'detail' => 'devis, commandes, factures, encaissements',
                'droits' => ['ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_JOURNAL_CAISSE'],
                'lecture' => Droits::LECTURE_VENTES,
                // Aussi les encaissements par relevé ou à la livraison, « Mes actions » de la caisse (pas celles de l'équipe) et les points fidélité posés sur une commande.
                'prefixes' => ['app_ventes_', 'app_commandes_', 'app_commande_catalogue_', 'app_brouillon_', 'app_devis_', 'app_factures_', 'app_creances_', 'app_caisse_', 'app_journal_caisse_', 'app_paiements_', 'app_finances_encaissements', 'app_livraisons_bl_encaissement', 'app_actions_index', 'app_actions_voir', 'app_actions_contact', 'app_actions_faire', 'app_fidelite_utiliser', 'app_fidelite_retirer', 'app_fidelite_reveil_appliquer'],
            ],
            'clients' => [
                'libelle' => 'Clients',
                'detail' => 'fiches, prospects, réclamations, sondages',
                'droits' => ['ROLE_CLIENT'],
                'lecture' => Droits::LECTURE_CLIENTS,
                'prefixes' => ['app_clients_', 'app_prospects_', 'app_reclamations_clients_', 'app_sondages_', 'app_messages_clients_'],
            ],
            'livraisons' => [
                'libelle' => 'Livraisons',
                'detail' => 'tournée, remise au client, retraits',
                'droits' => ['ROLE_LIVRAISON'],
            ],
            'atelier' => [
                'libelle' => 'Atelier et production',
                'detail' => 'planning, statuts des travaux',
                'droits' => ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER'],
                'voir' => ['ROLE_PRODUCTION_VOIR'],
            ],
            'pao' => [
                'libelle' => 'Montage PAO',
                'detail' => 'fichiers, BAT, conception',
                'droits' => ['ROLE_PREPRESSE'],
                'lecture' => Droits::LECTURE_PAO,
                'prefixes' => ['app_controle_pre_presse_', 'app_produits_clients_prepresse_', 'app_conception_', 'app_demandes_conception_', 'app_fichier_dossier_partage'],
            ],
            'stock' => [
                'libelle' => 'Stock et achats',
                'detail' => 'articles, fournisseurs, prix d\'achat',
                'droits' => [],
                'fixe' => self::FIXE_DIRECTION,
            ],
            'finances' => [
                'libelle' => 'Finances',
                'detail' => 'trésorerie, dépenses, virements',
                'droits' => ['ROLE_TRESORERIE_VOIR', 'ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER'],
                'voir' => ['ROLE_TRESORERIE_VOIR'],
            ],
            'equipe' => [
                'libelle' => 'Équipe et paie',
                'detail' => 'pointages, demandes, paie, actions de l\'équipe',
                'droits' => ['ROLE_RH', 'ROLE_ACTIONS_GERER'],
            ],
            'entretien' => [
                'libelle' => 'Entretien des locaux',
                'detail' => 'planning d\'entretien, photos',
                'droits' => ['ROLE_ENTRETIEN'],
            ],
            // Contenu du site public : à la Direction, et à qui on confie la mise à jour du site.
            'site' => [
                'libelle' => 'Site vitrine',
                'detail' => 'pages, réalisations, avis, conseils, partenaires, équipe, annonces',
                'droits' => ['ROLE_SITE_VITRINE'],
            ],
            // Crédits SMS et e-mail (docs/messagerie-marketing.md) : solde, fin du pack, historique des envois.
            'messagerie' => [
                'libelle' => 'Crédits SMS et e-mail',
                'detail' => 'solde SMS, limites e-mail, historique des envois',
                'droits' => ['ROLE_CREDITS_MESSAGES'],
            ],
            // Corbeille (docs/corbeille.md) : sur l'ERP local ; chacun n'y voit que les fiches de ses autres droits.
            'corbeille' => [
                'libelle' => 'Corbeille',
                'detail' => 'fiches supprimées : restaurer, supprimer pour de bon',
                'droits' => [Droits::CORBEILLE],
                'lecture' => Droits::LECTURE_CORBEILLE,
                'prefixes' => ['app_corbeille_'],
            ],
            'reglages' => [
                'libelle' => 'Réglages',
                'detail' => 'utilisateurs, prix, sécurité',
                'droits' => [],
                'fixe' => self::FIXE_DIRECTION,
            ],
            'mon_espace' => [
                'libelle' => 'Mon espace',
                'detail' => 'pointage, points, congés, bulletins',
                'droits' => [],
                'fixe' => self::FIXE_TOUS,
            ],
        ];
    }

    /**
     * Montants qu'on peut masquer meme quand le module est visible.
     *
     * @return list<string>
     */
    public static function montants(): array
    {
        return ['ROLE_PAIEMENT_VOIR', 'ROLE_COUT_MARGE', 'ROLE_STATS_VENTES', 'ROLE_STATS_GLOBAL', 'ROLE_STATS_PERSO', 'ROLE_COMPTABILITE'];
    }

    /**
     * Niveau d'un module pour une liste de droits (ceux d'un role).
     *
     * @param list<string> $droits
     */
    public static function niveau(string $module, array $droits, bool $direction = false): string
    {
        $definition = self::catalogue()[$module] ?? throw new \InvalidArgumentException('Module inconnu : ' . $module);

        if (isset($definition['fixe'])) {
            return match (true) {
                self::FIXE_TOUS === $definition['fixe'] => self::VOIR,
                $direction => self::MODIFIER,
                default => self::AUCUN,
            };
        }
        if ($direction) {
            return self::MODIFIER;
        }

        $presents = array_intersect($definition['droits'], $droits);
        if ([] === $presents) {
            return self::AUCUN;
        }
        if (isset($definition['lecture']) && \in_array($definition['lecture'], $droits, true)) {
            return self::VOIR;
        }
        if (isset($definition['voir']) && [] === array_diff($presents, $definition['voir'])) {
            return self::VOIR;
        }

        return self::MODIFIER;
    }

    /**
     * Le role a-t-il seulement une partie des droits de ce niveau ? (case
     * cochee a la main dans les droits detailles)
     *
     * @param list<string> $droits
     */
    public static function partiel(string $module, array $droits): bool
    {
        $niveau = self::niveau($module, $droits);
        $attendus = self::droitsDuNiveau($module, $niveau);

        return self::AUCUN !== $niveau && [] !== array_diff($attendus, $droits);
    }

    /**
     * Niveaux qu'on peut choisir pour ce module ([] : case fixe).
     *
     * @return list<string>
     */
    public static function niveauxPossibles(string $module): array
    {
        $definition = self::catalogue()[$module];
        if (isset($definition['fixe'])) {
            return [];
        }

        return isset($definition['voir']) || isset($definition['lecture'])
            ? [self::MODIFIER, self::VOIR, self::AUCUN]
            : [self::MODIFIER, self::AUCUN];
    }

    /** Niveau suivant quand on clique sur la case (Modifier, Voir, —, Modifier...). */
    public static function suivant(string $module, string $niveau): string
    {
        $possibles = self::niveauxPossibles($module);
        $rang = array_search($niveau, $possibles, true);

        return $possibles[false === $rang ? 0 : ($rang + 1) % \count($possibles)];
    }

    /**
     * Droits d'un role une fois le niveau du module change : ceux des autres
     * modules et les montants ne bougent pas.
     *
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function appliquer(string $module, string $niveau, array $droits): array
    {
        if (!\in_array($niveau, self::niveauxPossibles($module), true)) {
            throw new \InvalidArgumentException('Niveau impossible pour ce module.');
        }
        $definition = self::catalogue()[$module];
        $aRetirer = array_merge($definition['droits'], $definition['voir'] ?? [], isset($definition['lecture']) ? [$definition['lecture']] : []);
        // Un droit partage avec un autre module (aucun aujourd'hui) resterait.
        $restants = array_values(array_diff($droits, $aRetirer));

        return Droits::filtrer(array_merge($restants, self::droitsDuNiveau($module, $niveau)));
    }

    /**
     * Module en lecture seule qui couvre cette route, pour ces droits.
     *
     * @param list<string> $droits
     */
    public static function moduleEnLecture(string $route, array $droits): ?string
    {
        foreach (self::catalogue() as $cle => $definition) {
            if (!isset($definition['lecture']) || !\in_array($definition['lecture'], $droits, true)) {
                continue;
            }
            foreach ($definition['prefixes'] ?? [] as $prefixe) {
                if (str_starts_with($route, $prefixe)) {
                    return $cle;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function droitsDuNiveau(string $module, string $niveau): array
    {
        $definition = self::catalogue()[$module];

        return match ($niveau) {
            self::MODIFIER => $definition['droits'],
            self::VOIR => isset($definition['lecture'])
                ? array_merge($definition['droits'], [$definition['lecture']])
                : ($definition['voir'] ?? []),
            default => [],
        };
    }
}
