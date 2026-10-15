# Formation d'une demi-journée par rôle

But (jalon J7) : après une demi-journée, une nouvelle personne fait seule un devis et un encaissement, sans aide. Chaque rôle a son plan en blocs de 30 minutes, des exercices sur les comptes de démonstration et un exercice final.

Les mêmes plans s'impriment depuis l'application : « ? Aide » › « Fiches du formateur » (adresse `/aide/formation`, Direction seulement). Ils viennent de `src/Service/Guide/Formation.php` ; ce fichier les reprend mot pour mot (un test le vérifie).

## Préparer la séance

- **Jamais sur la base de la boutique.** La formation se fait sur une installation de formation (un poste ou un serveur de test, `APP_ENV=dev`, `APP_EN_LIGNE=0`), sans clés SMS, e-mail ni WhatsApp dans son `.env.local`. `app:demo` refuse de tourner en production.
- Remettre les exercices à zéro avant chaque séance : `php bin/console app:demo` (relançable : l'ancienne démo est d'abord enlevée, voir `docs/donnees-demo.md`). Par exemple la commande du site CMD-DC0005 redevient « à confirmer ».
- Les comptes : `demo.aminata` (caisse), `demo.seydou` et `demo.aissata` (graphistes), `demo.oumar` (machines), `demo.bakary` (livreur), `demo.awa` (entretien), `demo.kadiatou` (direction). Mot de passe `demo1234`.
- Imprimer pour la personne formée son guide : connecté avec son compte de démonstration, « ? Aide » › « Imprimer mon guide ». Le formateur imprime sa fiche dans « Fiches du formateur » (seulement les pages du rôle formé).
- Le livreur, l'opérateur et l'agent d'entretien travaillent sur un téléphone : prévoir un téléphone sur le Wi-Fi de la boutique. Pour le pointage, la RH valide d'abord le téléphone : Équipe › Pointage › « Points et appareils », onglet « Téléphones à valider », « Autoriser ».
- Les numéros de téléphone saisis pendant les exercices sont fictifs (8 chiffres commençant par 60). Les clients DEMO- ne reçoivent jamais de SMS ni d'e-mail.

## Déroulé commun

1. Le formateur montre la fiche du guide du bloc, à l'écran.
2. La personne fait l'exercice seule, sur son compte de démonstration ; le formateur regarde sans toucher.
3. Le formateur compare avec le résultat attendu. Si ce n'est pas bon, la personne relit la fiche et recommence.
4. L'exercice final se fait sans le formateur et sans le guide ouvert. Réussi, il se signe sur la fiche du formateur.

Chaque personne suit aussi « Premiers pas » et « Mon espace » (pointage, absences) : ils sont dans ses blocs.

## Caisse et commercial

- Compte : `demo.aminata`
- Durée : 4 h
- À la fin, la personne sait : Faire seul un devis, la commande, l'encaissement et la facture d'un nouveau client (jalon J7).

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter et se repérer | Se connecter et trouver l'aide | Connexion, menu court, « Aide », « Aller à… ». |
| 08:30 | Les clients | Trouver ou créer un client · La fiche du client | Exercices 1 et 2. |
| 09:00 | Le devis | Faire un devis | Exercice 3. |
| 09:30 | La commande | Transformer le devis en commande · Prendre une commande sans devis · Suivre les devis et commandes | Exercice 4. |
| 10:00 | L'encaissement et la facture | Encaisser un paiement · Facturer, imprimer, envoyer | Exercices 5 et 6. |
| 10:30 | Pause, puis dépenses et virement | Enregistrer une dépense · Verser la caisse à la banque | Exercices 7 et 8. |
| 11:00 | Le site, le client et le livreur | Confirmer une commande passée en ligne · Envoyer l'accès à l'espace client · Valider l'argent rapporté par le livreur · Mes actions de caisse | Exercices 9 à 11. |
| 11:30 | Exercice final J7, puis clôture de la caisse | Clôturer la caisse le soir | Exercice final, seul, sans aide. Puis la clôture, avec le formateur. |

### Exercices

1. **Retrouver un client** (`demo.aminata`)
   - Menu « Clients ».
   - Cherchez « Salon Espoir » et ouvrez sa fiche.

   Résultat attendu : La fiche s'ouvre ; l'employé lit le nombre de commandes et la dernière commande.

2. **Créer un client particulier** (`demo.aminata`)
   - « Nouveau client », type Particulier.
   - Prénom, Nom, un numéro fictif à 8 chiffres.
   - « Enregistrer et créer un devis ».

   Résultat attendu : L'écran « Nouveau devis » s'ouvre avec ce client.

