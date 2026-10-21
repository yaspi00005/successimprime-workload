# Atelier de production

Cinq écrans pour suivre les machines de l'atelier, d'après les maquettes AT01 à AT05 (« Atelier de production »). Menu **Atelier**, au local seulement : en ligne, toutes ces pages répondent « page introuvable ».

| Écran | Adresse | Pour qui |
| --- | --- | --- |
| Vue en direct (AT01) | `/atelier/direct` | tous ceux qui voient la production |
| Écran TV de l'atelier | `/atelier/tv`, puis `/ecran-accueil/{jeton}/atelier` | la télévision de l'atelier |
| Files d'attente (AT02) | `/atelier/files/{machine}` | tous ; le chef d'atelier range |
| Planning de la semaine (AT03) | `/atelier/planning` | tous ; le chef d'atelier déplace |
| Maintenance et incidents (AT04) | `/atelier/maintenance` | tous ; le chef d'atelier met à jour |
| Mon travail (AT05) | `/atelier/operateur` | l'opérateur, sur son téléphone |

Le code : `src/Controller/AtelierProductionController.php`, `src/Service/Atelier/AtelierProduction.php` (ce que montrent les pages), `src/Service/Atelier/AvancementOrdre.php` (les calculs, testés dans `tests/Unit/Service/Atelier/AvancementOrdreTest.php`), `src/Service/Atelier/ActionsOrdre.php` (démarrer, pause, terminer), `templates/atelier_production/`, `public/css/atelier-production.css`.

