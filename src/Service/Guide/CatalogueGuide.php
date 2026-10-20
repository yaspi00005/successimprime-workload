<?php

namespace App\Service\Guide;

use App\Security\LivreurSeul;
use App\Security\ModulesRoles;
use App\Security\PersonnelVoter;

/**
 * Guide utilisateur dans l'ERP local (docs/guide-utilisateur.md) : des
 * fiches courtes, une tâche par fiche, rangées par rôle.
 *
 * Chaque fiche a :
 * - « droits » : ceux de l'écran qu'elle décrit et de ce que montrent ses
 *   captures. Chaque entrée est exigée ; une entrée en liste veut dire
 *   « l'un de ces droits suffit ». Une fiche ne s'affiche, ne s'imprime
 *   et ne montre ses captures qu'à qui a ces droits ;
 * - « route » : l'écran décrit (bouton « Ouvrir l'écran ») ;
 * - « ecrans » : débuts de nom de route où le bouton « ? Aide » la propose
 *   en premier (par défaut, la route) ;
 * - « captures » : images de ressources/guide/captures/<nom>.webp, prises
 *   sur les données de démonstration par tools/guide/captures.mjs ;
 * - « consultation » : la fiche ne fait que regarder ; elle reste visible
 *   quand le module de l'écran est en « Voir » pour le rôle. Les autres
 *   fiches (créer, modifier, encaisser) disparaissent dans ce cas.
 *
 * Le texte de chaque fiche est dans templates/aide/fiches/<cle>.html.twig.
 * Tout est statique et pur pour être testé sans base.
 */
final class CatalogueGuide
{
    /** Droit d'une fiche du personnel : avoir une fiche employé (PersonnelVoter). */
    public const PERSONNEL = PersonnelVoter::PERSONNEL;

    /**
     * « droit » : le droit qui fait entrer le groupe dans « Imprimer mon
     * guide » quand il est ajouté sur la fiche de la personne (par exemple
     * « Encaissements » pour un graphiste).
     */
    public const GROUPES = [
        'premiers-pas' => ['libelle' => 'Premiers pas', 'icone' => 'fas fa-shoe-prints', 'texte' => 'Le parcours du premier jour : se connecter, le client, le devis, la commande, l\'encaissement, la facture.'],
        'direction' => ['libelle' => 'Direction', 'icone' => 'fas fa-chess-king', 'texte' => 'Suivre l\'activité, valider, gérer les accès et régler l\'application.', 'droit' => 'ROLE_ADMIN'],
        'caisse' => ['libelle' => 'Caisse et commercial', 'icone' => 'fas fa-cash-register', 'texte' => 'Le quotidien de l\'imprimerie : ventes, caisse, dépenses, relances, clients.', 'droit' => 'ROLE_PAIEMENT_ENCAISSER'],
        'graphiste' => ['libelle' => 'Graphiste PAO', 'icone' => 'fas fa-pencil-ruler', 'texte' => 'Fichiers des clients, BAT, montage, demandes de conception, créations.', 'droit' => 'ROLE_PREPRESSE'],
        'production' => ['libelle' => 'Opérateur production', 'icone' => 'fas fa-print', 'texte' => 'Les travaux de l\'atelier et la file de sa machine.', 'droit' => 'ROLE_PRODUCTION_GERER'],
        'livreur' => ['libelle' => 'Livreur', 'icone' => 'fas fa-route', 'texte' => 'La tournée du jour sur le téléphone.', 'droit' => 'ROLE_LIVRAISON'],
        'entretien' => ['libelle' => 'Entretien', 'icone' => 'fas fa-broom', 'texte' => 'Le planning d\'entretien des locaux, validé par photo.', 'droit' => 'ROLE_ENTRETIEN'],
        'mon-espace' => ['libelle' => 'Mon espace', 'icone' => 'fas fa-id-badge', 'texte' => 'Pour tout le personnel : pointage, absences, prime, statistiques.'],
    ];

    /** Groupe principal de chaque rôle de base (code du rôle), pour « Imprimer mon guide ». */
    public const GROUPE_DU_ROLE = [
        'ROLE_ADMIN' => 'direction',
        'ROLE_CAISSE_COMMANDE' => 'caisse',
        'ROLE_GRAPHISTE' => 'graphiste',
        'ROLE_PRODUCTION' => 'production',
        'ROLE_LIVREUR' => 'livreur',
    ];

