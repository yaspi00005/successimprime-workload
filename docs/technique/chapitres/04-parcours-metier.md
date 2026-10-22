# Parcours métier étape par étape

Chaque étape donne l'écran, la route (nom et adresse), la méthode du contrôleur, les services appelés et les tables écrites. Les adresses et les noms se retrouvent dans `routes` du guide. Pour la liste complète des routes d'un écran, filtrer `routes` par `controleur`. Les pages de docs/ citées racontent le même parcours du point de vue de l'utilisateur.

Dans tout le chapitre, « écrit » veut dire ajouté ou modifié en base. Le journal d'activité (`journal_activite`, `AuditSubscriber`) et la file de synchronisation (`synchro_journal`, `JournalSynchro`) sont écrits en plus à chaque enregistrement d'une donnée suivie. On ne les répète pas à chaque ligne.

## 1. La vente : du devis à la facture payée

Pages : docs/devis.md, docs/saisie-devis-commandes.md, docs/suivi-commandes.md, docs/prepresse.md, docs/atelier-production.md, docs/documents-livraisons.md, docs/factures-entreprises.md.

| Étape | Écran | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|---|
| Saisir un devis | Ventes › Devis › Nouveau | `app_devis_new` `GET,POST /devis/new` | `DevisController::new` | services de `src/Service/Devis` | `devis`, `devis_details`, `devis_detail_finition` |
| L'envoyer au client | fiche du devis | `app_devis_envoyer` `POST /devis/{id}/envoyer` | `DevisController::envoyer` | `Devis\EnvoiDevis` (e-mail et PDF) | `devis` (état, date d'envoi) |
| Le transformer en commande | fiche du devis | `app_devis_convertir_commande` `POST /devis/{id}/convertir-commande` | `DevisController::convertirCommande` | `DevisCommandeConverter` | `commandes`, `commandes_details`, `commande_detail_finition` |
| Ou saisir une commande directement | Ventes › Commandes › Nouvelle | `app_commandes_new` `GET,POST /commandes/new` | `CommandesController::new` | `TarifClient`, `PromotionsSaisie`, `AccordsPlafond`, `NotificationService` | `commandes`, `commandes_details`, `notifications` |
| Encaisser un acompte | fiche de la commande | `app_commandes_paiement` `GET,POST /commandes/{id}/paiement` | `CommandesController::paiement` | `Paiement\SoldeClient` | `paiements`, `mouvement_tresorerie` |
| Déposer les fichiers du client | Prépresse › commande | `app_controle_pre_presse_depot_enregistrer` `POST /pre-presse/commande/{id}/depot/enregistrer` | `PrepresseController::enregistrer` | `Prepresse\DepotFichiers` | `commande_detail_fichier` |
| Contrôler et lancer en production | Prépresse › travail | `app_controle_pre_presse_controler` `GET,POST /pre-presse/travail/{id}` | `ControlePrePresseController::controler` | `TableauConception`, `PosteMachiniste` | `controle_pre_presse`, `ordre_production` |
| Démarrer, puis terminer sur la machine | Production | `app_production_demarrer` `POST /production/{id}/demarrer`, `app_production_terminer` `POST /production/{id}/terminer` | `ProductionController::demarrer`, `::terminer` | services de production, `StockService::consommerPourDetail` | `ordre_production`, `journal_machine`, `stock_sorties` |
| Même geste depuis le poste de l'opérateur | Atelier › opérateur | `app_atelier_operateur_agir` `POST /atelier/operateur/{id}/{action}` | `AtelierProductionController::agir` | `ActionsOrdre` | `ordre_production`, `journal_machine`, `stock_sorties` |
| Faire le bon de livraison | fiche de la commande | `app_bons_livraison_creer_commande` `POST /bons-livraison/creer/commande/{id}` | `BonLivraisonController::creerDepuisCommande` | | `bon_livraison`, `bon_livraison_ligne` |
| Livrer | bon de livraison | `app_bons_livraison_livrer` `POST /bons-livraison/{id}/livrer` | `BonLivraisonController::livrer` | `BonLivraison::marquerLivre` | `bon_livraison`, `commandes_details` (quantité livrée) |
| Tournée du livreur : remise et argent reçu | Livraisons › tournée | `app_livraisons_remise` `GET,POST /livraisons/tournee/{id}/remise`, `app_livraisons_bl_encaissement` `POST /livraisons/bl/{id}/encaissement` | `TourneeController::remise`, `::validerEncaissement` | `Livraisons\Tournee` | `bon_livraison`, `paiements` |
| Préparer la facture | fiche de la commande | `app_factures_nouveau` `GET,POST /factures/commande/{id}/nouveau` | `FacturesController::nouveau` | `NumerotationFactures` | `factures` |
| L'émettre | fiche de la facture | `app_factures_emettre` `POST /factures/{id}/emettre` | `FacturesController::emettre` | `Facturation\ValidationFacture` (numéro et validation dans une seule transaction) | `factures` |
| Valider ou annuler un paiement | Finances › À valider | `app_paiements_valider` `POST /paiements/{id}/valider`, `app_paiements_annuler` `POST /paiements/{id}/annuler` | `PaiementsController::valider`, `::annuler` | `PaiementService` | `paiements`, `mouvement_tresorerie` |
| Faire un avoir | fiche de la facture | `app_factures_avoir` `GET,POST /factures/{id}/avoir` | `AvoirsController::nouveau` | `Facturation\AvoirsFactures` | `avoir` |