3. **Faire le devis** (`demo.aminata`)
   - Une ligne « Bâche imprimée (démo) », 300 × 100 cm, « Œillets tous les 50 cm ».
   - « Enregistrer le devis », puis « Télécharger PDF ».

   Résultat attendu : Le devis DEV-… est en « Brouillon », pour 10 500 F, et le PDF s'ouvre.

4. **Passer le devis en commande** (`demo.aminata`)
   - « Modifier », Statut « Accepté », « Enregistrer les modifications ».
   - « Valider et créer la commande », puis confirmer.

   Résultat attendu : La commande CMD-… s'ouvre avec la frise, de « Confirmée » à « Livrée ».

5. **Encaisser un acompte** (`demo.aminata`)
   - Menu « Caisse » : le client, sa commande.
   - 5 000 F, Espèces, compte « Caisse boutique ».
   - « Encaisser et imprimer le reçu ».

   Résultat attendu : Message « Paiement de 5 000 FCFA enregistré », reçu R-…, et « Encaissé » de la caisse du jour augmente de 5 000 F.

6. **Faire la facture** (`demo.aminata`)
   - Sur la commande, « Facturer ».
   - « Facture officielle », « Enregistrer le document ».

   Résultat attendu : La facture FAC-… s'ouvre ; « Générer le PDF » l'archive.

7. **Saisir une dépense** (`demo.aminata`)
   - « Aller à… » › « Nouveau › Dépense ».
   - Photo d'un reçu, objet « Carburant moto livraison », Divers, 2 000 F, payé avec « Caisse boutique ».
   - « Enregistrer la dépense ».

   Résultat attendu : Message : le décaissement attend sa validation dans « À valider ». Le formateur (demo.kadiatou) la voit dans Finances › À valider.

8. **Verser la caisse à la banque** (`demo.aminata`)
   - Caisse › « Virement ».
   - 50 000 F, de « Caisse boutique » vers « Banque BDM », un libellé.
   - « Enregistrer le mouvement », « Oui, enregistrer ».

   Résultat attendu : Retour à la caisse avec « Virement de 50 000 F … enregistré ». Aucun solde n'est affiché.

9. **Confirmer une commande du site** (`demo.aminata`)
   - Ouvrez la commande CMD-DC0005 (Salon Espoir).
   - « Vérifier et confirmer » : la ligne « Affiche A3 · A2 » est à 0 F, son prix est confirmé par l'atelier. Saisissez 500 F dans « Prix unitaire ».
   - « Confirmer la commande ».

   Résultat attendu : Message « La commande a été modifiée avec succès. » ; le bandeau « Commande passée par le client dans son espace » a disparu : la commande part à l'atelier.

10. **Envoyer l'accès à l'espace client** (`demo.aminata`)
   - Fiche du client créé à l'exercice 2 (pas une fiche DEMO-).
   - « Envoyer l'accès à l'espace client ».

   Résultat attendu : « Accès prêt pour … » avec l'identifiant, le code et la durée de 48 h. Ne pas l'envoyer : le numéro est fictif.

11. **Valider l'argent d'un livreur** (`demo.aminata`)
   - Le formateur fait une remise avec encaissement en espèces (demo.bakary, sur téléphone).
   - Planning des livraisons › la livraison remise › « Compte qui reçoit » › « Valider l'encaissement ».

   Résultat attendu : L'encaissement n'est plus provisoire ; « À encaisser à la livraison » le compte comme encaissé.

### Exercice final J7 : un client, de bout en bout

Seul, sans le formateur et sans le guide ouvert.

1. Seul, sans aide et sans le guide : un nouveau client particulier.
2. Un devis : bâche 200 × 100 cm avec œillets.
3. Le devis accepté, la commande créée.
4. Un acompte de 50 % en espèces, le reçu imprimé.
5. La facture officielle.

Résultat attendu : En 20 minutes : client, devis « Accepté », commande CMD-…, message « Paiement de … enregistré », reçu R-…, facture FAC-…. Le formateur vérifie le paiement dans « Encaissements ». Réussi : le jalon J7 est atteint pour cette personne.

## Graphiste PAO

