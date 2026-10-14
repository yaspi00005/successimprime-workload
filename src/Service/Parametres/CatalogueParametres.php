<?php

namespace App\Service\Parametres;

use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\SuiviMetiers\Bareme;

/**
 * Les réglages de l'écran Paramètres (maquettes S01 à S04) : 6 familles
 * de couleur, une tuile par réglage, et pour chaque tuile les champs que
 * le panneau d'édition rapide propose.
 *
 * Un champ sans « source » est rangé dans la table reglage ; un champ
 * avec source vit déjà ailleurs (entreprise principale, paramètres de
 * paiement) et c'est cette valeur-là qui est lue et modifiée.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class CatalogueParametres
{
    public const FAMILLES = [
        'entreprise' => ['libelle' => 'Entreprise', 'couleur' => 'bleu'],
        'ventes' => ['libelle' => 'Ventes', 'couleur' => 'rouge'],
        'atelier' => ['libelle' => 'Atelier', 'couleur' => 'gris'],
        'personnel' => ['libelle' => 'Personnel', 'couleur' => 'vert'],
        'clients' => ['libelle' => 'Clients et communication', 'couleur' => 'rose'],
        'systeme' => ['libelle' => 'Sécurité et système', 'couleur' => 'orange'],
    ];

    /** Note des tuiles dont les valeurs ne sont pas encore lues par un module. */

    /**
     * @return array<string, array{famille: string, libelle: string, icone: string, roles: list<string>, route: ?string, champs: list<array<string, mixed>>, motsCles: list<string>, note?: string}>
     */
    public static function tuiles(): array
    {
        return [
            // ---------- Entreprise ----------
            'entites' => [
                'famille' => 'entreprise', 'libelle' => 'Entités et en-têtes', 'icone' => 'fa-building',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_entreprises_index', 'champs' => [],
                'motsCles' => ['Raison sociale · logo · adresse', 'En-tête des documents', 'Numéro marchand Orange Money par entité', 'RCCM · NIF · banque'],
            ],
            'modeles' => [
                'famille' => 'entreprise', 'libelle' => 'Modèles de documents', 'icone' => 'fa-file-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_entreprises_index',
                'champs' => [
                    ['cle' => 'qrPaiement', 'type' => 'interrupteur', 'libelle' => 'QR de paiement Orange Money', 'aide' => 'Sur les factures de l\'entité principale', 'source' => 'entreprise'],
                    ['cle' => 'qrSuivi', 'type' => 'interrupteur', 'libelle' => 'QR de suivi de commande', 'aide' => 'Le client suit sa commande en le scannant', 'source' => 'entreprise'],
                    ['cle' => 'montantEnLettres', 'type' => 'interrupteur', 'libelle' => 'Montant en lettres', 'aide' => 'Sous le total des factures', 'source' => 'entreprise'],
                    ['cle' => 'afficherLogoPied', 'type' => 'interrupteur', 'libelle' => 'Logo en pied de page', 'source' => 'entreprise'],
                ],
                'motsCles' => ['Facture · afficher le QR Orange Money', 'Devis, facture, bon de livraison, bon de commande', 'Pied de page · montant en lettres'],
            ],
            'numerotation' => [
                'famille' => 'entreprise', 'libelle' => 'Numérotation', 'icone' => 'fa-hashtag',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_entreprises_index', 'champs' => [],
                'motsCles' => ['Numéro de facture · compteur annuel', 'Format des numéros par entité'],
            ],
            'taxes' => [
                'famille' => 'entreprise', 'libelle' => 'Taxes', 'icone' => 'fa-percent',
                'roles' => ['ROLE_ADMIN'], 'route' => null,
                'champs' => [
                    ['cle' => 'tva_par_defaut', 'type' => 'interrupteur', 'libelle' => 'TVA proposée par défaut', 'aide' => 'Chaque facture garde son choix TVA oui / non', 'defaut' => false],
                    ['cle' => 'taux_tva', 'type' => 'nombre', 'libelle' => 'Taux de TVA', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 18],
                    ['cle' => 'retenue', 'type' => 'nombre', 'libelle' => 'Retenue à la source', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 0],
                ],
                'motsCles' => ['TVA 18 % · Mali', 'Retenue à la source', 'Impôts et taxes'],
                'note' => 'Le taux s\'applique aux nouvelles lignes de devis et de commande quand la TVA est proposée par défaut. La retenue à la source sera appliquée avec les factures fournisseurs.',
            ],

            'finances' => [
                'famille' => 'entreprise', 'libelle' => 'Finances et budgets', 'icone' => 'fa-wallet',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_finances_vue_ensemble',
                'champs' => [
                    ['cle' => 'objectif_ca', 'type' => 'nombre', 'libelle' => 'Objectif de CA facturé par mois', 'aide' => 'Vue d\'ensemble : « % de l\'objectif »', 'suffixe' => 'F', 'min' => 0, 'max' => 10000000000, 'defaut' => 9000000],
                    ['cle' => 'seuil_tresorerie', 'type' => 'nombre', 'libelle' => 'Seuil d\'alerte de trésorerie', 'aide' => 'Prévision à 90 jours : la direction est prévenue sous ce solde', 'suffixe' => 'F', 'min' => 0, 'max' => 10000000000, 'defaut' => 2000000],
                    ['cle' => 'seuil_approbation', 'type' => 'nombre', 'libelle' => 'Dépense à faire approuver au-delà de', 'aide' => 'Approuvée par la direction (administrateur)', 'suffixe' => 'F', 'min' => 0, 'max' => 100000000, 'defaut' => 100000],
                    ['cle' => 'cout_horaire', 'type' => 'nombre', 'libelle' => 'Coût d\'une heure de main d\'œuvre', 'aide' => 'Rentabilité : temps pointé PAO et atelier', 'suffixe' => 'F', 'min' => 0, 'max' => 1000000, 'defaut' => 1000],
                ],
                'motsCles' => ['Objectif de chiffre d\'affaires', 'Seuil d\'alerte de trésorerie', 'Approbation des dépenses', 'Budgets des dépenses · main d\'œuvre'],
                'note' => 'Les budgets par catégorie de dépense se règlent sur Finances › Dépenses (bouton Budgets).',
            ],
            'statistiques' => [
                'famille' => 'entreprise', 'libelle' => 'Objectifs et rapport de la semaine', 'icone' => 'fa-chart-line',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_statistiques_objectifs',
                'champs' => [
                    ['cle' => 'rapport_actif', 'type' => 'interrupteur', 'libelle' => 'Rapport de la semaine', 'aide' => 'Notification dans l\'application, lien vers la page et le PDF. Jamais par SMS ni WhatsApp.', 'defaut' => true],
                    ['cle' => 'rapport_jour', 'type' => 'choix', 'libelle' => 'Envoyé le', 'options' => ['lundi' => 'lundi', 'mardi' => 'mardi', 'mercredi' => 'mercredi', 'jeudi' => 'jeudi', 'vendredi' => 'vendredi', 'samedi' => 'samedi', 'dimanche' => 'dimanche'], 'defaut' => 'lundi'],
                    ['cle' => 'rapport_heure', 'type' => 'heure', 'libelle' => 'À partir de', 'defaut' => '08:00'],
                    ['cle' => 'pour_direction', 'type' => 'interrupteur', 'libelle' => 'Pour la direction', 'aide' => 'Droit « Chiffre d\'affaires global » : version de l\'entreprise', 'defaut' => true],
                    ['cle' => 'pour_agents', 'type' => 'interrupteur', 'libelle' => 'Pour les agents', 'aide' => 'Droit « Ses propres statistiques » : seulement leurs chiffres', 'defaut' => true],
                    ['cle' => 'par_courriel', 'type' => 'interrupteur', 'libelle' => 'Aussi par e-mail', 'aide' => 'Si la messagerie est configurée et que la fiche employé a une adresse', 'defaut' => false],
                ],
                'motsCles' => ['Objectifs du mois · CA, commandes, m²', 'Objectif par agent, catégorie, machine', 'Rapport de la semaine · lundi 8 h', 'Destinataires du rapport'],
                'note' => 'Les objectifs se fixent mois par mois sur Statistiques › Objectifs (bouton ci-dessous). Sans objectif du mois, le CA reprend l\'objectif de la tuile Finances et budgets.',
            ],

            // ---------- Ventes ----------
            'grille' => [
                'famille' => 'ventes', 'libelle' => 'Grille tarifaire', 'icone' => 'fa-tag',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_grille_tarifaire_index', 'champs' => [],
                'motsCles' => ['Prix au m² · prix par pièce', 'Prix négociés par client', 'Historique des prix'],
            ],
            'produits' => [
                'famille' => 'ventes', 'libelle' => 'Produits et options', 'icone' => 'fa-sliders-h',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_produits_index', 'champs' => [],
                'motsCles' => ['Options dynamiques par produit', 'Finitions · formats · supports', 'Types d\'impression'],
            ],
            'paiements' => [
                'famille' => 'ventes', 'libelle' => 'Paiements', 'icone' => 'fa-credit-card',
                'roles' => ['ROLE_ADMIN', 'ROLE_PARAMETRES'], 'route' => 'app_parametres_paiement_edit',
                'champs' => [
                    ['cle' => 'orange_money', 'type' => 'interrupteur', 'libelle' => 'Orange Money', 'aide' => 'Numéro marchand · frais inclus au client', 'defaut' => true],
                    ['cle' => 'especes', 'type' => 'interrupteur', 'libelle' => 'Espèces en caisse', 'defaut' => true],
                    ['cle' => 'virement', 'type' => 'interrupteur', 'libelle' => 'Virement bancaire', 'defaut' => true],
                    ['cle' => 'wave', 'type' => 'interrupteur', 'libelle' => 'Wave', 'defaut' => true],
                    ['cle' => 'cheque', 'type' => 'interrupteur', 'libelle' => 'Chèque', 'defaut' => true],
                    ['cle' => 'carte', 'type' => 'interrupteur', 'libelle' => 'Carte bancaire', 'defaut' => true],
                    ['cle' => 'virement_seuil', 'type' => 'nombre', 'libelle' => 'Virement conseillé au-delà de', 'aide' => 'La caisse le rappelle pour un paiement en espèces plus élevé', 'suffixe' => 'F', 'min' => 0, 'max' => 100000000, 'defaut' => 500000],
                    ['cle' => 'acompte', 'type' => 'nombre', 'libelle' => 'Acompte par défaut', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 50],
                    ['cle' => 'recu', 'type' => 'choix', 'libelle' => 'Reçu envoyé', 'options' => ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'aucun' => 'Aucun'], 'defaut' => 'whatsapp'],
                    ['cle' => 'tauxFraisRetrait', 'type' => 'nombre', 'libelle' => 'Frais de retrait Orange Money', 'suffixe' => '%', 'min' => 0, 'max' => 20, 'decimales' => 2, 'source' => 'paiement'],
                ],
                'motsCles' => ['Orange Money · numéro marchand et frais', 'Espèces · virement · acompte', 'Reçu de paiement WhatsApp'],
            ],
            'relances' => [
                'famille' => 'ventes', 'libelle' => 'Relances et délais', 'icone' => 'fa-bell',
                'roles' => ['ROLE_ADMIN', 'ROLE_PARAMETRES', 'ROLE_ACTIONS_GERER'], 'route' => 'app_actions_equipe_regles',
                'champs' => [
                    ['cle' => 'delai_heures', 'type' => 'nombre', 'libelle' => 'Délai pour traiter une action', 'suffixe' => 'h de travail', 'min' => 1, 'max' => 72, 'defaut' => 4],
                    ['cle' => 'points_a_temps', 'type' => 'nombre', 'libelle' => 'Points si traité à temps', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10],
                    ['cle' => 'points_retard', 'type' => 'nombre', 'libelle' => 'Points retirés si en retard', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 15],
                    ['cle' => 'source_facture', 'type' => 'interrupteur', 'libelle' => 'Facture échue', 'aide' => 'Relance le lendemain de l\'échéance', 'defaut' => true],
                    ['cle' => 'facture_intervalle_jours', 'type' => 'nombre', 'libelle' => 'Relancer une facture échue tous les', 'suffixe' => 'jours', 'min' => 1, 'max' => 30, 'defaut' => 3],
                    ['cle' => 'source_devis', 'type' => 'interrupteur', 'libelle' => 'Devis sans réponse', 'aide' => 'Ni accepté ni refusé', 'defaut' => true],
                    ['cle' => 'devis_heures', 'type' => 'nombre', 'libelle' => 'Devis sans réponse après', 'suffixe' => 'h', 'min' => 4, 'max' => 720, 'defaut' => 48],
                    ['cle' => 'devis_seconde_jours', 'type' => 'nombre', 'libelle' => 'Seconde relance du devis à', 'suffixe' => 'jours', 'aide' => 'J+7 : l\'agent du devis est prévenu ; 0 = pas de seconde relance', 'min' => 0, 'max' => 30, 'defaut' => 7],
                    ['cle' => 'source_commande', 'type' => 'interrupteur', 'libelle' => 'Commande prête', 'aide' => 'Prévenir le client', 'defaut' => true],
                    ['cle' => 'source_prospect', 'type' => 'interrupteur', 'libelle' => 'Nouveau prospect', 'aide' => 'Site, discussion, appel entrant', 'defaut' => true],
                    ['cle' => 'source_inactif', 'type' => 'interrupteur', 'libelle' => 'Client inactif', 'aide' => 'Plus de commande depuis un moment', 'defaut' => true],
                    ['cle' => 'inactif_jours', 'type' => 'nombre', 'libelle' => 'Client inactif après', 'suffixe' => 'jours', 'min' => 15, 'max' => 365, 'defaut' => 60],
                    ['cle' => 'source_avis', 'type' => 'interrupteur', 'libelle' => 'Avis après livraison', 'aide' => 'Le lendemain de la livraison', 'defaut' => true],
                    ['cle' => 'source_manuelle', 'type' => 'interrupteur', 'libelle' => 'Lignes manuelles du responsable', 'aide' => 'Ex. « prospecter 5 écoles »', 'defaut' => true],
                ],
                'motsCles' => ['Actions de caisse · 4 heures de travail', 'Rappels de paiement · créances', 'Commande prête non retirée'],
                'note' => 'Les lignes d\'action sont créées pour la caisse selon ces sources ; le délai se compte en heures de travail (tuile Horaires).',
            ],

            // ---------- Atelier ----------
            'machines' => [
                'famille' => 'atelier', 'libelle' => 'Machines', 'icone' => 'fa-print',
                'roles' => ['ROLE_ADMIN', 'ROLE_COUT_MARGE'], 'route' => 'app_machines_index',
                'champs' => [
                    ['cle' => 'amortissement', 'type' => 'choix', 'libelle' => 'Amortissement', 'options' => ['usage' => 'À l\'usage (m² imprimés)', 'lineaire' => 'Linéaire (par mois)'], 'defaut' => 'usage'],
                ],
                'motsCles' => ['Amortissement des machines', 'Maintenance · pannes', 'Coût machine par m²'],
                'note' => 'À l\'usage, chaque m² ou page A4 imprimé coûte (prix d\'achat − valeur résiduelle) ÷ capacité prévue, dans le coût de revient et la rentabilité. Capacité et valeur résiduelle se règlent sur l\'écran Machines ; une machine sans capacité reste en linéaire.',
            ],
            'stock' => [
                'famille' => 'atelier', 'libelle' => 'Stock et alertes', 'icone' => 'fa-cube',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_stock_articles_index', 'champs' => [],
                'motsCles' => ['Seuil minimum · articles à commander', 'Alertes de stock', 'Inventaire'],
            ],
            'montage' => [
                'famille' => 'atelier', 'libelle' => 'Montage PAO', 'icone' => 'fa-th-large',
                'roles' => ['ROLE_ADMIN', 'ROLE_PRODUCTION_GERER', 'ROLE_PREPRESSE'], 'route' => 'app_montage_outil',
                'champs' => [
                    ['cle' => 'formats', 'type' => 'texte', 'libelle' => 'Formats de feuille', 'defaut' => 'A4 · A3 · SRA3'],
                    ['cle' => 'marge_mm', 'type' => 'nombre', 'libelle' => 'Marge de sécurité', 'suffixe' => 'mm', 'min' => 0, 'max' => 50, 'defaut' => 5],
                    ['cle' => 'fond_perdu_mm', 'type' => 'nombre', 'libelle' => 'Fond perdu', 'suffixe' => 'mm', 'min' => 0, 'max' => 20, 'defaut' => 3],
                    ['cle' => 'traits_coupe', 'type' => 'interrupteur', 'libelle' => 'Traits de coupe', 'defaut' => true],
                ],
                'motsCles' => ['Imposition · montage des fichiers', 'Format SRA3 · fond perdu', 'Infographiste'],
                'note' => 'L\'outil de montage part de ces valeurs. Depuis un travail PAO, le bouton « Monter » charge le fichier, le format et la quantité, et range le PDF dans la commande.',
            ],

            // ---------- Personnel ----------
            'utilisateurs' => [
                'famille' => 'personnel', 'libelle' => 'Utilisateurs et rôles', 'icone' => 'fa-user-friends',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_utilisateurs_index', 'champs' => [],
                'motsCles' => ['Comptes · mots de passe', 'Rôles et droits', 'Ajouter un utilisateur'],
            ],
            'pointage' => [
                'famille' => 'personnel', 'libelle' => 'Pointage', 'icone' => 'fa-qrcode',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_pointage_reglages',
                'champs' => [
                    ['cle' => 'photo', 'type' => 'interrupteur', 'libelle' => 'Photo obligatoire', 'aide' => 'Caméra frontale, pas de photo depuis la galerie', 'defaut' => true],
                    ['cle' => 'telephone_enregistre', 'type' => 'interrupteur', 'libelle' => 'Téléphone enregistré', 'aide' => 'Chaque employé pointe depuis son appareil validé par la RH', 'defaut' => true],
                    ['cle' => 'gps_zone', 'type' => 'interrupteur', 'libelle' => 'Position GPS dans la zone', 'aide' => 'Refuse un pointage hors de la zone du point de pointage', 'defaut' => true],
                    ['cle' => 'tolerance_minutes', 'type' => 'nombre', 'libelle' => 'Tolérance de retard', 'suffixe' => 'min', 'min' => 0, 'max' => 60, 'defaut' => 10],
                    ['cle' => 'anti_doublon_minutes', 'type' => 'nombre', 'libelle' => 'Anti-doublon', 'suffixe' => 'min', 'min' => 0, 'max' => 30, 'defaut' => 2],
                    ['cle' => 'pause_debut', 'type' => 'heure', 'libelle' => 'Pause déduite de', 'defaut' => '13:00'],
                    ['cle' => 'pause_fin', 'type' => 'heure', 'libelle' => 'Pause déduite jusqu\'à', 'defaut' => '14:00'],
                    ['cle' => 'alerte_depart', 'type' => 'heure', 'libelle' => 'Alerte départ non pointé', 'defaut' => '20:00'],
                    ['cle' => 'photos_jours', 'type' => 'nombre', 'libelle' => 'Photos conservées', 'suffixe' => 'jours', 'min' => 1, 'max' => 365, 'defaut' => 90],
                    ['cle' => 'points_heure', 'type' => 'nombre', 'libelle' => 'Points à l\'heure', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10],
                    ['cle' => 'points_retard', 'type' => 'nombre', 'libelle' => 'Points retirés en retard', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10],
                    ['cle' => 'points_soiree', 'type' => 'nombre', 'libelle' => 'Bonus 1 h après la fin', 'suffixe' => 'pts', 'min' => 0, 'max' => 500, 'defaut' => 30],
                    ['cle' => 'points_nuit', 'type' => 'nombre', 'libelle' => 'Bonus nuit au bureau', 'suffixe' => 'pts', 'min' => 0, 'max' => 1000, 'defaut' => 100],
                    ['cle' => 'points_oubli', 'type' => 'nombre', 'libelle' => 'Points retirés si oubli', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 20],
                ],
                'motsCles' => ['Pointage QR + photo', 'Retards · tolérance', 'Points de ponctualité', 'Affiche QR à imprimer'],
            ],
            'horaires' => [
                'famille' => 'personnel', 'libelle' => 'Horaires', 'icone' => 'fa-clock',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_pointage_jour',
                'champs' => [
                    ['cle' => 'jours', 'type' => 'choix', 'libelle' => 'Jours travaillés', 'options' => ['lun-ven' => 'lun – ven', 'lun-sam' => 'lun – sam', 'lun-dim' => 'lun – dim'], 'defaut' => 'lun-sam'],
                    ['cle' => 'ouverture', 'type' => 'heure', 'libelle' => 'Ouverture', 'defaut' => '08:00'],
                    ['cle' => 'fermeture', 'type' => 'heure', 'libelle' => 'Fermeture', 'defaut' => '18:00'],
                    ['cle' => 'feries', 'type' => 'dates', 'libelle' => 'Jours fériés (JJ/MM chaque année, JJ/MM/AAAA pour une seule année)', 'defaut' => HeuresTravail::FERIES_MALI],
                ],
                'motsCles' => ['Heures d\'ouverture de l\'atelier', 'Jours travaillés', 'Heures de travail'],
                'note' => 'Sert au pointage (retard sur l\'ouverture, heures sup. après la fermeture) et au délai des actions de caisse, qui s\'arrête hors de ces heures, pendant la pause et les jours fériés. Ajoutez chaque année Ramadan, Tabaski, Maouloud et lundi de Pâques.',
            ],
            'bareme' => [
                'famille' => 'personnel', 'libelle' => 'Barème des points', 'icone' => 'fa-star',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_bareme_index',
                'champs' => [
                    ['cle' => 'valeur_point', 'type' => 'nombre', 'libelle' => 'Valeur d\'un point', 'suffixe' => 'F', 'min' => 0, 'max' => 100000, 'defaut' => 100],
                    ['cle' => 'seuil_prime', 'type' => 'nombre', 'libelle' => 'Points minimum pour la prime', 'suffixe' => 'pts', 'min' => 0, 'max' => 10000, 'defaut' => 100],
                    ['cle' => 'ponctualite', 'type' => 'interrupteur', 'libelle' => 'Compter la ponctualité', 'aide' => 'Graphiste, machiniste, livreur, entretien', 'defaut' => true],
                    ['cle' => 'delai_bat_heures', 'type' => 'nombre', 'libelle' => 'Délai du BAT par défaut', 'suffixe' => 'h de travail', 'aide' => 'Échéance proposée à l\'arrivée d\'un travail PAO, modifiable par travail', 'min' => 1, 'max' => 72, 'defaut' => 4],
                    ...Bareme::champs(),
                ],
                'motsCles' => ['Points par métier · primes', 'Graphiste · machiniste · livreur · entretien', 'Coefficient de difficulté · paliers de prime'],
                'note' => 'Les points de chaque métier se modifient dans l\'écran du barème (bouton ci-dessous). Ils seront comptés quand les écrans de suivi des métiers seront branchés.',
            ],
            'avantages' => [
                'famille' => 'personnel', 'libelle' => 'Avantages', 'icone' => 'fa-gift',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_demandes_index',
                'champs' => [
                    ['cle' => 'frais_medicaux', 'type' => 'nombre', 'libelle' => 'Frais médicaux remboursés', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 80],
                    ['cle' => 'plafond_annuel', 'type' => 'nombre', 'libelle' => 'Plafond par an', 'suffixe' => 'F', 'min' => 0, 'max' => 100000000, 'defaut' => 0],
                    ['cle' => 'payer_om', 'type' => 'interrupteur', 'libelle' => 'Rembourser par Orange Money', 'defaut' => true],
                ],
                'motsCles' => ['Frais médicaux · payer par Orange Money', 'Avantages du personnel'],
                'note' => 'L\'employé demande le remboursement depuis Mon espace avec la facture ; la RH voit le montant proposé (taux et plafond) et l\'accepte ou l\'ajuste.',
            ],
            'paie' => [
                'famille' => 'personnel', 'libelle' => 'Paie', 'icone' => 'fa-money-bill-wave',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_paie_index',
                'champs' => [
                    ['cle' => 'jour_paie', 'type' => 'nombre', 'libelle' => 'Jour de paie', 'suffixe' => 'du mois', 'min' => 1, 'max' => 31, 'defaut' => 5],
                    ['cle' => 'mode', 'type' => 'choix', 'libelle' => 'Mode de paiement des salaires', 'options' => ['orange_money' => 'Orange Money', 'virement' => 'Virement', 'especes' => 'Espèces'], 'defaut' => 'orange_money'],
                    ['cle' => 'inps', 'type' => 'interrupteur', 'libelle' => 'Cotisations INPS', 'aide' => 'Active la ligne INPS sur les bulletins (montant saisi).', 'defaut' => false],
                    ['cle' => 'its', 'type' => 'interrupteur', 'libelle' => 'Impôt sur les traitements (ITS)', 'aide' => 'Active la ligne ITS sur les bulletins (montant saisi).', 'defaut' => false],
                ],
                'motsCles' => ['Mode de paiement des salaires : Orange Money', 'INPS · ITS', 'Jour de paie', 'Bulletins de paie'],
                'note' => 'Équipe › Paie prépare un bulletin par personne avec le salaire de sa fiche employé. Le paiement est un décaissement « Salaire » confidentiel en trésorerie.',
            ],

            // ---------- Clients et communication ----------
            'espace_client' => [
                'famille' => 'clients', 'libelle' => 'Espace client', 'icone' => 'fa-globe-africa',
                'roles' => ['ROLE_ADMIN', 'ROLE_PARAMETRES'], 'route' => null,
                'champs' => [
                    ['cle' => 'code_sms', 'type' => 'interrupteur', 'libelle' => 'Connexion par code SMS', 'aide' => 'Sans mot de passe. La création de compte vérifie toujours le numéro par code.', 'defaut' => true],
                    ['cle' => 'commande_en_ligne', 'type' => 'interrupteur', 'libelle' => 'Commande en ligne', 'defaut' => true],
                    ['cle' => 'paiement_en_ligne', 'type' => 'interrupteur', 'libelle' => 'Paiement Orange Money en ligne', 'defaut' => false],
                ],
                'motsCles' => ['Connexion par code SMS', 'Commande en ligne du client', 'Paiement Orange Money en ligne', 'WhatsApp · mot de passe'],
                'note' => 'La connexion par code est appliquée. La commande et le paiement en ligne le seront avec l\'espace client complet (jalon J5).',
            ],
            // Programme de points (docs/fidelite.md), règles de Yaya du 13/10/2026.
            'fidelite' => [
                'famille' => 'clients', 'libelle' => 'Fidélité', 'icone' => 'fa-award',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_fidelite_index',
                'champs' => [
                    ['cle' => 'actif', 'type' => 'interrupteur', 'libelle' => 'Programme de points', 'aide' => 'Clients au prix public seulement (pas la grille B2B)', 'defaut' => true],
                    ['cle' => 'seuil_m2', 'type' => 'nombre', 'libelle' => 'Impressions au m² : éligible dès', 'suffixe' => 'm²', 'min' => 0, 'max' => 10000, 'decimales' => 1, 'defaut' => 5],
                    ['cle' => 'points_m2', 'type' => 'nombre', 'libelle' => 'Points par m² au-delà', 'suffixe' => 'pts', 'min' => 0, 'max' => 100000, 'defaut' => 100],
                    ['cle' => 'seuil_montant', 'type' => 'nombre', 'libelle' => 'Autres articles : éligible dès', 'aide' => 'DTF, textile, papier, services', 'suffixe' => 'F', 'min' => 0, 'max' => 100000000, 'defaut' => 25000],
                    ['cle' => 'taux_autre', 'type' => 'nombre', 'libelle' => 'Autres articles : % en points', 'suffixe' => '%', 'min' => 0, 'max' => 50, 'decimales' => 2, 'defaut' => 2],
                    ['cle' => 'valeur_point', 'type' => 'nombre', 'libelle' => 'Valeur d\'un point', 'suffixe' => 'F', 'min' => 1, 'max' => 1000, 'defaut' => 1],
                    ['cle' => 'multiplicateur_jour', 'type' => 'nombre', 'libelle' => 'Payé le jour même : points ×', 'suffixe' => '×', 'min' => 0, 'max' => 10, 'defaut' => 2],
                    ['cle' => 'multiplicateur_delai', 'type' => 'nombre', 'libelle' => 'Payé dans le délai : points ×', 'suffixe' => '×', 'min' => 0, 'max' => 10, 'defaut' => 1],
                    ['cle' => 'delai_heures', 'type' => 'nombre', 'libelle' => 'Délai de paiement', 'aide' => 'Depuis la facture (ou la commande) ; au-delà, le paiement ne rapporte rien', 'suffixe' => 'h', 'min' => 1, 'max' => 720, 'defaut' => 72],
                    ['cle' => 'validite_mois', 'type' => 'nombre', 'libelle' => 'Les points expirent après', 'suffixe' => 'mois', 'min' => 1, 'max' => 60, 'defaut' => 12],
                    ['cle' => 'alerte_jours', 'type' => 'nombre', 'libelle' => '« Expirent bientôt » dans les', 'suffixe' => 'jours', 'min' => 1, 'max' => 120, 'defaut' => 30],
                    ['cle' => 'dormant_jours', 'type' => 'nombre', 'libelle' => 'Client dormeur après', 'suffixe' => 'jours', 'min' => 30, 'max' => 730, 'defaut' => 90],
                    ['cle' => 'reveil_taux', 'type' => 'nombre', 'libelle' => 'Promo de réveil proposée', 'suffixe' => '%', 'min' => 1, 'max' => 50, 'defaut' => 10],
                    ['cle' => 'reveil_jours', 'type' => 'nombre', 'libelle' => 'Promo de réveil valable', 'suffixe' => 'jours', 'min' => 1, 'max' => 90, 'defaut' => 15],
                ],
                'motsCles' => ['Points de fidélité · 1 point = 1 F', '100 points par m² dès 5 m²', '2 % au-delà de 25 000 F', 'Payé le jour même ×2', 'Fidélité par article', 'Clients dormeurs · promo de réveil'],
                'note' => 'Les valeurs propres d\'un gros client se règlent sur sa fiche (onglet Fidélité) ; la fidélité par article et les clients dormeurs sur l\'écran Fidélité (bouton ci-dessous).',
            ],
            'messages' => [
                'famille' => 'clients', 'libelle' => 'Messages WhatsApp et SMS', 'icone' => 'fa-comment-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_modele_message_index', 'champs' => [],
                'motsCles' => ['Modèles de messages', 'Campagnes WhatsApp et SMS'],
            ],
            'ecran_tv' => [
                'famille' => 'clients', 'libelle' => 'Écran TV de l\'accueil', 'icone' => 'fa-tv',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_ecran_tv_reglages', 'champs' => [],
                'motsCles' => ['Playlist de l\'écran TV', 'Bandeau · avis clients', 'Horaires de l\'écran'],
            ],
            'site_contact' => [
                'famille' => 'clients', 'libelle' => 'Site : page Contact', 'icone' => 'fa-map-marker-alt',
                'roles' => ['ROLE_ADMIN', 'ROLE_PARAMETRES'], 'route' => 'app_site_contact',
                'champs' => [
                    ['cle' => 'lien_plan', 'type' => 'texte', 'libelle' => 'Lien Google Maps de la boutique', 'aide' => 'Lien de partage (https://maps.app.goo.gl/…) ou coordonnées « 12.6392, -8.0029 ». Vide : pas de plan ni de bouton Itinéraire.', 'defaut' => ''],
                ],
                'motsCles' => ['Plan d\'accès · bouton Itinéraire', 'Lien Google Maps · coordonnées GPS', 'Contact du site vitrine'],
                'note' => 'Les horaires de la page viennent de la tuile Horaires ; l\'adresse, le téléphone et l\'e-mail, de l\'entité Succès Imprim (Entités et en-têtes).',
            ],

            // ---------- Sécurité et système ----------
            'appareils' => [
                'famille' => 'systeme', 'libelle' => 'Appareils autorisés', 'icone' => 'fa-shield-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_appareils_index', 'champs' => [],
                'motsCles' => ['Accès à l\'admin en ligne', 'Appareils à approuver', 'Code par SMS'],
            ],
            'api' => [
                'famille' => 'systeme', 'libelle' => 'Accès API', 'icone' => 'fa-plug',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_jetons_api_index', 'champs' => [],
                'motsCles' => ['Jetons pour Excel, rapports, applications', 'Documentation de l\'API'],
            ],
            'synchronisation' => [
                'famille' => 'systeme', 'libelle' => 'Synchronisation', 'icone' => 'fa-sync-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_synchronisation_index', 'champs' => [],
                'motsCles' => ['ERP local et site en ligne', 'Opérations provisoires', 'Derniers échanges', 'Conflits'],
            ],
            'sauvegardes' => [
                'famille' => 'systeme', 'libelle' => 'Sauvegardes', 'icone' => 'fa-save',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_sauvegardes_index',
                'champs' => [
                    ['cle' => 'heure', 'type' => 'heure', 'libelle' => 'Sauvegarde complète chaque jour à', 'aide' => 'Base et fichiers des clients et du personnel', 'defaut' => '02:00'],
                    ['cle' => 'base_heures', 'type' => 'nombre', 'libelle' => 'Base seule toutes les', 'suffixe' => 'heures', 'min' => 1, 'max' => 24, 'defaut' => 6],
                    ['cle' => 'jours', 'type' => 'nombre', 'libelle' => 'Garder une sauvegarde par jour pendant', 'suffixe' => 'jours', 'min' => 1, 'max' => 90, 'defaut' => 14],
                    ['cle' => 'semaines', 'type' => 'nombre', 'libelle' => 'Puis une par semaine pendant', 'suffixe' => 'semaines', 'min' => 0, 'max' => 52, 'defaut' => 8],
                    ['cle' => 'mois', 'type' => 'nombre', 'libelle' => 'Puis une par mois pendant', 'suffixe' => 'mois', 'min' => 0, 'max' => 60, 'defaut' => 12],
                ],
                'motsCles' => ['Sauvegarde de nuit', 'Base de données', 'Second disque', 'Restauration'],
            ],
            'taches' => [
                'famille' => 'systeme', 'libelle' => 'Tâches récurrentes', 'icone' => 'fa-calendar-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_decaissement_recurrent_index', 'champs' => [],
                'motsCles' => ['Dépenses récurrentes · loyer, taxes', 'Fournisseurs à payer chaque mois'],
            ],
        ];
    }

    /**
     * Valeurs des champs rangés dans la table reglage, complétées par
     * les valeurs par défaut.
     *
     * @param list<array<string, mixed>> $champs
     * @param array<string, mixed>       $stockees
     *
     * @return array<string, mixed>
     */
    public static function avecDefauts(array $champs, array $stockees): array
    {
        $valeurs = [];

        foreach ($champs as $champ) {
            if (isset($champ['source'])) {
                continue;
            }
            $valeurs[$champ['cle']] = \array_key_exists($champ['cle'], $stockees) ? $stockees[$champ['cle']] : ($champ['defaut'] ?? null);
        }

        return $valeurs;
    }

    /**
     * Lit et contrôle la saisie du panneau.
     *
     * @param list<array<string, mixed>> $champs
     * @param array<string, mixed>       $saisie
     *
     * @return array{valeurs: array<string, mixed>, erreurs: array<string, string>}
     */
    public static function lireSaisie(array $champs, array $saisie): array
    {
        $valeurs = [];
        $erreurs = [];

        foreach ($champs as $champ) {
            $cle = $champ['cle'];
            $brut = $saisie[$cle] ?? null;

            switch ($champ['type']) {
                case 'interrupteur':
                    $valeurs[$cle] = \in_array($brut, ['1', 'on', 1, true], true);
                    break;

                case 'nombre':
                    $texte = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim((string) $brut));
                    if ($texte === '' || !is_numeric($texte)) {
                        $erreurs[$cle] = 'Nombre attendu.';
                        break;
                    }
                    $nombre = ($champ['decimales'] ?? 0) > 0 ? round((float) $texte, (int) $champ['decimales']) : (int) round((float) $texte);
                    if ($nombre < ($champ['min'] ?? \PHP_INT_MIN) || $nombre > ($champ['max'] ?? \PHP_INT_MAX)) {
                        $erreurs[$cle] = \sprintf('Entre %s et %s.', $champ['min'], $champ['max']);
                        break;
                    }
                    $valeurs[$cle] = $nombre;
                    break;

                case 'heure':
                    $texte = trim((string) $brut);
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $texte)) {
                        $erreurs[$cle] = 'Heure attendue (ex. 08:00).';
                        break;
                    }
                    $valeurs[$cle] = $texte;
                    break;

                case 'dates':
                    $dates = HeuresTravail::lireDates((string) $brut);
                    if ($dates === null || \count($dates) > 60) {
                        $erreurs[$cle] = 'Dates JJ/MM ou JJ/MM/AAAA séparées par des virgules.';
                        break;
                    }
                    sort($dates);
                    $valeurs[$cle] = implode(', ', array_map(static fn (string $d): string => implode('/', array_reverse(explode('-', $d))), $dates));
                    break;

                case 'choix':
                    if (!\is_string($brut) || !\array_key_exists($brut, $champ['options'])) {
                        $erreurs[$cle] = 'Choix inconnu.';
                        break;
                    }
                    $valeurs[$cle] = $brut;
                    break;

                default:
                    $texte = trim((string) $brut);
                    if (mb_strlen($texte) > 120) {
                        $erreurs[$cle] = '120 caractères au plus.';
                        break;
                    }
                    $valeurs[$cle] = $texte;
            }
        }

        return ['valeurs' => $valeurs, 'erreurs' => $erreurs];
    }

    /**
     * Champs qui changent, avec leur valeur avant et après.
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     *
     * @return array{avant: array<string, mixed>, apres: array<string, mixed>}
     */
    public static function differences(array $avant, array $apres): array
    {
        $diff = ['avant' => [], 'apres' => []];

        foreach ($apres as $cle => $valeur) {
            $ancienne = $avant[$cle] ?? null;
            $egal = is_numeric($ancienne) && is_numeric($valeur) ? (float) $ancienne === (float) $valeur : $ancienne === $valeur;
            if (!$egal) {
                $diff['avant'][$cle] = $ancienne;
                $diff['apres'][$cle] = $valeur;
            }
        }

        return $diff;
    }

    /**
     * Valeur lisible d'un champ, pour le journal et l'état des tuiles.
     *
     * @param array<string, mixed> $champ
     */
    public static function afficher(array $champ, mixed $valeur): string
    {
        return match ($champ['type']) {
            'interrupteur' => $valeur ? 'oui' : 'non',
            'choix' => (string) ($champ['options'][$valeur] ?? $valeur),
            'nombre' => trim(number_format((float) $valeur, ($champ['decimales'] ?? 0) > 0 && floor((float) $valeur) != $valeur ? (int) $champ['decimales'] : 0, ',', ' ').' '.($champ['suffixe'] ?? '')),
            'heure' => str_replace(':', ' h ', (string) $valeur),
            default => (string) $valeur,
        };
    }

    /**
     * Champs du panneau d'édition rapide : sans ceux modifiés dans l'écran
     * dédié de la tuile (marqués « ecran »).
     *
     * @return list<array<string, mixed>>
     */
    public static function champsPanneau(string $tuile): array
    {
        return array_values(array_filter(self::tuiles()[$tuile]['champs'] ?? [], static fn (array $c): bool => empty($c['ecran'])));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function champ(string $tuile, string $cle): ?array
    {
        foreach (self::tuiles()[$tuile]['champs'] ?? [] as $champ) {
            if ($champ['cle'] === $cle) {
                return $champ;
            }
        }

        return null;
    }

    /**
     * Garde au plus $nombre tuiles récentes, la dernière ouverte en tête.
     *
     * @param list<string> $recents
     *
     * @return list<string>
     */
    public static function ajouterRecent(array $recents, string $tuile, int $nombre = 3): array
    {
        $recents = array_values(array_filter($recents, static fn (string $t): bool => $t !== $tuile));
        array_unshift($recents, $tuile);

        return \array_slice($recents, 0, $nombre);
    }
}