    /**
     * @return list<array{cle: string, groupe: string, titre: string, but: string, droits: list<string|list<string>>, route: string, ecrans?: list<string>, captures: list<array{nom: string, legende: string, mobile?: bool}>, consultation?: bool}>
     */
    public static function fiches(): array
    {
        return [
            // ---------- Premiers pas ----------
            [
                'cle' => 'se-connecter', 'groupe' => 'premiers-pas', 'titre' => 'Se connecter et trouver l\'aide',
                'but' => 'Ouvrir sa session, retrouver ses écrans et ce guide.',
                'droits' => ['ROLE_USER'], 'route' => 'app_home', 'ecrans' => ['app_home', 'app_aide_'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'connexion', 'legende' => 'La page de connexion sur le téléphone.', 'mobile' => true],
                    ['nom' => 'aide-index', 'legende' => 'Le guide : « ? Aide » en haut de chaque écran.'],
                ],
            ],
            [
                'cle' => 'client-trouver-creer', 'groupe' => 'premiers-pas', 'titre' => 'Trouver ou créer un client',
                'but' => 'Retrouver le client par son nom ou son téléphone, sinon créer sa fiche.',
                'droits' => ['ROLE_CLIENT'], 'route' => 'app_clients_index', 'ecrans' => ['app_clients_index', 'app_clients_nouveau'],
                'captures' => [
                    ['nom' => 'clients-liste', 'legende' => 'Clients : la recherche et « Nouveau client ».'],
                    ['nom' => 'client-nouveau', 'legende' => 'Nouveau client, type Particulier.'],
                ],
            ],
            [
                'cle' => 'devis-faire', 'groupe' => 'premiers-pas', 'titre' => 'Faire un devis',
                'but' => 'Chiffrer la demande du client et lui remettre le devis.',
                'droits' => ['ROLE_DEVIS'], 'route' => 'app_devis_new', 'ecrans' => ['app_devis_new', 'app_devis_edit'],
                'captures' => [
                    ['nom' => 'devis-saisie', 'legende' => 'Nouveau devis : une bâche avec œillets.'],
                ],
            ],
            [
                'cle' => 'devis-en-commande', 'groupe' => 'premiers-pas', 'titre' => 'Transformer le devis en commande',
                'but' => 'Quand le client accepte, créer la commande qui part à l\'atelier.',
                'droits' => ['ROLE_DEVIS', 'ROLE_COMMANDE'], 'route' => 'app_devis_index', 'ecrans' => ['app_devis_show', 'app_devis_index', 'app_commandes_show'],
                'captures' => [
                    ['nom' => 'devis-accepte', 'legende' => 'Devis accepté : « Valider et créer la commande ».'],
                    ['nom' => 'commande-fiche', 'legende' => 'La commande créée et ses étapes.'],
                ],
            ],
            [
                'cle' => 'encaisser', 'groupe' => 'premiers-pas', 'titre' => 'Encaisser un paiement',
                'but' => 'Enregistrer l\'argent reçu et donner le reçu au client.',
                'droits' => ['ROLE_PAIEMENT_ENCAISSER'], 'route' => 'app_caisse_index', 'ecrans' => ['app_caisse_', 'app_commandes_paiement'],
                'captures' => [
                    ['nom' => 'caisse-encaissement', 'legende' => 'Caisse : client, commande, montant, mode, compte.'],
                    ['nom' => 'caisse-recu', 'legende' => 'Après l\'encaissement : imprimer ou envoyer le reçu.'],
                ],
            ],
            [
                'cle' => 'facturer-envoyer', 'groupe' => 'premiers-pas', 'titre' => 'Facturer, imprimer, envoyer',
                'but' => 'Sortir la facture de la commande, l\'imprimer ou l\'envoyer au client.',
                'droits' => ['ROLE_FACTURE', 'ROLE_COMMANDE'], 'route' => 'app_factures_index', 'ecrans' => ['app_factures_'],
                'captures' => [
                    ['nom' => 'facture-fiche', 'legende' => 'La facture enregistrée et ses boutons.'],
                ],
            ],

            // ---------- Direction ----------
            [
                'cle' => 'tableau-de-bord', 'groupe' => 'direction', 'titre' => 'Lire le tableau de bord',
                'but' => 'Voir en une page le mois, l\'atelier et ce qui attend une décision.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_home', 'consultation' => true,
                'captures' => [
                    ['nom' => 'tableau-de-bord', 'legende' => 'Le tableau de bord de la Direction.'],
                ],
            ],
            [
                'cle' => 'statistiques-activite', 'groupe' => 'direction', 'titre' => 'Statistiques : imprimerie et hors imprimerie',
                'but' => 'Juger l\'activité d\'impression sans les ventes de matériel, puis voir le total.',
                'droits' => ['ROLE_STATS_GLOBAL'], 'route' => 'app_statistiques_index', 'ecrans' => ['app_statistiques_'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'statistiques-activite', 'legende' => 'Statistiques et rapports : la carte « Imprimerie / Hors imprimerie / Total ».'],
                ],
            ],
            [
                'cle' => 'a-valider', 'groupe' => 'direction', 'titre' => 'Valider les opérations « À valider »',
                'but' => 'Contrôler puis valider ou rejeter les paiements et mouvements en attente.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_a_valider_index', 'ecrans' => ['app_a_valider_'],
                'captures' => [
                    ['nom' => 'a-valider', 'legende' => 'Finances › À valider.'],
                ],
            ],
            [
                'cle' => 'utilisateur-nouveau', 'groupe' => 'direction', 'titre' => 'Donner un accès à un employé',
                'but' => 'Créer l\'utilisateur, choisir son rôle et lui envoyer son accès.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_utilisateurs_index', 'ecrans' => ['app_utilisateurs_index', 'app_utilisateurs_nouveau', 'app_utilisateurs_roles'],
                'captures' => [
                    ['nom' => 'utilisateurs', 'legende' => 'Réglages › Utilisateurs.'],
                    ['nom' => 'utilisateur-nouveau', 'legende' => 'Nouvel utilisateur.'],
                ],
            ],
            [
                'cle' => 'droit-une-personne', 'groupe' => 'direction', 'titre' => 'Ajouter ou retirer un droit à une personne',
                'but' => 'Donner une case en plus (ou en moins) à une seule personne, sans changer son rôle.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_utilisateurs_index', 'ecrans' => ['app_utilisateurs_fiche'],
                'captures' => [
                    ['nom' => 'utilisateur-droits', 'legende' => 'La fiche d\'un utilisateur : les droits cochés.'],
                ],
            ],
            [
                'cle' => 'parametres', 'groupe' => 'direction', 'titre' => 'Changer un réglage',
                'but' => 'Trouver un réglage par la recherche et le modifier.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_reglages_parametres_index', 'ecrans' => ['app_reglages_parametres_'],
                'captures' => [
                    ['nom' => 'parametres', 'legende' => 'Paramètres : les tuiles par famille et la recherche.'],
                ],
            ],
            [
                'cle' => 'finances', 'groupe' => 'direction', 'titre' => 'Vue d\'ensemble des finances',
                'but' => 'Suivre les soldes, le facturé, l\'encaissé et les dépenses du mois.',
                // La capture montre les soldes, le chiffre d'affaires, l'encaissé et la marge.
                'droits' => ['ROLE_TRESORERIE_VOIR', 'ROLE_STATS_GLOBAL', 'ROLE_PAIEMENT_VOIR', 'ROLE_COUT_MARGE'],
                'route' => 'app_finances_vue_ensemble', 'ecrans' => ['app_finances_vue_ensemble', 'app_finances_du_jour'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'finances', 'legende' => 'Finances › Vue d\'ensemble.'],
                ],
            ],
            [
                'cle' => 'fournisseurs', 'groupe' => 'direction', 'titre' => 'Suivre les fournisseurs',
                'but' => 'Voir ce qui est dû, ce qui arrive, qui livre à l\'heure et quoi commander bientôt.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_fournisseur_index', 'ecrans' => ['app_fournisseur_'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'fournisseurs', 'legende' => 'Stock et achats › Fournisseurs.'],
                    ['nom' => 'fournisseur-fiche', 'legende' => 'La fiche d\'un fournisseur : conditions, performance, prix.'],
                ],
            ],
            [
                'cle' => 'commande-achat', 'groupe' => 'direction', 'titre' => 'Passer une commande d\'achat',
                'but' => 'Commander la marchandise avec son transport, et connaître son coût rendu à Bamako.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_achat_index', 'ecrans' => ['app_achat_'],
                'captures' => [
                    ['nom' => 'commande-achat', 'legende' => 'Une commande d\'achat préparée depuis une alerte.'],
                ],
            ],
            [
                'cle' => 'reception-achat', 'groupe' => 'direction', 'titre' => 'Réceptionner une commande d\'achat',
                'but' => 'Compter ce qui arrive, réclamer la casse, saisir les frais réels et mettre en stock.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_reception_index', 'ecrans' => ['app_reception_'],
                'captures' => [
                    ['nom' => 'reception-achat', 'legende' => 'La réception : commandé, reçu, bon état, facturé.'],
                    ['nom' => 'reception-reclamation', 'legende' => 'Un rouleau écrasé : réclamation au fournisseur et paiement bloqué.'],
                ],
            ],
            [
                'cle' => 'sauvegardes-synchro', 'groupe' => 'direction', 'titre' => 'Sauvegardes et synchronisation',
                'but' => 'Vérifier que les données sont copiées et que le site suit l\'ERP.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_sauvegardes_index', 'ecrans' => ['app_sauvegardes_', 'app_synchronisation_'],
                'captures' => [
                    ['nom' => 'sauvegardes', 'legende' => 'Réglages › Sécurité › Sauvegardes.'],
                ],
            ],
            [
                'cle' => 'import-donnees', 'groupe' => 'direction', 'titre' => 'Importer les données de départ',
                'but' => 'Charger clients, produits ou soldes depuis un tableau Excel.',
                'droits' => ['ROLE_ADMIN'], 'route' => 'app_import_index', 'ecrans' => ['app_import_'],
                'captures' => [
                    ['nom' => 'import', 'legende' => 'Réglages › Système › Import des données.'],
                ],
            ],
            [
                'cle' => 'credits-messages', 'groupe' => 'direction', 'titre' => 'Crédits SMS et e-mail',
                'but' => 'Savoir combien de SMS il reste, jusqu\'à quand, et ce qui est parti.',
                // L'image s'arrête avant « Derniers envois » : pas de numéro ni d'adresse.
                'droits' => ['ROLE_CREDITS_MESSAGES'], 'route' => 'app_credits_messages_index', 'ecrans' => ['app_credits_messages_'],
                'captures' => [
                    ['nom' => 'credits-messages', 'legende' => 'Clients › Crédits SMS et e-mail.'],
                ],
            ],
            [
                'cle' => 'corbeille', 'groupe' => 'direction', 'titre' => 'Retrouver une fiche supprimée',
                'but' => 'Restaurer ce qui a été supprimé par erreur, tant que c\'est dans la corbeille.',
                'droits' => ['ROLE_CORBEILLE'], 'route' => 'app_corbeille_index', 'ecrans' => ['app_corbeille_'],
                'captures' => [
                    ['nom' => 'corbeille', 'legende' => 'Réglages › Corbeille : ce qui a été supprimé, et quand cela part pour de bon.'],
                ],
            ],

            // ---------- Caisse et commercial ----------
            [
                'cle' => 'ventes-suivre', 'groupe' => 'caisse', 'titre' => 'Suivre les devis et commandes',
                'but' => 'Retrouver une vente et voir où elle en est.',
                'droits' => [['ROLE_COMMANDE', 'ROLE_DEVIS']], 'route' => 'app_ventes_index', 'ecrans' => ['app_ventes_index', 'app_commandes_index', 'app_devis_index'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'ventes', 'legende' => 'Devis et commandes.'],
                ],
            ],
            [
                'cle' => 'commande-directe', 'groupe' => 'caisse', 'titre' => 'Prendre une commande sans devis',
                'but' => 'Saisir directement la commande d\'un client pressé.',
                'droits' => ['ROLE_COMMANDE'], 'route' => 'app_commandes_new', 'ecrans' => ['app_commandes_new'],
                'captures' => [
                    ['nom' => 'commande-nouvelle', 'legende' => 'Nouvelle commande.'],
                ],
            ],
            [
                'cle' => 'hors-imprimerie', 'groupe' => 'caisse', 'titre' => 'Vendre du matériel : « Hors imprimerie »',
                'but' => 'Marquer une vente de tablettes, d\'ordinateurs… pour la compter à part de l\'imprimerie.',
                'droits' => [['ROLE_DEVIS', 'ROLE_COMMANDE']], 'route' => 'app_ventes_index', 'ecrans' => ['app_devis_new', 'app_devis_edit', 'app_commandes_new', 'app_commandes_edit'],
                'captures' => [
                    ['nom' => 'hors-imprimerie', 'legende' => 'La case « Hors imprimerie », sous l\'objet du devis.'],
                ],
            ],
            [
                'cle' => 'commande-en-ligne', 'groupe' => 'caisse', 'titre' => 'Confirmer une commande passée en ligne',
                'but' => 'Vérifier la commande du client dans son espace, puis la confirmer.',
                'droits' => ['ROLE_COMMANDE'], 'route' => 'app_ventes_index', 'ecrans' => ['app_commandes_show', 'app_commandes_edit'],
                'captures' => [
                    ['nom' => 'commande-en-ligne', 'legende' => 'Une commande passée en ligne, à confirmer.'],
                ],
            ],
            [
                'cle' => 'mes-actions', 'groupe' => 'caisse', 'titre' => 'Mes actions de caisse',
                'but' => 'Faire à temps les relances du jour : devis, factures, commandes prêtes.',
                'droits' => ['ROLE_PAIEMENT_ENCAISSER'], 'route' => 'app_actions_index', 'ecrans' => ['app_actions_index', 'app_actions_voir'],
                'captures' => [
                    ['nom' => 'mes-actions', 'legende' => 'Mes actions : à faire, en retard, faites.'],
                ],
            ],
            [
                'cle' => 'caisse-cloture', 'groupe' => 'caisse', 'titre' => 'Clôturer la caisse le soir',
                'but' => 'Compter les espèces et fermer la caisse du jour.',
                'droits' => ['ROLE_JOURNAL_CAISSE', 'ROLE_PAIEMENT_ENCAISSER'], 'route' => 'app_caisse_index', 'ecrans' => ['app_caisse_index'],
                'captures' => [
                    ['nom' => 'caisse-cloture', 'legende' => 'Clôturer la caisse : les espèces comptées.'],
                ],
            ],
            [
                'cle' => 'caisse-virement', 'groupe' => 'caisse', 'titre' => 'Verser la caisse à la banque',
                'but' => 'Enregistrer l\'argent qui passe d\'un compte à un autre.',
                'droits' => ['ROLE_PAIEMENT_ENCAISSER', 'ROLE_TRESORERIE_TRANSFERER'], 'route' => 'app_caisse_index', 'ecrans' => ['app_caisse_index', 'app_mouvement_tresorerie_new'],
                'captures' => [
                    ['nom' => 'caisse-virement', 'legende' => 'Le virement : montant, compte source, compte destination.'],
                ],
            ],
            [
                'cle' => 'depense-saisir', 'groupe' => 'caisse', 'titre' => 'Enregistrer une dépense',
                'but' => 'Noter une sortie d\'argent avec la photo du reçu.',
                'droits' => ['ROLE_TRESORERIE_SAISIR'], 'route' => 'app_finances_depenses', 'ecrans' => ['app_finances_depenses'],
                'captures' => [
                    ['nom' => 'depense', 'legende' => 'Finances › Dépenses : « Saisir une dépense ».'],
                ],
            ],
            [
                'cle' => 'encaissements-suivre', 'groupe' => 'caisse', 'titre' => 'Suivre les encaissements',
                'but' => 'Voir ce qui est encaissé, ce qui attend une validation et ce qui est rapproché.',
                'droits' => ['ROLE_PAIEMENT_VOIR'], 'route' => 'app_finances_encaissements', 'ecrans' => ['app_finances_encaissements'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'encaissements', 'legende' => 'Finances › Encaissements.'],
                ],
            ],
            [
                'cle' => 'factures-liste', 'groupe' => 'caisse', 'titre' => 'La liste des factures et les nouveaux documents',
                'but' => 'Retrouver une facture, un avoir, un reçu ou un devis, et créer un nouveau document.',
                'droits' => ['ROLE_FACTURE'], 'route' => 'app_factures_index', 'ecrans' => ['app_factures_index', 'app_factures_export'],
                'captures' => [
                    ['nom' => 'factures-liste', 'legende' => 'Documents de facturation : les chiffres du mois et la liste.'],
                    ['nom' => 'facture-nouveau-document', 'legende' => '« + Nouveau document » : le type, puis le point de départ.'],
                ],
            ],
            [
                'cle' => 'facture-paiement', 'groupe' => 'caisse', 'titre' => 'Encaisser depuis la facture',
                'but' => 'Enregistrer le paiement d\'une facture validée et envoyer le reçu sur WhatsApp.',
                'droits' => ['ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER'], 'route' => 'app_factures_index', 'ecrans' => ['app_factures_show'],
                'captures' => [
                    ['nom' => 'facture-paiement', 'legende' => '« Enregistrer un paiement » : Orange Money reçu à distance, à confirmer.'],
                ],
            ],
            [
                'cle' => 'avoir-faire', 'groupe' => 'caisse', 'titre' => 'Corriger une facture validée : l\'avoir',
                'but' => 'Créditer tout ou partie d\'une facture déjà numérotée, sans la modifier.',
                'droits' => ['ROLE_FACTURE'], 'route' => 'app_factures_index', 'ecrans' => ['app_factures_avoir'],
                'captures' => [
                    ['nom' => 'avoir-saisie', 'legende' => 'Nouvel avoir : pourquoi, les lignes à créditer, que faire de l\'avoir.'],
                ],
            ],
            [
                'cle' => 'documents-lies', 'groupe' => 'caisse', 'titre' => 'Les documents liés d\'une commande',
                'but' => 'Voir sur une page le devis, la commande, le BAT, le bon de livraison, les factures et les paiements.',
                'droits' => ['ROLE_FACTURE', 'ROLE_COMMANDE'], 'route' => 'app_factures_index', 'ecrans' => ['app_factures_documents_lies'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'documents-lies', 'legende' => 'Documents liés : la chaîne du dossier et son équilibre.'],
                ],
            ],
            [
                'cle' => 'encaissement-livreur', 'groupe' => 'caisse', 'titre' => 'Valider l\'argent rapporté par le livreur',
                'but' => 'Recevoir l\'argent encaissé à la livraison et le valider.',
                'droits' => ['ROLE_PAIEMENT_ENCAISSER', ['ROLE_COMMANDE', 'ROLE_LIVRAISON']], 'route' => 'app_livraisons_planning', 'ecrans' => ['app_livraisons_planning', 'app_livraisons_bl'],
                'captures' => [
                    ['nom' => 'livraisons-planning', 'legende' => 'Planning des livraisons : « À encaisser à la livraison ».'],
                    ['nom' => 'livraison-encaissement', 'legende' => 'La livraison remise : « Valider l\'encaissement ».'],
                ],
            ],
            [
                'cle' => 'fiche-client', 'groupe' => 'caisse', 'titre' => 'La fiche du client',
                'but' => 'Tout savoir d\'un client avant de l\'appeler ou de lui vendre.',
                'droits' => ['ROLE_CLIENT'], 'route' => 'app_clients_index', 'ecrans' => ['app_clients_show', 'app_clients_detail', 'app_clients_modifier', 'app_clients_journal'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'client-fiche', 'legende' => 'La fiche d\'un client.'],
                ],
            ],
            [
                'cle' => 'acces-espace-client', 'groupe' => 'caisse', 'titre' => 'Envoyer l\'accès à l\'espace client',
                'but' => 'Donner au client un code pour entrer dans son espace sur le site.',
                'droits' => ['ROLE_CLIENT'], 'route' => 'app_clients_index', 'ecrans' => ['app_clients_show', 'app_clients_acces_espace'],
                'captures' => [
                    ['nom' => 'acces-espace', 'legende' => 'L\'accès prêt : le message à envoyer par WhatsApp (code masqué).'],
                ],
            ],

            // ---------- Graphiste PAO ----------
            [
                'cle' => 'controle-fichiers', 'groupe' => 'graphiste', 'titre' => 'Contrôler les fichiers d\'un travail',
                'but' => 'Vérifier le fichier du client avant l\'impression.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_controle_pre_presse_index', 'ecrans' => ['app_controle_pre_presse_'],
                'captures' => [
                    ['nom' => 'prepresse', 'legende' => 'Contrôle prépresse : les travaux à contrôler.'],
                    ['nom' => 'prepresse-controle', 'legende' => 'Le contrôle d\'un travail : la liste des points à vérifier.'],
                ],
            ],
            [
                'cle' => 'prepresse-depot', 'groupe' => 'graphiste', 'titre' => 'Déposer les fichiers d\'une commande',
                'but' => 'Envoyer tous les fichiers d\'un coup : chacun va tout seul au bon format.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_controle_pre_presse_index', 'ecrans' => ['app_controle_pre_presse_depot', 'app_fichier_dossier_partage'],
                'captures' => [
                    ['nom' => 'prepresse-depot', 'legende' => 'La fenêtre « Déposer les fichiers » d\'une commande.'],
                ],
            ],
            [
                'cle' => 'prepresse-tableau', 'groupe' => 'graphiste', 'titre' => 'Le tableau de la prépresse',
                'but' => 'Voir en quatre colonnes où en est chaque commande, du fichier à la production.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_controle_pre_presse_tableau', 'consultation' => true,
                'captures' => [
                    ['nom' => 'prepresse-tableau', 'legende' => 'Prépresse › Tableau.'],
                ],
            ],
            [
                'cle' => 'prepresse-relances', 'groupe' => 'graphiste', 'titre' => 'Relancer les fichiers manquants',
                'but' => 'Demander au client les fichiers qui manquent, avec la liste des formats.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_controle_pre_presse_relances', 'ecrans' => ['app_controle_pre_presse_relance'],
                'captures' => [
                    ['nom' => 'prepresse-relances', 'legende' => 'Prépresse › Relances : J+1, J+3, J+7 et la réponse du client.'],
                ],
            ],
            [
                'cle' => 'prepresse-retirer', 'groupe' => 'graphiste', 'titre' => 'Retirer de la liste une ligne sans fichier',
                'but' => 'Enlever de la prépresse ce qui n\'aura pas de fichier, sans clôturer la commande.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_controle_pre_presse_retirees', 'ecrans' => ['app_controle_pre_presse_retirees', 'app_controle_pre_presse_retirer', 'app_controle_pre_presse_remettre'],
                'captures' => [
                    ['nom' => 'prepresse-retirees', 'legende' => 'Prépresse › Retirées : « Remettre dans la liste ».'],
                ],
            ],
            [
                'cle' => 'poste-bat', 'groupe' => 'graphiste', 'titre' => 'Mon tableau PAO et le BAT',
                'but' => 'Prendre le travail suivant, le concevoir, envoyer le BAT au client.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_conception_tableau', 'ecrans' => ['app_conception_'],
                'captures' => [
                    ['nom' => 'pao-tableau', 'legende' => 'Conception PAO : mon tableau.'],
                    ['nom' => 'pao-poste', 'legende' => 'Le poste de conception d\'un travail.'],
                ],
            ],
            [
                'cle' => 'montage', 'groupe' => 'graphiste', 'titre' => 'Monter un fichier sur une feuille',
                'but' => 'Placer les poses sur la feuille et sortir le PDF prêt à imprimer.',
                'droits' => [['ROLE_PREPRESSE', 'ROLE_PRODUCTION_GERER']], 'route' => 'app_montage_outil', 'ecrans' => ['app_montage_'],
                'captures' => [
                    ['nom' => 'montage', 'legende' => 'L\'outil de montage.'],
                ],
            ],
            [
                'cle' => 'demandes-conception', 'groupe' => 'graphiste', 'titre' => 'Demandes de conception',
                'but' => 'Prendre en charge un visuel demandé par un client.',
                'droits' => ['ROLE_PREPRESSE'], 'route' => 'app_demandes_conception_index', 'ecrans' => ['app_demandes_conception_'],
                'captures' => [
                    ['nom' => 'demandes-conception', 'legende' => 'Demandes de conception.'],
                ],
            ],
            [
                'cle' => 'creations-studio', 'groupe' => 'graphiste', 'titre' => 'Les créations du Studio textile',
                'but' => 'Retrouver les créations faites par les clients sur le site et leurs fichiers.',
                'droits' => [['ROLE_DEVIS', 'ROLE_COMMANDE']], 'route' => 'app_creations_atelier_index', 'ecrans' => ['app_creations_atelier_'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'creations', 'legende' => 'Ventes › Créations.'],
                ],
            ],

            // ---------- Opérateur production ----------
            [
                'cle' => 'tableau-production', 'groupe' => 'production', 'titre' => 'Le tableau de production',
                'but' => 'Voir chaque travail à son étape, du fichier à la livraison.',
                'droits' => ['ROLE_PRODUCTION_VOIR'], 'route' => 'app_atelier_production', 'ecrans' => ['app_atelier_production'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'production', 'legende' => 'Atelier › Production.'],
                ],
            ],
            [
                'cle' => 'planning-machines', 'groupe' => 'production', 'titre' => 'Le planning des machines',
                'but' => 'Voir la semaine de chaque machine, sa charge, et déplacer un travail.',
                'droits' => ['ROLE_PRODUCTION_VOIR'], 'route' => 'app_atelier_planning', 'consultation' => true,
                'captures' => [
                    ['nom' => 'planning-machines', 'legende' => 'Atelier › Planning.'],
                ],
            ],
            [
                'cle' => 'ma-file', 'groupe' => 'production', 'titre' => 'Poste machiniste : vérifier, estimer, imprimer, étiqueter',
                'but' => 'Faire avancer ses travaux du fichier à vérifier jusqu\'au colis prêt pour la livraison.',
                'droits' => ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER'], 'route' => 'app_ma_file_index', 'ecrans' => ['app_ma_file_', 'app_production_'],
                'captures' => [
                    ['nom' => 'ma-file', 'legende' => 'La file en colonnes, du fichier à vérifier au colis prêt.'],
                    ['nom' => 'ma-file-travail', 'legende' => 'Un travail en cours sur la machine.'],
                ],
            ],

            // ---------- Livreur ----------
            [
                'cle' => 'tournee', 'groupe' => 'livreur', 'titre' => 'Ma tournée du jour',
                'but' => 'Suivre ses livraisons dans l\'ordre, sur le téléphone.',
                'droits' => ['ROLE_LIVRAISON'], 'route' => 'app_livraisons_tournee', 'ecrans' => ['app_livraisons_tournee', 'app_livraisons_index'],
                'captures' => [
                    ['nom' => 'tournee', 'legende' => 'Ma tournée sur le téléphone.', 'mobile' => true],
                ],
            ],
            [
                'cle' => 'remise', 'groupe' => 'livreur', 'titre' => 'Remettre la commande au client',
                'but' => 'Prouver la livraison : articles, photo, signature, encaissement.',
                'droits' => ['ROLE_LIVRAISON'], 'route' => 'app_livraisons_tournee', 'ecrans' => ['app_livraisons_remise'],
                'captures' => [
                    ['nom' => 'remise', 'legende' => 'La remise au client.', 'mobile' => true],
                ],
            ],
            [
                'cle' => 'a-retirer', 'groupe' => 'livreur', 'titre' => 'Commandes à retirer',
                'but' => 'Relancer les clients dont la commande attend à l\'imprimerie.',
                'droits' => ['ROLE_LIVRAISON'], 'route' => 'app_livraisons_a_retirer', 'ecrans' => ['app_livraisons_a_retirer'],
                'captures' => [
                    ['nom' => 'a-retirer', 'legende' => 'Commandes à retirer.', 'mobile' => true],
                ],
            ],

            // ---------- Entretien ----------
            [
                'cle' => 'entretien-taches', 'groupe' => 'entretien', 'titre' => 'Mon planning d\'entretien',
                'but' => 'Faire les tâches du jour et les valider par une photo.',
                'droits' => ['ROLE_ENTRETIEN'], 'route' => 'app_entretien_index', 'ecrans' => ['app_entretien_'],
                'captures' => [
                    ['nom' => 'entretien', 'legende' => 'Mon planning d\'entretien.', 'mobile' => true],
                    ['nom' => 'entretien-tache', 'legende' => 'Une tâche : les étapes, puis la photo.', 'mobile' => true],
                ],
            ],

            // ---------- Mon espace ----------
            [
                'cle' => 'pointer', 'groupe' => 'mon-espace', 'titre' => 'Pointer à l\'arrivée et au départ',
                'but' => 'Scanner l\'affiche de pointage et prendre la photo.',
                'droits' => [self::PERSONNEL], 'route' => 'app_pointer_index', 'ecrans' => ['app_pointer_', 'app_mon_espace_pointage'],
                'captures' => [
                    ['nom' => 'pointer', 'legende' => 'L\'écran Pointer sur le téléphone.', 'mobile' => true],
                    ['nom' => 'pointer-photo', 'legende' => 'Après le scan : la photo.', 'mobile' => true],
                ],
            ],
            [
                'cle' => 'absence-conge', 'groupe' => 'mon-espace', 'titre' => 'Déclarer une absence, demander un congé',
                'but' => 'Envoyer sa demande à la RH et suivre la réponse.',
                'droits' => [self::PERSONNEL], 'route' => 'app_mon_espace_index', 'ecrans' => ['app_mon_espace_index', 'app_mon_espace_demande'],
                'captures' => [
                    ['nom' => 'mon-espace', 'legende' => 'Mon espace sur le téléphone.', 'mobile' => true],
                    ['nom' => 'absence', 'legende' => 'Déclarer une absence.', 'mobile' => true],
                ],
            ],
            [
                'cle' => 'ma-prime', 'groupe' => 'mon-espace', 'titre' => 'Ma prime',
                'but' => 'Comprendre sa prime estimée du mois.',
                'droits' => [self::PERSONNEL], 'route' => 'app_mon_espace_prime', 'ecrans' => ['app_mon_espace_prime', 'app_mon_espace_performance'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'ma-prime', 'legende' => 'Ma prime sur le téléphone.', 'mobile' => true],
                ],
            ],
            [
                'cle' => 'mes-statistiques', 'groupe' => 'mon-espace', 'titre' => 'Mes statistiques',
                'but' => 'Suivre ses propres résultats du mois.',
                'droits' => ['ROLE_STATS_PERSO'], 'route' => 'app_mes_statistiques', 'ecrans' => ['app_mes_statistiques'], 'consultation' => true,
                'captures' => [
                    ['nom' => 'mes-statistiques', 'legende' => 'Mes statistiques.'],
                ],
            ],
        ];
    }

    /**
     * @return array{cle: string, groupe: string, titre: string, but: string, droits: list<string|list<string>>, route: string, ecrans?: list<string>, captures: list<array{nom: string, legende: string, mobile?: bool}>, consultation?: bool}|null
     */
    public static function fiche(string $cle): ?array
    {
        foreach (self::fiches() as $fiche) {
            if ($fiche['cle'] === $cle) {
                return $fiche;
            }
        }

        return null;
    }

    /**
     * La personne a-t-elle les droits de l'écran décrit ?
     *
     * @param array{droits: list<string|list<string>>, route: string, consultation?: bool} $fiche
     * @param callable(string): bool                                                     $accorde isGranted
     * @param list<string>                                                               $roles   droits de la personne (User::getRoles), pour le mode « Voir »
     */
    public static function autorisee(array $fiche, callable $accorde, array $roles = []): bool
    {
        if ([] === $fiche['droits']) {
            return false;
        }
        foreach ($fiche['droits'] as $droit) {
            $unSuffit = false;
            foreach ((array) $droit as $possible) {
                if ($accorde($possible)) {
                    $unSuffit = true;
                    break;
                }
            }
            if (!$unSuffit) {
                return false;
            }
        }

        // Livreur seul : les fiches des seules pages qu'il ouvre (sa tournée, Mon espace…).
        if (LivreurSeul::est($roles) && !LivreurSeul::routePermise($fiche['route'])) {
            return false;
        }

        // Module en « Voir » : pas de fiche qui crée ou modifie (LectureSeuleSubscriber le refuserait).
        return ($fiche['consultation'] ?? false) || $accorde('ROLE_ADMIN') || null === ModulesRoles::moduleEnLecture($fiche['route'], $roles);
    }

    /**
     * @param callable(string): bool $accorde
     * @param list<string>           $roles
     *
     * @return list<array<string, mixed>>
     */
    public static function visibles(callable $accorde, array $roles = []): array
    {
        return array_values(array_filter(self::fiches(), static fn (array $f): bool => self::autorisee($f, $accorde, $roles)));
    }

    /**
     * @param list<array<string, mixed>> $fiches
     * @param list<string>|null          $enPremier groupes à mettre en tête (ceux de la personne)
     *
     * @return array<string, list<array<string, mixed>>> groupes dans l'ordre de GROUPES, sans les vides
     */
    public static function parGroupe(array $fiches, ?array $enPremier = null): array
    {
        $ordre = array_keys(self::GROUPES);
        if (null !== $enPremier) {
            $ordre = array_values(array_unique([...array_intersect($enPremier, $ordre), ...$ordre]));
        }
        $groupes = [];
        foreach ($ordre as $groupe) {
            $dedans = array_values(array_filter($fiches, static fn (array $f): bool => $f['groupe'] === $groupe));
            if ([] !== $dedans) {
                $groupes[$groupe] = $dedans;
            }
        }

        return $groupes;
    }

    /**
     * Fiches qui parlent de l'écran d'où l'on vient (bouton « ? Aide »).
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return list<array<string, mixed>>
     */
    public static function pourEcran(array $fiches, ?string $route): array
    {
        if (null === $route || '' === $route) {
            return [];
        }

        return array_values(array_filter($fiches, static function (array $f) use ($route): bool {
            foreach ($f['ecrans'] ?? [$f['route']] as $prefixe) {
                if (str_starts_with($route, $prefixe)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Groupes de « Imprimer mon guide » : Premiers pas, celui du rôle,
     * ceux dont le droit est ajouté sur la fiche de la personne, Mon
     * espace. Rôle inconnu (créé dans Rôles et menus) : null, tout ce qui
     * est visible.
     *
     * @param list<string> $droitsAjoutes User::getDroitsAjoutes()
     *
     * @return list<string>|null
     */
    public static function groupesAImprimer(?string $codeRole, array $droitsAjoutes = []): ?array
    {
        $groupe = self::GROUPE_DU_ROLE[$codeRole ?? ''] ?? null;
        if (null === $groupe) {
            return null;
        }
        $groupes = ['premiers-pas', $groupe];
        foreach (self::GROUPES as $cle => $infos) {
            if (isset($infos['droit']) && \in_array($infos['droit'], $droitsAjoutes, true)) {
                $groupes[] = $cle;
            }
        }
        $groupes[] = 'mon-espace';

        return array_values(array_unique($groupes));
    }

    /**
     * Toutes les captures, par nom.
     *
     * @return array<string, array{fiche: string, legende: string, mobile: bool}>
     */
    public static function captures(): array
    {
        $captures = [];
        foreach (self::fiches() as $fiche) {
            foreach ($fiche['captures'] as $capture) {
                $captures[$capture['nom']] = ['fiche' => $fiche['cle'], 'legende' => $capture['legende'], 'mobile' => $capture['mobile'] ?? false];
            }
        }

        return $captures;
    }
}