- Compte : `demo.seydou`
- Durée : 4 h
- À la fin, la personne sait : Contrôler un fichier, envoyer un BAT, monter une feuille, et faire un devis ou une commande.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter et se repérer | Se connecter et trouver l'aide | Le menu replié : « Fichiers et BAT », « Mes travaux PAO », « Aide ». |
| 08:30 | Le contrôle des fichiers | Contrôler les fichiers d'un travail | Exercice 1. |
| 09:00 | Mon tableau PAO et le BAT | Mon tableau PAO et le BAT | Exercice 2. |
| 09:30 | Le montage | Monter un fichier sur une feuille | Exercice 3. |
| 10:00 | Demandes de conception et créations du site | Demandes de conception · Les créations du Studio textile | Exercice 4. |
| 10:30 | Pause, puis devis et commandes | Faire un devis · Transformer le devis en commande · Suivre les devis et commandes | Exercice 5. |
| 11:00 | La facture ; ce que le graphiste ne voit pas | Facturer, imprimer, envoyer | Les paiements ne s'affichent pas ; « Encaisser » seulement si la Direction ajoute « Encaissements » sur la fiche. |
| 11:30 | Exercice final | — | Seul, sans aide. |

### Exercices

1. **Contrôler un fichier** (`demo.seydou`)
   - « Fichiers et BAT » › « Contrôler » sur CMD-D00767 (T-shirt DTF).
   - Cochez les points vérifiés, puis « Enregistrer ».

   Résultat attendu : Le contrôle est gardé : l'historique du travail montre demo.seydou.

2. **Envoyer un BAT** (`demo.seydou`)
   - « Mes travaux PAO » : le travail en cours.
   - « Pause », puis reprise ; « Envoyer le BAT ».

   Résultat attendu : Le travail passe dans la colonne « BAT envoyé ».

