# Tâches planifiées

Une partie du travail se fait sans écran, par des commandes `php bin/console app:…` lancées à heure fixe. Chaque commande a sa fiche dans `commandes` du guide : nom, description, classe, options, et `planification` (quand, où, d'après quel fichier). `planifiee` vaut `true` quand au moins un rythme a été trouvé.

## Où se règlent les tâches

- **ERP local sous Windows** : `scripts/windows/`. Chaque `installer_tache_….bat` crée une tâche Windows (`schtasks /create /sc … /mo … /st …`) qui lance le script `.bat` voisin, et ce script lance une ou plusieurs commandes. `installer_taches_planifiees.bat` installe les sauvegardes (chaque heure et au démarrage). Exemple : `installer_tache_synchro.bat` crée la tâche de chaque minute qui lance `synchro.bat`, et `synchro.bat` lance `app:synchro`, `app:messagerie:delais`, `app:notifications`, `app:dossier-partage:importer` et `app:messagerie-interne:fermer`. Les journaux vont dans `var\log\`.
- **Serveur en ligne** : la `crontab` du compte `succes`, écrite dans docs/deploiement.md (« Tâches planifiées du serveur »).
- **ERP local sous Linux ou Mac** : les lignes `crontab` d'exemple données par chaque page (avec `cd /chemin/du/projet`).

Le générateur lit ces trois sources (`TachesPlanifiees::depuisScriptsWindows`, `::depuisCrontab`), plus les phrases des docs qui donnent un rythme (« `app:devis:relances` chaque heure », `::depuisPhrases`) et les lignes crontab écrites dans le docblock de la classe de la commande. Le champ `source` de chaque rythme dit d'où il vient, pour aller vérifier.

## Les tâches principales

Les rythmes ci-dessous viennent des scripts et des docs au moment de l'écriture. Le guide régénéré fait foi.

| Commande | Rythme | Où | Ce qu'elle fait |
|---|---|---|---|
| `app:synchro` | chaque minute | ERP local | un cycle de synchronisation avec le site (chapitre « Deux installations ») |
| `app:notifications` | chaque minute | ERP local | pousse les notifications de l'équipe (docs/notifications-equipe.md) |
| `app:messagerie:delais` | chaque minute | ERP local | délais de réponse aux clients, escalade, clôture automatique (docs/messagerie-clients.md) |
| `app:dossier-partage:importer` | chaque minute | ERP local | importe les fichiers déposés dans le dossier partagé (docs/prepresse.md) |
| `app:messagerie-interne:fermer` | chaque minute | ERP local | ferme les groupes de projet livrés et nettoie les envois de fichiers abandonnés (docs/messagerie-interne.md) |
| `app:alertes` | toutes les 5 minutes | les deux | vérifie la synchronisation, le disque et la file de messages, envoie les alertes système (docs/alertes-systeme.md) |
| `app:dossiers-clients:analyser` | toutes les 5 minutes | ERP local | analyse les dossiers clients (docs/dossiers-clients.md) |
| `app:envoyer-campagnes` | toutes les 10 minutes | ERP local | messages marketing (docs/messagerie-marketing.md) |
| `app:rapports-mail` | toutes les 15 minutes | ERP local | rapports du jour, de la semaine et du mois par e-mail ; refuse de tourner en ligne |
| `app:espace:notifications` | toutes les 15 minutes | serveur en ligne | SMS mis de côté pendant les heures calmes, rappels d'échéance des factures |
| `app:devis:relances` | chaque heure | ERP local | devis expirés, relances WhatsApp (docs/devis.md) |
| `app:prepresse:relances` | chaque heure | ERP local | relances des fichiers attendus (J+1, J+3, J+7, J+15) |
| `app:sauvegarde --auto` | chaque heure et au démarrage | les deux | sauvegarde de la base (docs/sauvegardes.md) |
| `app:executer-decaissements-recurrents` | chaque jour à 06:00 | ERP local | décaissements qui reviennent (`DecaissementRecurrentService`) |
| `app:executer-rappels-paiement` | chaque jour à 07:00 | ERP local | rappels de paiement des factures (`RappelPaiementService`) |
| `app:nettoyer-fichiers-production` | chaque nuit (exemple `15 2 * * *`) | les deux | efface les fichiers de commande arrivés au bout de leur durée de conservation (chapitre « Fichiers ») |
| `app:corbeille:purger` | chaque jour à 03:30 | les deux | supprime pour de bon, après la sauvegarde de la nuit, ce qui est dans la corbeille depuis plus que la durée réglée, 30 jours par défaut (docs/corbeille.md) |

D'autres commandes ont un rythme seulement dans les pages de docs/ (`app:promotions`, `app:primes`, `app:planning:rappels`, `app:pointage:entretien`, `app:rapport-hebdomadaire`, `app:finances:alerte-tresorerie`, `app:immobilisations:dotations`, `app:messagerie:credits`, `app:actions-caisse`) : leur fiche le dit (`ou` : « d'après la documentation » ou « crontab d'exemple »). Pour un audit, vérifier qu'une tâche réelle existe sur la machine (Planificateur de tâches de Windows, `crontab -l` sur le serveur).

## Ce qu'il faut savoir sur ces commandes

- Elles tournent sans utilisateur connecté : elles n'ont pas de droits à vérifier, mais ce qu'elles écrivent passe par les mêmes services et les mêmes écouteurs Doctrine que les écrans (journal d'activité, file de synchronisation).
- Plusieurs refusent de tourner sur la mauvaise machine : elles testent `estEnLigne()` (liste dans `securite.separation_en_ligne_local.classes_qui_testent_estEnLigne`).
- Les commandes qui envoient des messages (SMS, WhatsApp, e-mail) ont leurs heures calmes et leurs limites dans leur service. La démonstration (clients `DEMO-…`) ne reçoit jamais de SMS (chapitre « Données de démonstration et tests »).
- Les autres commandes de la liste ne sont pas planifiées : installation (`app:installer-theme`, `app:verifier-installation`, `app:creer-utilisateur`, `app:autoriser-appareil`, `app:push:cles`), bascule (`app:bascule:verifier`), démonstration (`app:demo`, `app:…:demo`), recette (`app:recette:acces`), reprise de données, et ce guide (`app:doc:technique`).

Chaque commande planifiée a son point d'audit `commande:<nom>` (chapitre « Comment auditer avec ce guide »).