La production au rouleau (parcours d'un fichier, imposition, envoi au RIP, fiche de production, machines et dossiers) : `docs/production-rouleau.md`.

## Qui voit quoi

- **Chef d'atelier** (nouveau droit « Chef d'atelier », `ROLE_ATELIER_CHEF`, donné par l'administrateur ou ajouté sur la fiche d'une personne) et **direction** : toutes les machines, et tous les gestes (ranger les files, déplacer dans le planning, affecter les opérateurs, mettre à jour les incidents, gérer l'entretien).
- **Opérateur** : les machines auxquelles le chef d'atelier l'a affecté (Files d'attente › « Affecter les opérateurs »). Il démarre, met en pause, termine, signale un problème et saisit l'encre de ses machines.
- Les gestes sur un travail (démarrer, pause, terminer, encre, signaler) sont permis à l'**opérateur affecté** à la machine, au **chef d'atelier** et au **poste de la machine** (adresse IP reconnue, comme Ma file machine).
- **Aucun montant** sur ces pages. Seule exception : le devis de réparation d'un incident, visible et saisissable seulement avec le droit « Coûts et marges ».
- L'**écran TV** ne montre ni client, ni montant, ni personne : le nom de la machine, le numéro de commande, le travail, l'avancement.

## Vue en direct (AT01)

Une carte par machine, dans cet ordre : en production, en pause, en entretien, en attente, libre, en panne. Sur chaque carte : le travail en cours, l'avancement, la fin estimée, l'opérateur, les niveaux d'encre (C, M, J, N, B). En haut, cinq chiffres : machines actives, travaux du jour (terminés et restants), m² imprimés aujourd'hui (sans la gâche ; les pièces sans dimensions à part), travaux en retard, gâche de la semaine (objectif sous 3 %). Puis les alertes de l'atelier (panne, tête surveillée, encre sous 25 %, stock bas, retard) et les travaux terminés aujourd'hui. La page se recharge chaque minute.

Ces alertes sont celles de l'atelier : elles n'ont rien à voir avec les alertes système (`docs/alertes-systeme.md`).

« Afficher sur l'écran TV » ouvre la vue de la télévision, protégée par le même jeton secret que l'écran d'accueil de l'imprimerie : il suffit d'ouvrir l'adresse une fois sur la télévision.

## Files d'attente (AT02)

Une file par machine : commande, travail, matière, surface, temps, date promise, statut (en cours, en retard, à suivre, bloqué). En bas : total en m² et en temps, fin estimée de la file, nombre de changements de rouleau, nombre de travaux bloqués.

- **Ordre automatique** : le travail en cours reste en tête, puis les retards, puis par date promise ; le même jour, la même matière est groupée pour changer moins souvent de rouleau ; les travaux bloqués restent en bas.
- Le chef d'atelier **glisse une ligne** (sur téléphone : flèches haut et bas) ; « Réordonner automatiquement » revient aux règles. Un nouveau travail se place selon les règles après ceux qui ont été rangés.
- Un travail est **bloqué** tant que son contrôle prépresse n'est pas validé (fichier à corriger, attente du client).
- La **matière** vient de la nomenclature du produit (famille Supports) ; à droite, le stock de cette matière et les niveaux d'encre.

## Planning de la semaine (AT03)

Une ligne par machine, une colonne par jour du lundi au samedi, et la charge de la semaine (rouge au-delà de 85 %). Le dimanche, la page montre la semaine qui commence le lendemain. Gris : terminé ; rayé : entretien ; rouge : retard ou panne.

Le chef d'atelier glisse un bloc vers un autre jour ou une autre machine (sur téléphone : « Déplacer »). Si la nouvelle date dépasse la date promise, la caisse (droit Commandes) reçoit une notification pour prévenir le client.

## Maintenance et incidents (AT04)

- **Incidents ouverts** : signalés depuis Mon travail ou depuis cette page, avec la machine, ce qui se passe, le détail, la suite prévue, et « machine arrêtée » ou non. États : ouvert, en réparation, surveillé (la machine tourne), résolu. À la résolution, l'arrêt est noté dans l'historique de maintenance de la machine (type « Panne », avec sa durée).
- **Arrêts ce mois** : la durée d'arrêt par machine.
- **Entretien préventif** : une tâche par machine avec sa fréquence (chaque jour, chaque semaine, tous les N mois, toutes les N heures ou N m² imprimés), la dernière fois, et l'état OK, Bientôt (80 % du délai passé) ou À faire. « Fait » note l'entretien dans l'historique de la machine.
- **Compteurs** : le compteur de chaque machine (pages, m², cartes, transferts) et l'âge des têtes ; « pose des têtes » enregistre un changement.

Quand une machine est arrêtée, le chef d'atelier peut **masquer en ligne** ses produits (case cochée par défaut) : ceux dont c'est la machine (fiche produit, docs/produits.md) et qui n'ont pas de machine de secours en état passent « masqués en ligne » avec le motif « Machine en réparation ». Ils quittent le site, le calculateur et l'espace client, et se vendent toujours au comptoir. Ils reviennent en ligne quand l'incident est résolu, s'il n'y a plus d'autre arrêt ouvert sur la machine ; un produit masqué à la main pour un autre motif reste masqué.

## Mon travail (AT05)

L'écran de l'opérateur, pensé pour le téléphone : le travail en cours de sa machine (ou le suivant), les fichiers, l'avancement et la fin estimée, puis **Pause**, **Terminer**, **Signaler un problème** et les niveaux d'encre. En bas, la suite de sa file.

« Terminer » demande la quantité imprimée et la gâche (calculée d'elle-même : imprimé − demandé, modifiable), le motif de la gâche (réglage, bourrage, couleur, tête bouchée, autre) et montre l'étape suivante de la commande (autre machine ou finition). Valider sort le stock, termine l'ordre et la ligne de commande, fait avancer le compteur de la machine, note la gâche au journal de la machine et prévient la suite.

## Calculs

- **Durée prévue** : l'estimation de l'ordre, sinon surface ÷ vitesse de la machine (15 min au moins), sinon 1 heure.
- **Avancement** : minutes de travail effectif (pauses retirées) ÷ durée prévue, 99 % au plus tant que le travail n'est pas terminé ; un travail qui dépasse garde 5 minutes de reste. Une pause repousse la fin estimée.
- **Fin estimée de la file** : les travaux s'enchaînent après le travail en cours, sur les heures d'ouverture (8 h – 19 h, du lundi au samedi) ; un travail déplacé dans le planning garde son créneau.
- **Retard** : la date promise (date limite de l'ordre, sinon livraison prévue de la commande ; sans heure, jusqu'à 19 h) est dépassée, ou la fin estimée tombe après elle.
- **Gâche** : gâche ÷ (bon + gâche), en m² quand les dimensions sont connues, sinon en pièces.

## Colis et contrôle final

Une fois ses travaux terminés, une commande passe par **Production › Étiquettes** (`/atelier/production/etiquettes`, une étiquette 100 × 60 mm par colis), puis **Production › Contrôle final** (`/atelier/production/controle` : BAT et photo, vérifications, colis scannés, temps et consommé) ; elle passe « Prête » au contrôle validé et au dernier colis scanné. Le scanner du téléphone (`/atelier/operateur/scanner`) est au bas de Mon travail. « Refaire une pièce » suit la même règle que les gestes ci-dessus (opérateur affecté, chef d'atelier, poste de la machine). Tout est dans `docs/production-colis.md`.

## Niveaux d'encre

Les machines n'envoient aucune donnée : les niveaux d'encre sont **saisis à la main** par l'opérateur ou le chef d'atelier (Files d'attente › « Saisir les niveaux d'encre », ou Mon travail). La page dit qui les a saisis et quand.

## Tables

`atelier_machine` (opérateurs affectés, niveaux d'encre, ordre choisi de la file, pose des têtes), `atelier_machine_operateur`, `incident_machine`, `entretien_machine` (migration `Version20261018174810`). Les machines, les ordres de production, le stock, l'historique de maintenance et le journal des machines sont ceux qui existaient déjà.

## Démonstration

`php bin/console app:atelier:demo` (lancée par `app:demo`, jamais en production ni en ligne) : voir `docs/donnees-demo.md`. Comptes : `demo.ibrahim` (chef d'atelier), `demo.oumar` (opérateur des deux solvants), mot de passe `demo1234`.

## Ce qui reste à décider

- Les gestes depuis un téléphone pour l'opérateur affecté, alors que la décision du 11/10/2026 les réservait au poste de la machine (en place : opérateur affecté, chef d'atelier ou poste).
- Un graphiste qui a les droits production mais aucune machine affectée ne voit aucune machine.
- Le client est prévenu par la caisse quand un déplacement dépasse la date promise, pas automatiquement.
- Photo du premier tirage : pas encore faite.