3. **Monter une carte de visite** (`demo.seydou`)
   - Depuis un contrôle, « Monter » (ou l'exemple « Exemple carte de visite »).
   - Comparez A4 et SRA3.

   Résultat attendu : « Faisable » ; 10 poses en A4, 25 en SRA3 ; « PDF du montage » se télécharge.

4. **Prendre une demande de conception** (`demo.seydou`)
   - « Voir › » dans le bandeau rouge.
   - Ouvrez DC-D001 (Salon Espoir), « Prendre en charge ».

   Résultat attendu : La demande est « En cours » à votre nom.

5. **Faire un devis** (`demo.seydou`)
   - Devis et commandes › « Nouveau devis » pour Salon Espoir.
   - Un roll-up, « Enregistrer le devis ».

   Résultat attendu : Le devis DEV-… est en « Brouillon ».

### Exercice final : un fichier prêt à imprimer

Seul, sans le formateur et sans le guide ouvert.

1. Contrôler le fichier d'un travail.
2. Envoyer le BAT du travail en cours.
3. Monter le fichier et sortir le PDF du montage.

Résultat attendu : Le contrôle est enregistré, le BAT envoyé, le PDF du montage téléchargé. Sans ouvrir la caisse ni voir de paiement.

## Opérateur production

- Compte : `demo.oumar`
- Durée : 4 h
- À la fin, la personne sait : Faire avancer les travaux de sa machine : démarrer, noter la gâche, terminer.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter et se repérer | Se connecter et trouver l'aide | Le menu : « Production », « Ma file machine ». |
| 08:30 | Le tableau de production | Le tableau de production | Exercice 1. |
| 09:00 | Le planning des machines | Le planning des machines | Exercice 2. |
| 09:30 | Ma file machine, au poste de la machine | Ma file machine : démarrer, terminer, pointer la gâche | Exercice 3. |
| 10:00 | Gâche, incident, réimpression | Ma file machine : démarrer, terminer, pointer la gâche | Exercice 4. |
| 10:30 | Pause, puis le pointage | Pointer à l'arrivée et au départ | Exercice 5. |
| 11:00 | Mon espace : absence, prime, statistiques | Déclarer une absence, demander un congé · Ma prime · Mes statistiques | Lire sa prime estimée et ses points. |
| 11:30 | Exercice final | — | Seul, au poste de la machine. |

### Exercices

1. **Trouver un travail** (`demo.oumar`)
   - « Production » : cherchez CMD-D00764.

   Résultat attendu : L'employé dit dans quelle colonne il est (son étape).

2. **Lire la charge des machines** (`demo.oumar`)
   - Onglet « Planning machines ».

   Résultat attendu : L'employé dit quelle machine est la plus occupée aujourd'hui.

3. **Démarrer et mettre en pause** (`demo.oumar`)
   - Au poste de la machine : « Ma file machine ».
   - Touchez un travail, « Démarrer », puis « Pause », puis « Reprendre ».

   Résultat attendu : L'écran dit « Poste de » la machine ; le travail passe en cours, en pause, puis en cours.

4. **Noter la gâche et terminer** (`demo.oumar`)
   - « + Gâche » sur le travail en cours.
   - « Terminer » : « Quantité bonne », « Rebut », « Confirmer la fin ».

   Résultat attendu : Le travail quitte la file ; les m² du jour augmentent.

5. **Pointer** (`demo.oumar`)
   - Sur le téléphone : « Pointer », l'affiche, la photo.

   Résultat attendu : « Arrivée enregistrée » (après la validation du téléphone par la RH).

### Exercice final : un travail du début à la fin

Seul, sans le formateur et sans le guide ouvert.

1. Au poste de la machine, sans aide : démarrer le travail suivant.
2. Noter une gâche.
3. Le terminer avec la quantité bonne.

Résultat attendu : Le travail est terminé, la gâche est notée, et l'employé sait dire ce qui change hors du poste (« Consultation seulement »).

## Livreur

- Compte : `demo.bakary`
- Durée : 4 h
- À la fin, la personne sait : Faire sa tournée sur le téléphone et prouver chaque remise.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter sur le téléphone | Se connecter et trouver l'aide | La barre du bas : Tournée, Livrer, Pointage, Moi. |
| 08:30 | Ma tournée du jour | Ma tournée du jour | Exercice 1. |
| 09:00 | Charger et partir | Ma tournée du jour | Exercice 2. |
| 09:30 | Remettre la commande | Remettre la commande au client | Exercice 3. |
| 10:00 | L'argent encaissé à la livraison | Remettre la commande au client · Valider l'argent rapporté par le livreur | Le livreur rend l'argent à la caisse, qui le valide le jour même. |
| 10:30 | Pause, puis les commandes à retirer | Commandes à retirer | Exercice 4. |
| 11:00 | Pointer et Mon espace | Pointer à l'arrivée et au départ · Déclarer une absence, demander un congé | Exercice 5. |
| 11:30 | Exercice final | — | Seul, sur le téléphone. |

### Exercices

1. **Lire sa tournée** (`demo.bakary`)
   - « Tournée » : l'arrêt 1.

   Résultat attendu : L'employé dit le client, le créneau et l'adresse.

2. **Charger et partir** (`demo.bakary`)
   - Sur l'arrêt « Préparée » : « Chargée », puis « Je pars ».

   Résultat attendu : L'arrêt passe « En route ».

3. **Faire une remise** (`demo.bakary`)
   - Sur l'arrêt « En route » : « Remise ».
   - Signature, Espèces, montant, « Valider la remise ».

   Résultat attendu : La page de livraison terminée s'affiche ; à la boutique, la caisse voit l'encaissement à valider.

4. **Relancer un client** (`demo.bakary`)
   - « À livrer ou retirer » : une commande prête depuis 2 jours ou plus.
   - « WhatsApp » (ne pas envoyer pendant la formation).

   Résultat attendu : WhatsApp s'ouvre avec le message prêt.

5. **Pointer** (`demo.bakary`)
   - « Pointage » en bas, l'affiche, la photo.

   Résultat attendu : « Arrivée enregistrée » (après la validation du téléphone par la RH).

### Exercice final : une livraison complète

Seul, sans le formateur et sans le guide ouvert.

1. Seul : « Chargée », « Je pars », « Je suis arrivé ».
2. « Remise » avec signature et encaissement en espèces.
3. Retour à la boutique : la caisse valide l'argent.

Résultat attendu : Livraison terminée, encaissement validé par la caisse avec « Valider l'encaissement ».

## Entretien

- Compte : `demo.awa`
- Durée : 2 h
- À la fin, la personne sait : Faire les tâches d'entretien du jour et les valider par une photo.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter sur le téléphone | Se connecter et trouver l'aide | La barre du bas : Accueil, Entretien, Pointage, Moi. |
| 08:30 | Mon planning d'entretien | Mon planning d'entretien | Exercice 1. |
| 09:00 | Pointer et Mon espace | Pointer à l'arrivée et au départ · Déclarer une absence, demander un congé | Exercice 2. |
| 09:30 | Exercice final | — | Seul, sur une vraie tâche de la journée. |

### Exercices

1. **Valider une tâche** (`demo.awa`)
   - Ouvrez la tâche « Ménage de la boutique ».
   - Cochez les étapes, « Prendre la photo et valider ».

   Résultat attendu : La tâche est « fait à … · photo » dans le planning, avec ses points.

2. **Déclarer une absence** (`demo.awa`)
   - « Moi » › « Déclarer une absence ».
   - Dates, motif, « Envoyer à la RH ».

   Résultat attendu : La demande apparaît dans « Mes demandes ».

### Exercice final : une tâche réelle

Seul, sans le formateur et sans le guide ouvert.

1. Seule : la tâche suivante du planning, ses étapes, la photo.

Résultat attendu : La tâche est validée à l'heure, avec la photo.

## Direction

- Compte : `demo.kadiatou`
- Durée : 4 h
- À la fin, la personne sait : Suivre l'activité, valider, gérer les accès et les réglages.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Le tableau de bord | Lire le tableau de bord | Exercice 1. |
| 08:30 | Les opérations à valider | Valider les opérations « À valider » | Exercice 2. |
| 09:00 | Utilisateurs et droits | Donner un accès à un employé · Ajouter ou retirer un droit à une personne | Exercices 3 et 4. |
| 09:30 | Les réglages | Changer un réglage | Exercice 5. |
| 10:00 | Les finances | Vue d'ensemble des finances · Suivre les encaissements | Lire le mois : soldes, facturé, encaissé, dépenses, marge. |
| 10:30 | Pause, puis sauvegardes et synchronisation | Sauvegardes et synchronisation | Exercice 6. |
| 11:00 | Import des données de départ | Importer les données de départ | Exercice 7. |
| 11:30 | Exercice final | — | Seul. |

### Exercices

1. **Lire le tableau de bord** (`demo.kadiatou`)
   - « Accueil » › « Tableau de bord ».

   Résultat attendu : La personne dit le CA facturé du mois et les créances clients.

2. **Valider et rejeter** (`demo.kadiatou`)
   - Finances › « À valider ».
   - Cochez le chèque de Pharmacie Faso, « Valider la sélection ».
   - Cochez une autre ligne, « Rejeter » avec un motif.

   Résultat attendu : Les deux lignes quittent la liste ; l'auteur du rejet est prévenu.

3. **Créer un accès** (`demo.kadiatou`)
   - Réglages › « Utilisateurs et rôles » › « + Nouvel utilisateur ».
   - Rôle « Caisse et commercial », « Créer et envoyer l'accès ».

   Résultat attendu : Le mot de passe temporaire s'affiche, avec le bouton WhatsApp.

4. **Ajouter un droit à une personne** (`demo.kadiatou`)
   - Fiche de demo.aissata (Graphiste PAO) : cochez « Encaissements », « Enregistrer ».
   - Vérifiez avec demo.aissata : « Caisse » s'ouvre.
   - Décochez, « Enregistrer ».

   Résultat attendu : « Droits de … enregistrés. » ; la case est marquée « ajouté pour cette personne », puis revient à « non accordé ».

5. **Trouver un réglage** (`demo.kadiatou`)
   - Réglages : tapez « Orange Money » dans « Que voulez-vous régler ? ».

   Résultat attendu : La tuile Paiements s'ouvre.

6. **Vérifier les sauvegardes** (`demo.kadiatou`)
   - Tuile « Sauvegardes ».

   Résultat attendu : La personne lit la dernière sauvegarde réussie et sait dire s'il y a une copie hors de la boutique.

7. **Préparer l'import des clients** (`demo.kadiatou`)
   - Tuile « Import des données » › Clients › « Modèle ».

   Résultat attendu : Le modèle Excel se télécharge ; rien n'est importé sans l'aperçu.

### Exercice final : une journée de direction

Seul, sans le formateur et sans le guide ouvert.

1. Valider les opérations en attente de la journée.
2. Ajouter « Trésorerie et soldes » à une personne précise, vérifier, puis le retirer.
3. Dire l'état des sauvegardes et de la synchronisation.

Résultat attendu : Aucune opération en attente oubliée ; le droit ajouté puis retiré ; l'état des sauvegardes connu.

## Valider le jalon J7

Le jalon est atteint quand une personne nouvelle, après sa demi-journée « Caisse et commercial », réussit seule l'exercice final J7 : un client, un devis accepté, la commande, l'acompte encaissé avec son reçu et la facture. Le formateur le vérifie dans « Encaissements » et signe la fiche.

## Après la formation

- La Direction crée le vrai compte de la personne (fiche « Donner un accès à un employé ») sur l'ERP de la boutique, avec son rôle. Un droit en plus se coche sur sa fiche (par exemple « Encaissements » pour un graphiste).
- La personne garde son guide imprimé ; l'aide de l'application (« ? Aide ») suit toujours la dernière version.
- Le formateur remet la base de formation à zéro (`app:demo`) avant la séance suivante.