## 2. La caisse

Page : docs/actions-caisse.md.

| Étape | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|
| Ouvrir la caisse du jour | `app_caisse_index` `GET /caisse` | `CaisseController::index` | | rien (lecture) |
| Encaisser plusieurs commandes d'un client en une fois | `app_caisse_groupe` `POST /caisse/groupe` | `CaisseController::groupe` | `Paiement\EncaissementGroupe` | `paiements`, `mouvement_tresorerie` |
| Clôturer sa caisse | `app_caisse_cloturer` `POST /caisse/cloturer` | `CaisseController::cloturer` | | `cloture_caisse` |

## 3. La commande en ligne du client

Pages : docs/espace-client.md, docs/synchronisation.md.

| Étape | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|
| Composer et envoyer la commande | `app_espace_nouvelle_commande` `GET,POST /espace/commandes/nouvelle` | `EspaceNouvelleCommandeController::nouvelle` | `EspaceClient\NouvelleCommande`, `EspaceClient\CompteEntreprise`, `DelaisCommande` | `commandes`, `commandes_details` |
| Payer (payeur seulement) | `app_espace_nouvelle_commande_paiement` `GET,POST /espace/commandes/nouvelle/{id}/paiement` | `EspaceNouvelleCommandeController::paiement` | `Paiement\ReglementsEnLigne`, `OrangeMoney` | `reglement_en_ligne`, `reglement_en_ligne_part`, `transaction_orange_money` |
| Confirmation envoyée par Orange | `app_orange_money_notification` `POST /om/notification/{o}` | `OrangeMoneyController::notification` | `OrangeMoney::notification` (le jeton reçu à l'ouverture est vérifié, seule son empreinte est gardée) | `transaction_orange_money`, `paiements` (« en attente ») |

Le paiement Orange Money n'est jamais validé tout seul : il arrive au local « en attente » et un responsable le valide dans Finances › À valider (`PaiementsController::valider`), sur le compte « Orange Money en ligne ».

## 4. Les achats et le stock

Pages : docs/achats.md, docs/stock-et-options.md.

| Étape | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|
| Demander un achat | `app_achat_new` `GET,POST /achats/new` | `AchatsController::new` | `Achats\SuiviAchats` (numéro `CA-AAAA-NNNN`) | `achats`, `achat_detail` |
| Le valider (au-dessus du seuil de 500 000 F) | `app_achat_valider` `POST /achats/{id}/valider` | `AchatsController::valider` | `Achats\SuiviAchats` | `achats` (états : brouillon, a_valider, commandee, en_route, recu, annule) |
| Réceptionner la marchandise | `app_reception_valider` `POST /achats/receptions/{id}/valider` | `ReceptionsController::valider` | `Achats\Receptions` | `stock_entrees`, `reclamation_fournisseur`, `depense`, `article_fournisseur` |
| Réserver le stock d'une commande | (automatique à l'enregistrement de la commande) | écouteur `ReservationsCommandeListener` | `ReservationsCommande` | `stock_reservation` |
| Sortir le stock | (automatique à la fin de la production, voir la vente) | | `StockService::consommerPourDetail` | `stock_sorties` |
| Sortir un consommable à la main | `app_consommables_ajouter` `POST /consommables/ajouter` | `ConsommablesController::ajouter` | `StockService::enregistrerConsommableManuel` | `stock_sorties` |
| Ajuster un article, faire l'inventaire | `app_stock_articles_ajuster` `POST /stock/articles/{id}/ajuster`, `app_stock_articles_inventaire` `GET,POST /stock/articles/inventaire` | `StockArticlesController::ajuster`, `::inventaire` | `StockService::enregistrerConsommableManuel` (écart négatif) | `stock_entrees` (écart positif), `stock_sorties` (écart négatif) |

La réception (`ReceptionsController`, `Achats\Receptions`) est en cours de construction (SU05) au moment où ce guide est écrit : relire `routes` et `services` du guide régénéré pour son état réel.

## 5. Les finances

Page : docs/finances.md (écrans F01 à F07).

