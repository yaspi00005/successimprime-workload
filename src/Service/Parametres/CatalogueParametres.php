<?php

namespace App\Service\Parametres;

use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\PiecesParMetreCarre;
use App\Service\Rapports\ReglagesRapportsMail;
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
            'patrimoine' => [
                'famille' => 'entreprise', 'libelle' => 'Patrimoine et amortissements', 'icone' => 'fa-landmark',
                'roles' => ['ROLE_ADMIN', 'ROLE_COMPTABILITE'], 'route' => 'app_immobilisations_patrimoine',
                'champs' => [
                    ['cle' => 'inventaire_mois', 'type' => 'nombre', 'libelle' => 'Contrôler chaque bien au moins tous les', 'aide' => 'Patrimoine : « Biens à contrôler » au-delà', 'suffixe' => 'mois', 'min' => 1, 'max' => 60, 'defaut' => 12],
                    ['cle' => 'releve_jour', 'type' => 'nombre', 'libelle' => 'Relevés de compteurs attendus jusqu\'au', 'aide' => 'La dotation d\'une machine suivie par relevé attend son relevé jusqu\'à ce jour du mois suivant', 'suffixe' => 'du mois suivant', 'min' => 1, 'max' => 28, 'defaut' => 10],
                ],
                'motsCles' => ['Registre des immobilisations · IMM-0001', 'Dotation aux amortissements · SYSCOHADA 681 / 28x', 'Relevés de compteurs · Canon', 'Inventaire annuel · étiquettes QR'],
                'note' => 'Les machines s\'amortissent à l\'usage (tuile Machines), les autres biens en linéaire sur leur durée. La dotation du mois est passée chaque matin pour le mois précédent (commande app:immobilisations:dotations).',
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
            // Rapports du jour, de la semaine et du mois par e-mail (docs/rapports-mail.md). Les
            // destinataires se choisissent sur la page de la tuile (champs « ecran »). ERP local.
            ReglagesRapportsMail::TUILE => [
                'famille' => 'entreprise', 'libelle' => 'Rapports par e-mail', 'icone' => 'fa-envelope-open-text',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_rapports_mail',
                'champs' => [
                    ['cle' => 'jour_actif', 'type' => 'interrupteur', 'libelle' => 'Rapport du jour', 'aide' => 'Activité et argent de la journée, le soir même', 'defaut' => true],
                    ['cle' => 'jour_heure', 'type' => 'heure', 'libelle' => 'Heure du rapport du jour', 'aide' => 'Le soir : la journée même. Avant midi (7 h) : le lendemain matin, pour la veille', 'defaut' => '20:00'],
                    ['cle' => 'jour_ouvres', 'type' => 'interrupteur', 'libelle' => 'Seulement les jours d\'ouverture', 'aide' => 'Jours travaillés et jours fériés de la tuile Horaires', 'defaut' => true],
                    ['cle' => 'semaine_actif', 'type' => 'interrupteur', 'libelle' => 'Rapport de la semaine', 'aide' => 'La semaine passée, du lundi au dimanche', 'defaut' => true],
                    ['cle' => 'semaine_jour', 'type' => 'choix', 'libelle' => 'Rapport de la semaine envoyé le', 'options' => ['lundi' => 'lundi', 'mardi' => 'mardi', 'mercredi' => 'mercredi', 'jeudi' => 'jeudi', 'vendredi' => 'vendredi', 'samedi' => 'samedi', 'dimanche' => 'dimanche'], 'defaut' => 'lundi'],
                    ['cle' => 'semaine_heure', 'type' => 'heure', 'libelle' => 'Heure du rapport de la semaine', 'defaut' => '07:00'],
                    ['cle' => 'mois_actif', 'type' => 'interrupteur', 'libelle' => 'Rapport du mois', 'aide' => 'Le mois passé, comparé au mois d\'avant et aux objectifs', 'defaut' => true],
                    ['cle' => 'mois_jour', 'type' => 'nombre', 'libelle' => 'Rapport du mois envoyé le', 'suffixe' => 'du mois', 'min' => 1, 'max' => 28, 'defaut' => 1],
                    ['cle' => 'mois_heure', 'type' => 'heure', 'libelle' => 'Heure du rapport du mois', 'defaut' => '07:00'],
                    ['cle' => 'pdf', 'type' => 'interrupteur', 'libelle' => 'PDF joint', 'aide' => 'La même version en PDF, pour l\'archiver ou l\'imprimer', 'defaut' => true],
                    ['cle' => 'adresse_erp', 'type' => 'texte', 'longueur' => 200, 'libelle' => 'Adresse de l\'ERP dans l\'atelier', 'aide' => 'Pour le bouton « Ouvrir dans l\'ERP » de l\'e-mail, par exemple http://192.168.1.10/successImprim/public. Vide : pas de bouton dans les envois automatiques.', 'defaut' => ''],
                    ['cle' => 'destinataires_choisis', 'type' => 'interrupteur', 'libelle' => 'Destinataires choisis', 'aide' => 'Non : la direction (comptes avec une adresse e-mail)', 'defaut' => false, 'ecran' => true],
                    ['cle' => 'destinataires', 'type' => 'texte', 'longueur' => 2000, 'libelle' => 'Destinataires (numéros des comptes)', 'defaut' => '', 'ecran' => true],
                ],
                'motsCles' => ['Rapport du jour · 20 h', 'Rapport de la semaine · lundi 7 h', 'Rapport du mois · le 1er à 7 h', 'Destinataires des rapports · administration', 'Envoi par e-mail · PDF joint'],
                'note' => 'Destinataires, aperçu, essai et historique des envois : bouton ci-dessous. Chacun reçoit sa version, selon ses droits. Rien ne part tant que l\'envoi d\'e-mails n\'est pas réglé sur l\'ERP (MAILER_DSN).',
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
                'motsCles' => ['Options dynamiques par produit', 'Finitions · formats · supports', 'Types d\'impression', 'Noms commerciaux (autocollant, banderole…)'],
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
                    // Montrées aux clients qui paient, dans « Factures et paiements » de l'espace client.
                    ['cle' => 'virement_coordonnees', 'type' => 'texte', 'libelle' => 'Coordonnées bancaires pour les virements', 'aide' => 'Banque, titulaire et RIB, montrés dans l\'espace client aux personnes qui paient. Vide : « coordonnées à demander en boutique ».', 'defaut' => ''],
                    ['cle' => 'acompte', 'type' => 'nombre', 'libelle' => 'Acompte par défaut', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 50],
                    ['cle' => 'recu', 'type' => 'choix', 'libelle' => 'Reçu envoyé', 'options' => ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'aucun' => 'Aucun'], 'defaut' => 'whatsapp'],
                    // Frais Orange Money payés par le client en plus du montant (espace client, caisse) ; tenus dans ParametresPaiement.
                    ['cle' => 'tauxFondsSoutien', 'type' => 'nombre', 'libelle' => 'Fonds de soutien Orange Money', 'aide' => 'Payé par le client en plus du montant, à chaque paiement Orange Money. 0 : la ligne disparaît.', 'suffixe' => '%', 'min' => 0, 'max' => 20, 'decimales' => 2, 'source' => 'paiement'],
                    ['cle' => 'tauxFraisRetrait', 'type' => 'nombre', 'libelle' => 'Frais de retrait Orange Money', 'aide' => 'Payé par le client en plus du montant, à chaque paiement Orange Money. 0 : la ligne disparaît.', 'suffixe' => '%', 'min' => 0, 'max' => 20, 'decimales' => 2, 'source' => 'paiement'],
                ],
                'motsCles' => ['Orange Money · numéro marchand et frais', 'Fonds de soutien · frais de retrait', 'Espèces · virement · acompte', 'Reçu de paiement WhatsApp'],
            ],
            // Saisie des devis et commandes (docs/documents-livraisons.md) : boutons des frais de
            // livraison et objets suggérés. Les paliers et les frais de la commande en ligne partent
            // sur le site (CatalogueSynchro::REGLAGES_PUBLICS) ; les objets restent au local.
            ReglagesSaisie::TUILE => [
                'famille' => 'ventes', 'libelle' => 'Livraison et objet', 'icone' => 'fa-truck',
                'roles' => ['ROLE_ADMIN'], 'route' => null,
                'champs' => [
                    ['cle' => 'paliers_livraison', 'type' => 'texte', 'format' => 'montants', 'libelle' => 'Frais de livraison proposés', 'aide' => 'Montants en F séparés par des virgules, du plus petit au plus grand : le plus grand est le maximum. Vide : pas de frais de livraison.', 'suffixe' => 'F', 'defaut' => ReglagesSaisie::PALIERS_DEFAUT],
                    // Un des paliers ci-dessus, ou 0 : contrôlé avec eux (lireSaisie, 'palierDe').
                    ['cle' => 'livraison_en_ligne', 'type' => 'nombre', 'libelle' => 'Frais de livraison en ligne', 'aide' => 'Proposés au client qui commande dans son espace (« Livraison à Bamako »). Un des paliers ci-dessus, ou 0 : livraison offerte. La boutique peut les changer en confirmant la commande.', 'suffixe' => 'F', 'min' => 0, 'max' => ReglagesSaisie::PALIER_MAX, 'palierDe' => 'paliers_livraison', 'defaut' => ReglagesSaisie::FRAIS_EN_LIGNE_DEFAUT],
                    ['cle' => 'objets', 'type' => 'texte', 'longueur' => 500, 'libelle' => 'Objets suggérés', 'aide' => 'Séparés par des virgules. L\'objet reste libre à la saisie (150 caractères).', 'defaut' => ReglagesSaisie::OBJETS_DEFAUT],
                ],
                'motsCles' => ['Frais de livraison · 1 000, 1 500, 2 000, 2 500 F', 'Frais de livraison de la commande en ligne', 'Objet de la commande · impressions, prestations informatiques', 'Devis, commande, facture'],
                'note' => 'Frais : montants en F séparés par des virgules ; le plus grand est le maximum, vide = pas de frais. À la saisie d\'un devis ou d\'une commande livrée, l\'agent choisit « Sans frais » ou un palier : une ligne « Livraison » paraît sur le devis, la commande et la facture. Commande en ligne : le client qui choisit « Livraison à Bamako » voit les frais de livraison en ligne (un palier, ou 0) ; la boutique peut les changer en confirmant. Objets : séparés par des virgules, simples suggestions. Les conditions de paiement (bloc « NB » des factures) se règlent par entreprise, tuile Entités et en-têtes.',
            ],
            // Demande de conception dans l'espace client (docs/demande-conception.md). Le coût
            // d'une image chez le fournisseur reste au local (CatalogueSynchro::REGLAGES_PUBLICS).
            'conception' => [
                'famille' => 'ventes', 'libelle' => 'Conception', 'icone' => 'fa-pencil-ruler',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_reglages_parametres_conception_supports',
                'champs' => [
                    ['cle' => 'ia_active', 'type' => 'interrupteur', 'libelle' => 'Génération par IA', 'aide' => 'Proposée seulement si la clé du fournisseur est posée sur le serveur en ligne', 'defaut' => true],
                    ['cle' => 'prix_image', 'type' => 'nombre', 'libelle' => 'Prix d\'une image IA', 'suffixe' => 'F', 'min' => 0, 'max' => 100000, 'defaut' => 1000],
                    ['cle' => 'finalisation', 'type' => 'nombre', 'libelle' => 'Finalisation d\'un visuel IA par un graphiste', 'suffixe' => 'F', 'min' => 0, 'max' => 1000000, 'defaut' => 5000],
                    ['cle' => 'packs', 'type' => 'texte', 'libelle' => 'Packs de crédit IA (images)', 'aide' => 'Nombres séparés par des virgules', 'defaut' => '1, 3, 5, 10'],
                    ['cle' => 'plafond_jour', 'type' => 'nombre', 'libelle' => 'Images IA par jour, tout le site', 'aide' => 'Au-delà, la génération reprend le lendemain', 'suffixe' => 'images', 'min' => 0, 'max' => 10000, 'defaut' => 200],
                    ['cle' => 'limite_heure', 'type' => 'nombre', 'libelle' => 'Images IA par client et par heure', 'suffixe' => 'images', 'min' => 1, 'max' => 100, 'defaut' => 10],
                    ['cle' => 'cout_image', 'type' => 'nombre', 'libelle' => 'Coût d\'une image chez le fournisseur', 'aide' => 'Pour suivre la dépense : jamais montré aux clients, ne part pas sur le site', 'suffixe' => 'F', 'min' => 0, 'max' => 100000, 'defaut' => 0],
                ],
                'motsCles' => ['Demande de conception · supports et forfaits', 'Acompte graphiste · premier BAT', 'IA · 1 000 F par image · packs de crédit', 'Plafond d\'images par jour'],
                'note' => 'Supports, forfaits, acompte et produits du catalogue : bouton ci-dessous.',
            ],
            // Atelier 3D du site et de l'espace client (docs/atelier-3d.md) : le prix vient du
            // catalogue (prix public, prix B2B) ; ces paliers et le prix d'une face en plus s'y ajoutent.
            'atelier_3d' => [
                'famille' => 'ventes', 'libelle' => 'Atelier 3D', 'icone' => 'fa-tshirt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_reglages_parametres_atelier_modeles',
                'champs' => [
                    ['cle' => 'palier1_quantite', 'type' => 'nombre', 'libelle' => 'Premier palier de remise à partir de', 'suffixe' => 'pièces', 'min' => 2, 'max' => 100000, 'defaut' => 25],
                    ['cle' => 'palier1_remise', 'type' => 'nombre', 'libelle' => 'Remise du premier palier', 'suffixe' => '%', 'min' => 0, 'max' => 50, 'defaut' => 10],
                    ['cle' => 'palier2_quantite', 'type' => 'nombre', 'libelle' => 'Second palier à partir de', 'aide' => 'Plus grand que le premier', 'suffixe' => 'pièces', 'min' => 2, 'max' => 100000, 'defaut' => 50],
                    ['cle' => 'palier2_remise', 'type' => 'nombre', 'libelle' => 'Remise du second palier', 'suffixe' => '%', 'min' => 0, 'max' => 50, 'defaut' => 15],
                    ['cle' => 'face_en_plus', 'type' => 'nombre', 'libelle' => 'Marquage de la seconde face (dos), par pièce', 'aide' => 'Le prix du catalogue comprend une face', 'suffixe' => 'F', 'min' => 0, 'max' => 100000, 'defaut' => 1500],
                    ['cle' => 'paliers_b2b', 'type' => 'interrupteur', 'libelle' => 'Paliers aussi pour les revendeurs (prix B2B)', 'aide' => 'Éteint : le revendeur garde son prix B2B, sans palier', 'defaut' => false],
                ],
                'motsCles' => ['Atelier 3D · t-shirt, polo, casquette', 'Remise dès 25 et 50 pièces', 'Couleurs et tailles des articles', 'Seconde face'],
                'note' => 'Articles proposés, couleurs, tailles et zone d\'impression : bouton ci-dessous.',
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
            // Promotions par segment et anniversaires des clients (docs/promotions.md).
            'promotions' => [
                'famille' => 'ventes', 'libelle' => 'Promotions', 'icone' => 'fa-percent',
                'roles' => ['ROLE_ADMIN', 'ROLE_PROMOTIONS'], 'route' => 'app_promotions_index',
                'champs' => [
                    ['cle' => 'alerte_cout_pct', 'type' => 'nombre', 'libelle' => 'Alerte coût : remises au-delà de', 'aide' => 'En % du CA facturé pendant la promotion (indicateur 2)', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'decimales' => 1, 'defaut' => 5],
                    ['cle' => 'alerte_marge_pct', 'type' => 'nombre', 'libelle' => 'Alerte marge : sous', 'aide' => 'Marge brute des lignes en promotion', 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 35],
                    ['cle' => 'anniversaire_delai_jours', 'type' => 'nombre', 'libelle' => 'Anniversaires à venir sur', 'suffixe' => 'jours', 'min' => 7, 'max' => 60, 'defaut' => 21],
                    ['cle' => 'anniversaire_validation', 'type' => 'interrupteur', 'libelle' => 'Valider les anniversaires avant le jour J', 'aide' => 'Sinon la promotion et le SMS partent tout seuls', 'defaut' => true],
                    ['cle' => 'anniversaire_rappel_jours', 'type' => 'nombre', 'libelle' => 'Rappel avant la fin de validité', 'aide' => 'Si la promotion n\'a pas servi ; 0 = pas de rappel', 'suffixe' => 'jours', 'min' => 0, 'max' => 30, 'defaut' => 7],
                    ['cle' => 'anniversaire_consentement', 'type' => 'choix', 'libelle' => 'SMS d\'anniversaire à', 'options' => ['sauf_refus' => 'Tous sauf ceux qui refusent les messages', 'accepte' => 'Ceux qui ont accepté les offres'], 'defaut' => 'sauf_refus'],
                ],
                'motsCles' => ['Promotions par segment de clients', 'Code promo · exception B2B', 'Anniversaire du client · SMS le jour J'],
                'note' => 'Les promotions se créent sur l\'écran Promotions (Ventes) ; les anniversaires et leurs modèles sur l\'écran Anniversaires (Clients).',
            ],

            // Pièces au m² (docs/saisie-devis-commandes.md) : petites pièces au m², à la saisie
            // du comptoir et sur le site. L'espacement part sur le site (CatalogueSynchro::REGLAGES_PUBLICS).
            PiecesParMetreCarre::TUILE => [
                'famille' => 'ventes', 'libelle' => 'Pièces au m²', 'icone' => 'fa-th',
                'roles' => ['ROLE_ADMIN'], 'route' => null,
                'champs' => [
                    ['cle' => PiecesParMetreCarre::CLE_ESPACEMENT, 'type' => 'nombre', 'libelle' => 'Espacement entre les pièces, en largeur et en hauteur', 'aide' => 'Compté entre deux pièces voisines sur 1 m × 1 m. Une ligne déjà enregistrée garde son espacement.', 'suffixe' => 'mm', 'min' => 0, 'max' => (int) PiecesParMetreCarre::ESPACEMENT_MAX, 'decimales' => 1, 'defaut' => PiecesParMetreCarre::ESPACEMENT_DEFAUT],
                ],
                'motsCles' => ['Autocollants, étiquettes · pièces au m²', 'Espacement 1 mm en X et en Y', 'Quantité en pièces ou en m²'],
                'note' => 'Sur une ligne au m², la case « Pièces au m² » compte les pièces qui tiennent sur 1 m × 1 m avec cet espacement (la pièce tournée est essayée aussi) et facture pièces ÷ pièces au m², au centième de m² supérieur. Une pièce plus grande que 1 m reste au calcul au m² habituel.',
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
                    // Ancien barème (avant la date de début de la tuile « Points du pointage ») : figé.
                    ['cle' => 'points_heure', 'type' => 'nombre', 'libelle' => 'Ancien barème · à l\'heure', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10, 'fige' => true],
                    ['cle' => 'points_retard', 'type' => 'nombre', 'libelle' => 'Ancien barème · retard', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10, 'fige' => true],
                    ['cle' => 'points_soiree', 'type' => 'nombre', 'libelle' => 'Ancien barème · 1 h après la fin', 'suffixe' => 'pts', 'min' => 0, 'max' => 500, 'defaut' => 30, 'fige' => true],
                    ['cle' => 'points_nuit', 'type' => 'nombre', 'libelle' => 'Ancien barème · nuit au bureau', 'suffixe' => 'pts', 'min' => 0, 'max' => 1000, 'defaut' => 100, 'fige' => true],
                    ['cle' => 'points_oubli', 'type' => 'nombre', 'libelle' => 'Ancien barème · oubli', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 20, 'fige' => true],
                ],
                'motsCles' => ['Pointage QR + photo', 'Retards · tolérance', 'Affiche QR à imprimer'],
                'note' => 'Les points de chaque journée se règlent dans la tuile « Points du pointage ».',
            ],
            'points_pointage' => [
                'famille' => 'personnel', 'libelle' => 'Points du pointage', 'icone' => 'fa-user-clock',
                'roles' => ['ROLE_ADMIN', 'ROLE_RH'], 'route' => 'app_pointage_jour',
                'champs' => [
                    ['cle' => 'accueil_debut', 'type' => 'heure', 'libelle' => 'Accueil et caisse · début', 'aide' => 'Assistantes, caissières', 'defaut' => '09:00'],
                    ['cle' => 'accueil_fin', 'type' => 'heure', 'libelle' => 'Accueil et caisse · fin', 'defaut' => '17:00'],
                    ['cle' => 'atelier_debut', 'type' => 'heure', 'libelle' => 'Atelier et autres · début', 'defaut' => '09:00'],
                    ['cle' => 'atelier_fin', 'type' => 'heure', 'libelle' => 'Atelier et autres · fin', 'defaut' => '18:00'],
                    ['cle' => 'points_heures_faites', 'type' => 'nombre', 'libelle' => 'Heures faites (à l\'heure ou retard rattrapé)', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 10],
                    ['cle' => 'points_retard', 'type' => 'nombre', 'libelle' => 'Retirés : retard et heures pas faites', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 15],
                    ['cle' => 'points_heure_en_plus', 'type' => 'nombre', 'libelle' => 'Chaque heure entière en plus', 'suffixe' => 'pts', 'min' => 0, 'max' => 100, 'defaut' => 5],
                    ['cle' => 'longue_heures', 'type' => 'nombre', 'libelle' => 'Longue journée (garde) à partir de', 'suffixe' => 'h', 'min' => 12, 'max' => 26, 'defaut' => 20],
                    ['cle' => 'points_longue', 'type' => 'nombre', 'libelle' => 'Points d\'une longue journée', 'suffixe' => 'pts', 'min' => 0, 'max' => 1000, 'defaut' => 50],
                    ['cle' => 'pause_deduite', 'type' => 'interrupteur', 'libelle' => 'Retirer la pause des heures', 'aide' => 'Non : 9 h – 18 h font 9 h. Oui : 8 h (pause de la tuile Pointage).', 'defaut' => false],
                    ['cle' => 'valider_en_plus', 'type' => 'interrupteur', 'libelle' => 'Heures en plus validées par la RH', 'aide' => 'Les points des heures en plus et des longues journées comptent après validation.', 'defaut' => true],
                    ['cle' => 'debut', 'type' => 'date', 'libelle' => 'Nouveau calcul à partir du', 'aide' => 'Les journées d\'avant gardent l\'ancien barème', 'defaut' => null, 'requis' => true],
                ],
                'motsCles' => ['Points de ponctualité', 'Horaires accueil et caisse · atelier et autres', 'Heures faites · retard · heures en plus', 'Garde de 24 h · longue journée'],
                'note' => 'Chaque employé a l\'horaire de son poste, modifiable sur sa fiche ; des horaires propres (gardien de nuit) passent avant. Une longue journée remplace le calcul du jour. La tolérance de retard (tuile Pointage) sert aussi de marge sur les heures dues. Exemples : docs/pointage.md, « Calcul des points ».',
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
            'primes' => [
                'famille' => 'personnel', 'libelle' => 'Primes de performance', 'icone' => 'fa-trophy',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_bareme_primes',
                'champs' => [
                    ['cle' => 'appreciation_obligatoire', 'type' => 'interrupteur', 'libelle' => 'Appréciation du responsable obligatoire', 'aide' => 'Avant de valider l\'évaluation du mois', 'defaut' => true],
                    ['cle' => 'agent_voit_note', 'type' => 'interrupteur', 'libelle' => 'L\'agent voit sa note en temps réel', 'aide' => 'Mon espace › Ma performance ; sinon seulement les mois validés', 'defaut' => true],
                ],
                'motsCles' => ['Barème des primes par rôle', 'Score sur 100 · paliers 90 / 80 / 70 / 60', 'Évaluation du mois · appréciation du responsable', 'Prime cible par rôle et par personne'],
                'note' => 'Les critères, poids et primes cibles de chaque rôle se règlent dans le barème (bouton ci-dessous). Le responsable d\'un employé (fiche employé) évalue son équipe ; la direction valide les primes avant la paie.',
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
                    // Suivi de commande (docs/espace-client.md) : au-delà, la demande part avec « peut être facturée ».
                    ['cle' => 'corrections_bat_gratuites', 'type' => 'nombre', 'libelle' => 'Corrections de BAT gratuites', 'aide' => 'Par BAT. Au-delà, le client peut encore demander une correction : la page le prévient qu\'elle peut être facturée, l\'atelier fixe le prix.', 'suffixe' => 'corrections', 'min' => 0, 'max' => 10, 'defaut' => 2],
                    ['cle' => 'code_sms', 'type' => 'interrupteur', 'libelle' => 'Code de première connexion et de mot de passe oublié', 'aide' => 'Par e-mail, ou par SMS sans adresse. Coupé, le client demande son accès à la boutique ; la création de compte vérifie toujours par code.', 'defaut' => true],
                    // Appliqués par le serveur (NouvelleCommande::estOuverte, OrangeMoney::estOuvert).
                    ['cle' => 'commande_en_ligne', 'type' => 'interrupteur', 'libelle' => 'Commande en ligne', 'aide' => 'Coupé : l\'espace client n\'enregistre plus de commande (nouvelle commande, Mes produits, Atelier 3D, impression d\'une conception) et l\'explique au client. Les commandes déjà passées continuent.', 'defaut' => true],
                    ['cle' => 'paiement_en_ligne', 'type' => 'interrupteur', 'libelle' => 'Paiement Orange Money en ligne', 'aide' => 'Coupé : l\'espace ne propose pas Orange Money, le client paie en boutique (ou au numéro marchand). Allumé : il faut aussi les clés d\'Orange sur le serveur en ligne.', 'defaut' => true],
                    // Délais de la commande en ligne (docs/espace-client.md, « Nouvelle commande ») : comptés en jours ouvrés
                    // (tuile Horaires) à partir de la validation du BAT.
                    ['cle' => 'delai_standard', 'type' => 'nombre', 'libelle' => 'Délai standard', 'aide' => 'Après validation du BAT. Compté en jours ouvrés (24 h = 1 jour).', 'suffixe' => 'h', 'min' => 1, 'max' => 720, 'defaut' => DelaisCommande::STANDARD_DEFAUT],
                    ['cle' => 'express', 'type' => 'interrupteur', 'libelle' => 'Délai express proposé', 'defaut' => true],
                    ['cle' => 'delai_express', 'type' => 'nombre', 'libelle' => 'Délai express', 'aide' => 'Après validation du BAT.', 'suffixe' => 'h', 'min' => 1, 'max' => 720, 'defaut' => DelaisCommande::EXPRESS_DEFAUT],
                    ['cle' => 'majoration_express', 'type' => 'nombre', 'libelle' => 'Majoration express', 'aide' => 'Ajoutée en ligne « Express » sur la commande, sur le prix des produits.', 'suffixe' => '%', 'min' => 0, 'max' => 200, 'defaut' => DelaisCommande::MAJORATION_DEFAUT],
                    ['cle' => 'heure_limite', 'type' => 'heure', 'libelle' => 'BAT validé avant', 'aide' => 'Le délai part le jour même ; après, le jour ouvré suivant.', 'defaut' => DelaisCommande::HEURE_LIMITE_DEFAUT],
                ],
                'motsCles' => ['Connexion par code e-mail ou SMS', 'Mot de passe oublié', 'Commande en ligne du client', 'Paiement Orange Money en ligne', 'Délai standard · express 24 h', 'WhatsApp · mot de passe', 'Corrections de BAT gratuites'],
                'note' => 'Tout est appliqué par le serveur. Commande en ligne coupée : la page Nouvelle commande l\'explique et rien ne s\'enregistre. Paiement Orange Money en ligne coupé (réglage de départ) : aucun paiement Orange Money depuis l\'espace, ni à la commande, ni dans Factures et paiements, ni pour la conception.',
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
            // Crédits SMS et e-mail (docs/messagerie-marketing.md), demande de Yaya du 16/10/2026.
            // Les champs « ecran » se saisissent sur Clients › Crédits SMS et e-mail.
            'credits_messages' => [
                'famille' => 'clients', 'libelle' => 'Crédits SMS et e-mail', 'icone' => 'fa-sms',
                'roles' => ['ROLE_ADMIN', 'ROLE_CREDITS_MESSAGES'], 'route' => 'app_credits_messages_index',
                'champs' => [
                    ['cle' => 'seuil_sms', 'type' => 'nombre', 'libelle' => 'Prévenir quand il reste moins de', 'aide' => 'Notification à la direction, une fois, quand le solde passe sous ce nombre', 'suffixe' => 'SMS', 'min' => 0, 'max' => 1000000, 'defaut' => 100],
                    ['cle' => 'alerte_jours', 'type' => 'nombre', 'libelle' => 'Prévenir avant l\'expiration', 'aide' => 'Pack SMS et abonnement de la messagerie e-mail', 'suffixe' => 'jours', 'min' => 1, 'max' => 90, 'defaut' => 15],
                    ['cle' => 'email_hebergeur', 'type' => 'texte', 'libelle' => 'Hébergeur de la messagerie e-mail', 'aide' => 'Par exemple LWS : là où se voient l\'abonnement et les limites d\'envoi', 'longueur' => 60, 'defaut' => ''],
                    ['cle' => 'email_quota_heure', 'type' => 'nombre', 'libelle' => 'E-mails permis par heure', 'aide' => 'Limite de l\'hébergeur. 0 : pas de limite connue', 'suffixe' => 'e-mails', 'min' => 0, 'max' => 10000000, 'defaut' => 0],
                    ['cle' => 'email_quota_jour', 'type' => 'nombre', 'libelle' => 'E-mails permis par jour', 'aide' => '0 : pas de limite connue', 'suffixe' => 'e-mails', 'min' => 0, 'max' => 10000000, 'defaut' => 0],
                    ['cle' => 'email_quota_mois', 'type' => 'nombre', 'libelle' => 'E-mails permis par mois', 'aide' => '0 : pas de limite connue', 'suffixe' => 'e-mails', 'min' => 0, 'max' => 100000000, 'defaut' => 0],
                    ['cle' => 'email_expiration', 'type' => 'date', 'libelle' => 'Abonnement e-mail : fin', 'defaut' => '', 'ecran' => true],
                    ['cle' => 'sms_solde_manuel', 'type' => 'nombre', 'libelle' => 'SMS restants (saisis à la main)', 'suffixe' => 'SMS', 'min' => 0, 'max' => 100000000, 'defaut' => 0, 'ecran' => true],
                    ['cle' => 'sms_expiration_manuelle', 'type' => 'date', 'libelle' => 'Pack SMS : fin (saisie à la main)', 'defaut' => '', 'ecran' => true],
                    ['cle' => 'sms_saisi_le', 'type' => 'texte', 'libelle' => 'Solde SMS saisi le', 'defaut' => '', 'ecran' => true],
                ],
                'motsCles' => ['SMS disponibles · date d\'expiration du pack', 'Orange SMS · solde et achats de packs', 'Historique des envois SMS et e-mail', 'Limites d\'envoi de l\'hébergeur · LWS', 'Alerte crédit SMS bas'],
                'note' => 'Le solde SMS est lu chez Orange quand les clés sont posées ; sans les clés, il se saisit à la main sur la page complète, avec la fin de l\'abonnement e-mail.',
            ],
            // Règles de réponse aux messages des clients (maquette MS04, docs/messagerie-clients.md).
            // Le délai de 1re réponse et la durée de réouverture partent au site (texte « Réponse en général sous 30 min »).
            'messagerie' => [
                'famille' => 'clients', 'libelle' => 'Règles de réponse aux messages', 'icone' => 'fa-comments',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_messages_clients_regles',
                'champs' => [
                    ['cle' => 'premiere_minutes', 'type' => 'nombre', 'libelle' => '1re réponse', 'aide' => 'Minutes ouvrées après le premier message du client', 'suffixe' => 'min ouvrées', 'min' => 5, 'max' => 1440, 'defaut' => 30],
                    ['cle' => 'suivantes_minutes', 'type' => 'nombre', 'libelle' => 'Réponses suivantes', 'suffixe' => 'min ouvrées', 'min' => 5, 'max' => 2880, 'defaut' => 60],
                    ['cle' => 'tresoriere', 'type' => 'nombre', 'libelle' => 'Paiement, facture : trésorière (compte)', 'aide' => 'Numéro du compte choisi sur l\'écran Règles de réponse ; 0 : la caisse', 'min' => 0, 'max' => 99999999, 'defaut' => 0, 'ecran' => true],
                    ['cle' => 'escalade_minutes', 'type' => 'nombre', 'libelle' => 'Escalade à la direction après', 'aide' => 'Attente du client en minutes ouvrées ; 0 : jamais', 'suffixe' => 'min ouvrées', 'min' => 0, 'max' => 2880, 'defaut' => 120],
                    ['cle' => 'penalite_points', 'type' => 'nombre', 'libelle' => 'Pénalité par réponse en retard', 'aide' => 'Retirée de la note « Réponses aux messages » de la prime', 'suffixe' => 'pts', 'min' => 0, 'max' => 50, 'defaut' => 5],
                    ['cle' => 'cloture_auto_heures', 'type' => 'nombre', 'libelle' => 'Clôture automatique sans réponse du client', 'aide' => '0 : jamais', 'suffixe' => 'h', 'min' => 0, 'max' => 720, 'defaut' => 72],
                    ['cle' => 'reouverture_jours', 'type' => 'nombre', 'libelle' => 'Le client peut rouvrir pendant', 'suffixe' => 'jours', 'min' => 0, 'max' => 60, 'defaut' => 7],
                ],
                'motsCles' => ['Délai de réponse aux clients · 30 min', 'Retard imputé à l\'agent assigné', 'Clôture automatique des conversations', 'Pénalité de points par réponse en retard'],
                'note' => 'Le chronomètre ne tourne que pendant les heures ouvrées (tuile Horaires, pause comprise) ; il s\'arrête à la première réponse envoyée, les notes internes ne comptent pas.',
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
                    // Repère de la fiche Google de la boutique (lien envoyé par Yaya le 15/10/2026).
                    ['cle' => 'lien_plan', 'type' => 'texte', 'libelle' => 'Position de la boutique (carte et Itinéraire)', 'aide' => 'Coordonnées « latitude, longitude » pour la carte de la page Contact : dans Google Maps, appui long sur la boutique (téléphone) ou clic droit (ordinateur) affiche « 12.6517571, -7.9299376 » ; copiez ces deux nombres ici. Un lien court (https://maps.app.goo.gl/…) donne seulement le bouton Itinéraire, sans carte. Vide : ni carte ni bouton.', 'defaut' => '12.6517571, -7.9299376'],
                ],
                'motsCles' => ['Carte de la boutique · bouton Itinéraire', 'Lien Google Maps · coordonnées GPS', 'Contact du site vitrine'],
                'note' => 'Les horaires de la page viennent de la tuile Horaires ; l\'adresse, le téléphone et l\'e-mail, de l\'entité Succès Imprim (Entités et en-têtes).',
            ],
            'site_vitrine' => [
                'famille' => 'clients', 'libelle' => 'Site vitrine', 'icone' => 'fa-globe',
                'roles' => ['ROLE_ADMIN', 'ROLE_SITE_VITRINE'], 'route' => 'app_site_contenu_index', 'champs' => [],
                'motsCles' => ['Pages du site · équipements, guide, à propos, engagements, FAQ', 'Réalisations et photos', 'Avis clients · conseils', 'Partenaires · chiffres du site', 'Équipe · photos, noms et postes · mot du gérant'],
            ],
            // Assistant du site et de l'espace client (docs/assistant-client.md), périmètre validé par Yaya le 10/10/2026.
            'assistant' => [
                'famille' => 'clients', 'libelle' => 'Assistant du site', 'icone' => 'fa-comments',
                'roles' => ['ROLE_ADMIN', 'ROLE_ASSISTANT'], 'route' => 'app_assistant_conversations',
                'champs' => [
                    ['cle' => 'actif', 'type' => 'interrupteur', 'libelle' => 'Assistant sur le site et l\'espace client', 'aide' => 'Caché tant que la clé de l\'API n\'est pas posée sur le serveur en ligne.', 'defaut' => false],
                    ['cle' => 'message_accueil', 'type' => 'texte', 'libelle' => 'Message d\'accueil', 'defaut' => 'Bonjour ! Posez votre question : produits, prix, horaires ou suivi de vos commandes.'],
                    ['cle' => 'limite_jour', 'type' => 'nombre', 'libelle' => 'Plafond de dépense par jour', 'aide' => 'Atteinte, l\'assistant renvoie vers WhatsApp jusqu\'à minuit.', 'suffixe' => 'F', 'min' => 0, 'max' => 1000000, 'defaut' => 5000],
                    ['cle' => 'whatsapp', 'type' => 'texte', 'libelle' => 'WhatsApp des conseillers', 'aide' => 'Vide : le téléphone de l\'entité Succès Imprim.', 'defaut' => ''],
                    ['cle' => 'delais', 'type' => 'texte', 'libelle' => 'Délais à annoncer', 'aide' => 'Vide : « annoncé à la validation du BAT ». L\'assistant ne promet rien d\'autre.', 'defaut' => ''],
                    ['cle' => 'modele', 'type' => 'choix', 'libelle' => 'Modèle', 'aide' => 'Vaut pour les nouvelles conversations. ASSISTANT_MODELE sur le serveur l\'emporte.', 'options' => ['claude-opus-5-5' => 'Opus 5.5 (conseillé)', 'claude-sonnet-5-5' => 'Sonnet 5.5', 'claude-haiku-5-5' => 'Haiku 5.5'], 'defaut' => 'claude-opus-5-5'],
                    ['cle' => 'effort', 'type' => 'choix', 'libelle' => 'Effort de réflexion', 'options' => ['low' => 'Faible (conseillé)', 'medium' => 'Moyen', 'high' => 'Élevé'], 'defaut' => 'low'],
                    ['cle' => 'taux_usd', 'type' => 'nombre', 'libelle' => 'Taux du dollar', 'suffixe' => 'F pour 1 $', 'min' => 1, 'max' => 5000, 'defaut' => 600],
                    ['cle' => 'prix_entree', 'type' => 'nombre', 'libelle' => 'Tarif : entrée', 'aide' => 'En dollars par million de jetons. Opus 5.5 : 4 / 20 / 0,20 / 5 ; Sonnet 5.5 : 2 / 10 / 0,20 / 2,50 ; Haiku 5.5 : 0,10 / 0,50 / 0,01 / 0,125.', 'suffixe' => '$ / M', 'min' => 0, 'max' => 1000, 'decimales' => 3, 'defaut' => 4],
                    ['cle' => 'prix_sortie', 'type' => 'nombre', 'libelle' => 'Tarif : sortie', 'suffixe' => '$ / M', 'min' => 0, 'max' => 1000, 'decimales' => 3, 'defaut' => 20],
                    ['cle' => 'prix_cache_lecture', 'type' => 'nombre', 'libelle' => 'Tarif : lecture du cache', 'suffixe' => '$ / M', 'min' => 0, 'max' => 1000, 'decimales' => 3, 'defaut' => 0.2],
                    ['cle' => 'prix_cache_ecriture', 'type' => 'nombre', 'libelle' => 'Tarif : écriture du cache', 'suffixe' => '$ / M', 'min' => 0, 'max' => 1000, 'decimales' => 3, 'defaut' => 5],
                ],
                'motsCles' => ['Assistant du site · questions des clients', 'Plafond de dépense par jour', 'Passage à un conseiller sur WhatsApp', 'Modèle Claude · tarif · taux du dollar'],
                'note' => 'Plafond atteint : l\'assistant renvoie vers WhatsApp jusqu\'à minuit (0 l\'arrête). WhatsApp vide : le téléphone de l\'entité ; délais vides : « annoncés à la validation du BAT ». Tarifs en dollars par million de jetons, ceux du modèle choisi (Opus 5.5 : 4 / 20 / 0,20 / 5 ; Sonnet 5.5 : 2 / 10 / 0,20 / 2,50 ; Haiku 5.5 : 0,10 / 0,50 / 0,01 / 0,125). Conversations et analyse : Clients › Assistant du site, avec le droit « Assistant du site ». La clé de l\'API se pose dans .env.local du serveur en ligne (docs/deploiement.md).',
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
            // Corbeille (docs/corbeille.md) : la même durée vaut sur le site en ligne (CatalogueSynchro::REGLAGES_PUBLICS).
            'corbeille' => [
                'famille' => 'systeme', 'libelle' => 'Corbeille', 'icone' => 'fa-trash-restore',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_corbeille_index',
                'champs' => [
                    ['cle' => 'duree', 'type' => 'nombre', 'libelle' => 'Garder les fiches supprimées pendant', 'aide' => 'Puis elles sont supprimées pour de bon, avec leurs fichiers', 'suffixe' => 'jours', 'min' => 1, 'max' => 365, 'defaut' => 30],
                ],
                'motsCles' => ['Fiches supprimées', 'Restaurer une suppression', 'Suppression définitive', 'Annuler une erreur'],
            ],
            'taches' => [
                'famille' => 'systeme', 'libelle' => 'Tâches récurrentes', 'icone' => 'fa-calendar-alt',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_decaissement_recurrent_index', 'champs' => [],
                'motsCles' => ['Dépenses récurrentes · loyer, taxes', 'Fournisseurs à payer chaque mois'],
            ],
            'import' => [
                'famille' => 'systeme', 'libelle' => 'Import des données', 'icone' => 'fa-file-import',
                'roles' => ['ROLE_ADMIN'], 'route' => 'app_import_index', 'champs' => [],
                'motsCles' => ['Clients, articles et soldes de démarrage', 'Fichier Excel ou CSV', 'Soldes d\'ouverture · à-nouveaux', 'Journal des imports'],
                'note' => 'Sur l\'ERP local seulement. Rien n\'est enregistré avant la confirmation de l\'aperçu ; aucun message n\'est envoyé aux clients importés (docs/import-donnees.md).',
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

                case 'date':
                    // Vide : pas de date, sauf champ « requis ». Rangée AAAA-MM-JJ (champ date du navigateur ou JJ/MM/AAAA tapé).
                    $texte = trim((string) $brut);
                    $date = null;
                    foreach (['Y-m-d', 'd/m/Y'] as $format) {
                        $lue = \DateTimeImmutable::createFromFormat('!'.$format, $texte);
                        // Pas de 31/02 ramené au 3 mars : la date relue doit être celle tapée.
                        if (false !== $lue && $lue->format($format) === $texte) {
                            $date = $lue;
                        }
                    }
                    if (('' !== $texte || !empty($champ['requis'])) && (null === $date || (int) $date->format('Y') < 2000 || (int) $date->format('Y') > 2100)) {
                        $erreurs[$cle] = 'Date attendue (JJ/MM/AAAA).';
                        break;
                    }
                    $valeurs[$cle] = null === $date ? '' : $date->format('Y-m-d');
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
                    $longueur = $champ['longueur'] ?? 120;
                    if (mb_strlen($texte) > $longueur) {
                        $erreurs[$cle] = $longueur.' caractères au plus.';
                        break;
                    }
                    // Liste de montants (paliers des frais de livraison) : contrôlée, puis rangée.
                    if (($champ['format'] ?? null) === 'montants') {
                        $paliers = ReglagesSaisie::lirePaliers($texte);
                        if ($paliers === null) {
                            $erreurs[$cle] = \sprintf('Montants entiers de 1 à %s F, %d au plus, séparés par des virgules (ex. 1000, 1500, 2000).', number_format(ReglagesSaisie::PALIER_MAX, 0, ',', ' '), ReglagesSaisie::PALIERS_NOMBRE_MAX);
                            break;
                        }
                        $texte = ReglagesSaisie::ecrirePaliers($paliers);
                    }
                    $valeurs[$cle] = $texte;
            }
        }

        // Montant à choisir parmi une liste de paliers saisie dans la même tuile (frais de livraison en ligne) : 0 ou un palier.
        foreach ($champs as $champ) {
            $cle = $champ['cle'];
            $liste = $champ['palierDe'] ?? null;
            if (null === $liste || !\is_int($valeurs[$cle] ?? null) || !\array_key_exists($liste, $valeurs)) {
                continue;
            }
            $paliers = ReglagesSaisie::lirePaliers((string) $valeurs[$liste]) ?? [];
            if (!ReglagesSaisie::fraisPermis($valeurs[$cle], $paliers)) {
                $erreurs[$cle] = [] === $paliers ? 'Sans palier, seulement 0.' : \sprintf('0 ou un des paliers : %s.', implode(', ', array_map(ReglagesSaisie::montant(...), $paliers)));
                unset($valeurs[$cle]);
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
            'date' => '' === (string) $valeur ? 'aucune' : implode('/', array_reverse(explode('-', (string) $valeur))),
            default => (string) $valeur,
        };
    }

    /**
     * Champs du panneau d'édition rapide : sans ceux modifiés dans l'écran
     * dédié de la tuile (marqués « ecran ») ni ceux figés (« fige » :
     * ancien barème, gardé pour recalculer les journées d'avant).
     *
     * @return list<array<string, mixed>>
     */
    public static function champsPanneau(string $tuile): array
    {
        return array_values(array_filter(self::tuiles()[$tuile]['champs'] ?? [], static fn (array $c): bool => empty($c['ecran']) && empty($c['fige'])));
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