| Étape | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|
| Saisir une entrée, une sortie, un virement | `app_mouvement_tresorerie_new` `GET,POST /gestion/tresorerie/mouvements/nouveau` | `MouvementTresorerieController::new` | `MouvementTresorerieService` | `mouvement_tresorerie` |
| Valider un mouvement | `app_mouvement_tresorerie_validate` `POST /gestion/tresorerie/mouvements/{id}/valider` | `MouvementTresorerieController::validateMovement` | `MouvementTresorerieService` | `mouvement_tresorerie` |
| Approuver, refuser, payer une dépense | `app_finances_depenses_approuver`, `app_finances_depenses_refuser`, `app_finances_depenses_payer` (`POST /finances/depenses/{id}/…`) | `Finances\DepensesController::approuver`, `::refuser`, `::payer` | `Finances\Depenses`, `Finances\Budgets` | `depense`, `budget_depense`, `mouvement_tresorerie` (au paiement) |
| Rapprocher le relevé Orange Money | `app_finances_encaissements_importer`, `…_associer`, `…_ignorer` | `Finances\EncaissementsController` | | `ligne_releve_orange_money` |
| Échéancier des fournisseurs | écran Fournisseurs | `Finances\FournisseursController::index` | `Finances\Echeancier` | rien (calcul) |
| Planifier une échéance | `app_planning_echeance_nouvelle` `GET,POST /planning/echeance/nouvelle` | `PlanningController::nouvelle` | | `echeance` |
| Exporter le journal comptable | `app_comptabilite_export` `GET /finances/comptabilite/export` | `ComptabiliteController::export` | `JournalComptable` | rien (fichier) |
| Décaissements qui reviennent chaque mois | (tâche planifiée) | | `DecaissementRecurrentService` | `mouvement_tresorerie` |

## 6. Les employés

Pages : docs/pointage.md, docs/espace-employe.md, docs/immobilisations-primes.md.

| Étape | Route | Contrôleur | Services | Tables écrites |
|---|---|---|---|---|
| Pointer à l'entrée (tablette ou téléphone) | `app_pointer_enregistrer` `POST /pointer/{code}/{secret}` | `PointerController::enregistrer` | `Pointage\Pointages` | `pointage` |
| Corriger un pointage | `app_pointage_corriger` `POST /equipe/pointage/{id}/corriger` | `PointageController::corriger` | `Pointage\Pointages` | `pointage` |
| Faire une demande (absence, congé, réclamation, frais médicaux) | `app_mon_espace_demande` `GET,POST /mon-espace/demande/{type}` | `MonEspaceController::demande` | `EspaceEmploye` | `demande_employe`, `conge_employe` |
| Décider d'une demande, régler un congé | `app_demandes_decider` `POST /equipe/demandes/{id}/decider`, `app_demandes_conge_regler` `POST /equipe/demandes/conges/{id}` | `DemandesEmployesController::decider`, `::reglerConge` | `EspaceEmploye` | `demande_employe`, `conge_employe` |
| Valider la prime d'un agent | `app_primes_valider` `POST /equipe/primes/agent/{id}/valider` | `PrimesController::valider` | `Primes` | `prime_agent`, `evaluation_prime` |
| Préparer et payer la paie | `app_paie_preparer` `POST /equipe/paie/preparer`, `app_paie_payer` `POST /equipe/paie/{id}/payer` | `PaieController::preparer`, `::payer` | `EspaceEmploye\Paie` | `bulletin_paie`, `mouvement_tresorerie` |

## 7. Les relances

| Relance | Déclencheur | Services | Tables écrites |
|---|---|---|---|
| Fichiers attendus en prépresse (J+1, J+3, J+7, J+15) | tâche `app:prepresse:relances` | `Prepresse\RelancesFichiers` | `relance_fichier` |
| Devis sans réponse (expirés, relancés sur WhatsApp après 3 jours) | tâche `app:devis:relances` | `Devis\EnvoiDevis`, `Devis\SuiviDevis` | `devis` |
| Créances | `app_creances_relancer` `POST /finances/creances/relancer` (`CreancesController::relancer`) | | `relance_creance` |
| Commande prête non retirée | `app_livraisons_a_retirer_relancer` `POST /livraisons/a-retirer/{id}/relancer` (`RelanceRetraitController::relancer`) | `RelanceRetraitService` | `relance_retrait` |
| Rappels de paiement des factures | tâche planifiée (chapitre « Tâches planifiées ») | `RappelPaiementService` | `rappel_paiement_envoye` |

## 8. Les alertes et les notifications

- **Alertes système** (docs/alertes-systeme.md) : `Alertes\AlertesSysteme` écrit dans `alerte_systeme` ce qui ne va pas (synchronisation arrêtée, disque plein, file de messages) ; la tâche `app:alertes` les envoie ; l'écran est `AlertesSystemeController`.
- **Notifications de l'équipe** (docs/notifications-equipe.md) : `NotificationService` et `NotificationsEquipe` écrivent dans `notifications` ; la tâche `app:notifications` les pousse ; l'écran est `NotificationController`.
