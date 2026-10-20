# Formation d'une demi-journée par rôle

But (jalon J7) : après une demi-journée, une nouvelle personne fait seule un devis et un encaissement, sans aide. Chaque rôle a son plan en blocs de 30 minutes, des exercices sur les comptes de démonstration et un exercice final.

Les mêmes plans s'impriment depuis l'application : « ? Aide » › « Fiches du formateur » (adresse `/aide/formation`, Direction seulement). Ils viennent de `src/Service/Guide/Formation.php` ; ce fichier les reprend mot pour mot (un test le vérifie).

## Préparer la séance

- **Jamais sur la base de l'imprimerie.** La formation se fait sur une installation de formation (un poste ou un serveur de test, `APP_ENV=dev`, `APP_EN_LIGNE=0`), sans clés SMS, e-mail ni WhatsApp dans son `.env.local`. `app:demo` refuse de tourner en production.
- Remettre les exercices à zéro avant chaque séance : `php bin/console app:demo` (relançable : l'ancienne démo est d'abord enlevée, voir `docs/donnees-demo.md`). Par exemple la commande du site CMD-DC0005 redevient « à confirmer ».
- Les comptes : `demo.aminata` (caisse), `demo.seydou` et `demo.aissata` (graphistes), `demo.oumar` (machines), `demo.bakary` (livreur), `demo.awa` (entretien), `demo.kadiatou` (direction). Mot de passe `demo1234`.
- Imprimer pour la personne formée son guide : connecté avec son compte de démonstration, « ? Aide » › « Imprimer mon guide ». Le formateur imprime sa fiche dans « Fiches du formateur » (seulement les pages du rôle formé).
- Le livreur, l'opérateur et l'agent d'entretien travaillent sur un téléphone : prévoir un téléphone sur le Wi-Fi de l'imprimerie. Pour le pointage, la RH valide d'abord le téléphone : Équipe › Pointage › « Points et appareils », onglet « Téléphones à valider », « Autoriser ».
- Graphiste, exercice « Déposer les fichiers d'une commande » : préparer sur le poste quelques fichiers d'exemple (PDF ou images) nommés avec les formats qui manquent à CMD-PQ0378, par exemple « etiquette_5,5x5cm.pdf » et « etiquette_5,5x4cm.pdf ». La commande et les autres CMD-PQ… viennent de `app:prepresse:demo`, lancée par `app:demo`.
- Les adresses des écrans montrent un code à la place du numéro (`/commandes/ADWlzbNUR`, voir `docs/ids-chiffres.md`) : on retrouve une commande ou une facture par la recherche, pas en tapant son numéro dans l'adresse. Les anciens liens à numéro s'ouvrent encore.
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
| 09:30 | La commande | Transformer le devis en commande · Prendre une commande sans devis · Suivre les devis et commandes · Vendre du matériel : « Hors imprimerie » | Exercice 4. Montrer la case « Hors imprimerie » d'un devis de matériel. |
| 10:00 | L'encaissement et la facture | Encaisser un paiement · Facturer, imprimer, envoyer · Encaisser depuis la facture | Exercices 5 à 7. |
| 10:30 | Pause, puis la liste des factures, l'avoir et les dépenses | La liste des factures et les nouveaux documents · Corriger une facture validée : l'avoir · Les documents liés d'une commande · Enregistrer une dépense | Exercices 8 et 9. |
| 11:00 | Le virement, le site, le client et le livreur | Verser la caisse à la banque · Confirmer une commande passée en ligne · Envoyer l'accès à l'espace client · Valider l'argent rapporté par le livreur · Mes actions de caisse | Exercices 10 à 13. |
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
   - « Enregistrer le brouillon », puis « Aperçu PDF ».

   Résultat attendu : Le devis DEV-… est en « Brouillon », pour 10 500 F, et le PDF s'ouvre.

4. **Passer le devis en commande** (`demo.aminata`)
   - « Envoyer au client » (le devis passe en « Envoyé »).
   - Quand le client accepte : « Convertir en commande », puis confirmer.

   Résultat attendu : La commande CMD-… s'ouvre avec la frise, de « Confirmée » à « Livrée ».

5. **Encaisser un acompte** (`demo.aminata`)
   - Menu « Caisse » : le client, sa commande.
   - 5 000 F, Espèces, compte « Caisse imprimerie ».
   - « Encaisser et imprimer le reçu ».

   Résultat attendu : Message « Paiement de 5 000 FCFA enregistré », reçu R-…, et « Encaissé » de la caisse du jour augmente de 5 000 F.

6. **Faire la facture** (`demo.aminata`)
   - Sur la commande, « … » (autres actions) › « Facturer ».
   - Vérifiez le brouillon, puis « Valider et numéroter » et « Valider ».

   Résultat attendu : La facture validée s'ouvre avec son numéro définitif et « Validée » ; « Envoyer sur WhatsApp » et « Enregistrer un paiement » sont proposés.

7. **Encaisser le reste depuis la facture** (`demo.aminata`)
   - Sur la facture de l'exercice 6, « Enregistrer un paiement ».
   - Orange Money, le reste prérempli, une référence fictive, « Reçu à distance : à confirmer par la trésorière » cochée.
   - « Enregistrer · générer le reçu ».

   Résultat attendu : La facture revient avec « Paiement de … enregistré » et « à confirmer » ; le bouton « Envoyer le reçu par WhatsApp (en cours de validation) » est prêt (ne pas l'envoyer : le numéro est fictif).

8. **Préparer un avoir** (`demo.aminata`)
   - Encaissements › onglet « Facturation » : retrouvez la facture de l'exercice 6.
   - « Créer un avoir », « Erreur de prix », une quantité à créditer, « Garder pour une prochaine commande ».
   - Lisez « Effet sur le compte client », puis « Annuler ».
   - Ouvrez « Voir tous les documents liés ».

   Résultat attendu : L'employé dit le total de l'avoir et le solde dû après, sans valider ; il sait qu'une facture validée ne se modifie jamais. Les documents liés montrent devis, commande, facture et paiement.

9. **Saisir une dépense** (`demo.aminata`)
   - « Aller à… » › « Nouveau › Dépense ».
   - Photo d'un reçu, objet « Carburant moto livraison », Divers, 2 000 F, payé avec « Caisse imprimerie ».
   - « Enregistrer la dépense ».

   Résultat attendu : Message : le décaissement attend sa validation dans « À valider ». Le formateur (demo.kadiatou) la voit dans Finances › À valider.

10. **Verser la caisse à la banque** (`demo.aminata`)
   - Caisse › « Virement ».
   - 50 000 F, de « Caisse imprimerie » vers « Banque BDM », un libellé.
   - « Enregistrer le mouvement », « Oui, enregistrer ».

   Résultat attendu : Retour à la caisse avec « Virement de 50 000 F … enregistré ». Aucun solde n'est affiché.

11. **Confirmer une commande du site** (`demo.aminata`)
   - Ouvrez la commande CMD-DC0005 (Salon Espoir).
   - « Vérifier et confirmer » : la ligne « Affiche A3 · A2 » est à 0 F, son prix est confirmé par l'atelier. Saisissez 500 F dans « Prix unitaire ».
   - « Confirmer la commande ».

   Résultat attendu : Message « La commande a été modifiée avec succès. » ; le bandeau « Commande passée par le client dans son espace » a disparu : la commande part à l'atelier.

12. **Envoyer l'accès à l'espace client** (`demo.aminata`)
   - Fiche du client créé à l'exercice 2 (pas une fiche DEMO-).
   - « Envoyer l'accès à l'espace client ».

   Résultat attendu : « Accès prêt pour … » avec l'identifiant, le code et la durée de 48 h. Ne pas l'envoyer : le numéro est fictif.

13. **Valider l'argent d'un livreur** (`demo.aminata`)
   - Le formateur fait une remise avec encaissement en espèces (demo.bakary, sur téléphone).
   - Planning des livraisons › la livraison remise › « Compte qui reçoit » › « Valider l'encaissement ».

   Résultat attendu : L'encaissement n'est plus provisoire ; « À encaisser à la livraison » le compte comme encaissé.

### Exercice final J7 : un client, de bout en bout

Seul, sans le formateur et sans le guide ouvert.

1. Seul, sans aide et sans le guide : un nouveau client particulier.
2. Un devis : bâche 200 × 100 cm avec œillets.
3. Le devis accepté, la commande créée.
4. Un acompte de 50 % en espèces, le reçu imprimé.
5. La facture, validée et numérotée.

Résultat attendu : En 20 minutes : client, devis envoyé puis converti, commande CMD-…, message « Paiement de … enregistré », reçu R-…, facture FAC-…. Le formateur vérifie le paiement dans « Encaissements ». Réussi : le jalon J7 est atteint pour cette personne.

## Graphiste PAO

- Compte : `demo.seydou`
- Durée : 4 h
- À la fin, la personne sait : Déposer et contrôler les fichiers d'une commande, relancer ceux qui manquent, envoyer un BAT, monter une feuille, et faire un devis ou une commande.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter et se repérer | Se connecter et trouver l'aide | Le menu replié : « Fichiers et BAT », « Mes travaux PAO », « Aide ». |
| 08:30 | La liste de la prépresse, le dépôt et le contrôle | Contrôler les fichiers d'un travail · Déposer les fichiers d'une commande | Exercices 1 à 3. |
| 09:00 | Le tableau, les relances et les lignes retirées | Le tableau de la prépresse · Relancer les fichiers manquants · Retirer de la liste une ligne sans fichier | Exercices 4 et 5. |
| 09:30 | Mon tableau PAO et le BAT | Mon tableau PAO et le BAT | Exercice 6. |
| 10:00 | Le montage | Monter un fichier sur une feuille | Exercice 7. |
| 10:30 | Pause, puis demandes de conception et créations du site | Demandes de conception · Les créations du Studio textile | Exercice 8. |
| 11:00 | Devis, commandes et facture ; ce que le graphiste ne voit pas | Faire un devis · Transformer le devis en commande · Suivre les devis et commandes · Facturer, imprimer, envoyer | Exercice 9. Les paiements ne s'affichent pas ; « Encaisser » seulement si la Direction ajoute « Encaissements » sur la fiche. |
| 11:30 | Exercice final | — | Seul, sans aide. |

### Exercices

1. **Déposer les fichiers d'une commande** (`demo.seydou`)
   - « Fichiers et BAT », onglet « Sans fichier » : « Déposer » sur CMD-PQ0378 (Fofana, étiquettes).
   - Glissez les fichiers d'exemple donnés par le formateur (le format est dans leur nom, par exemple « etiquette_5,5x5cm.pdf »).
   - Vérifiez « Associé par », puis « Enregistrer · passer en « À contrôler » ».

   Résultat attendu : Chaque fichier est associé à un format ; la commande passe dans l'onglet « À contrôler » et sa barre montre les fichiers reçus.

2. **Contrôler un fichier** (`demo.seydou`)
   - « Fichiers et BAT » › onglet « Tout » : cherchez CMD-D00767 (T-shirt DTF), dépliez-la, « Contrôler ».
   - Cochez les points vérifiés, puis « Enregistrer ».

   Résultat attendu : Le contrôle est gardé : l'historique du travail montre demo.seydou.

3. **Envoyer en production** (`demo.seydou`)
   - Onglet « À contrôler » : « Contrôler » sur le kakémono à livrer aujourd'hui.
   - « Tout cocher », puis « Valider et envoyer en production ».

   Résultat attendu : Retour à la liste de la prépresse avec « Document transmis à la production (ordre …) » ; la commande a quitté « À contrôler ».

4. **Relancer un client** (`demo.seydou`)
   - « Relances » : cochez CMD-PQ0378 (Fofana), « Relancer la sélection ».
   - Lisez le message, sans l'envoyer.

   Résultat attendu : WhatsApp s'ouvre avec le message qui liste les formats manquants ; la relance est notée sur la ligne.

5. **Retirer une ligne et la remettre** (`demo.seydou`)
   - « Liste », onglet « Sans fichier » : « Retirer » sur CMD-PQ0379 (Comptoir), puis « Retirer ».
   - « Retirées · 1 », puis « Remettre dans la liste ».

   Résultat attendu : La commande quitte la liste, apparaît dans « Retirées » avec la date et le nom, puis revient ; elle n'a été ni clôturée ni supprimée.

6. **Envoyer un BAT** (`demo.seydou`)
   - « Mes travaux PAO » : le travail en cours.
   - « Pause », puis reprise ; « Envoyer le BAT ».

   Résultat attendu : Le travail passe dans la colonne « BAT envoyé ».

7. **Monter une carte de visite** (`demo.seydou`)
   - Depuis un contrôle, « Monter » (ou l'exemple « Exemple carte de visite »).
   - Comparez A4 et SRA3.

   Résultat attendu : « Faisable » ; 10 poses en A4, 25 en SRA3 ; « PDF du montage » se télécharge.

8. **Prendre une demande de conception** (`demo.seydou`)
   - « Voir › » dans le bandeau rouge.
   - Ouvrez DC-D001 (Salon Espoir), « Prendre en charge ».

   Résultat attendu : La demande est « En cours » à votre nom.

9. **Faire un devis** (`demo.seydou`)
   - Devis et commandes › « Nouveau devis » pour Salon Espoir.
   - Un roll-up, « Enregistrer le brouillon ».

   Résultat attendu : Le devis DEV-… est en « Brouillon ».

### Exercice final : un fichier prêt à imprimer

Seul, sans le formateur et sans le guide ouvert.

1. Déposer les fichiers d'une commande sans fichier.
2. Contrôler un fichier et l'envoyer en production.
3. Envoyer le BAT du travail en cours.
4. Monter le fichier et sortir le PDF du montage.

Résultat attendu : Les fichiers sont associés, le contrôle validé (« Document transmis à la production »), le BAT envoyé, le PDF du montage téléchargé. Sans ouvrir la caisse ni voir de paiement.

## Opérateur production

- Compte : `demo.oumar`
- Durée : 4 h
- À la fin, la personne sait : Faire avancer les travaux de sa machine : démarrer, noter la gâche, terminer.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Se connecter et se repérer | Se connecter et trouver l'aide | Le menu : « Production », « Ma file machine ». |
| 08:30 | Le tableau de production | Le tableau de production | Exercice 1. |
| 09:00 | Le planning des machines | Le planning des machines | Exercice 2. |
| 09:30 | Ma file machine, au poste de la machine | Poste machiniste : vérifier, estimer, imprimer, étiqueter | Exercice 3. |
| 10:00 | Gâche, incident, réimpression | Poste machiniste : vérifier, estimer, imprimer, étiqueter | Exercice 4. |
| 10:30 | Pause, puis le pointage | Pointer à l'arrivée et au départ | Exercice 5. |
| 11:00 | Mon espace : absence, prime, statistiques | Déclarer une absence, demander un congé · Ma prime · Mes statistiques | Lire sa prime estimée et ses points. |
| 11:30 | Exercice final | — | Seul, au poste de la machine. |

### Exercices

1. **Trouver un travail** (`demo.oumar`)
   - « Production » : cherchez CMD-D00764.

   Résultat attendu : L'employé dit dans quelle colonne il est (son étape).

2. **Lire la charge des machines** (`demo.oumar`)
   - Menu « Atelier », onglet « Planning ».

   Résultat attendu : L'employé dit quelle machine est la plus occupée cette semaine.

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

   Résultat attendu : La page de livraison terminée s'affiche ; à l'imprimerie, la caisse voit l'encaissement à valider.

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
3. Retour à l'imprimerie : la caisse valide l'argent.

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
   - Ouvrez la tâche « Ménage de l'accueil ».
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
- À la fin, la personne sait : Suivre l'activité, valider, gérer les accès, les réglages et les achats aux fournisseurs.

| Heure | Bloc | Fiches du guide | À faire |
|---|---|---|---|
| 08:00 | Le tableau de bord et les statistiques | Lire le tableau de bord · Statistiques : imprimerie et hors imprimerie | Exercices 1 et 2. |
| 08:30 | Les opérations à valider | Valider les opérations « À valider » | Exercice 3. |
| 09:00 | Utilisateurs et droits | Donner un accès à un employé · Ajouter ou retirer un droit à une personne | Exercices 4 et 5. |
| 09:30 | Les réglages | Changer un réglage | Exercice 6. |
| 10:00 | Les finances | Vue d'ensemble des finances · Suivre les encaissements · La liste des factures et les nouveaux documents · Corriger une facture validée : l'avoir | Lire le mois : soldes, facturé, encaissé, dépenses, marge ; la liste des factures et les avoirs à valider. |
| 10:30 | Pause, puis fournisseurs et achats | Suivre les fournisseurs · Passer une commande d'achat · Réceptionner une commande d'achat | Exercices 7 à 9. |
| 11:00 | Sauvegardes, synchronisation et import | Sauvegardes et synchronisation · Importer les données de départ | Exercices 10 et 11. |
| 11:30 | Exercice final | — | Seul. |

### Exercices

1. **Lire le tableau de bord** (`demo.kadiatou`)
   - « Accueil » › « Tableau de bord ».

   Résultat attendu : La personne dit le CA facturé du mois et les créances clients.

2. **Comparer imprimerie et hors imprimerie** (`demo.kadiatou`)
   - Accueil › « Statistiques et rapports ».
   - Choisissez « Activité : Tout ».

   Résultat attendu : La personne lit la carte « Imprimerie / Hors imprimerie / Total » et sait que les objectifs et les primes ne comptent que l'imprimerie.

3. **Valider et rejeter** (`demo.kadiatou`)
   - Finances › « À valider ».
   - Cochez le chèque de Pharmacie Faso, « Valider la sélection ».
   - Cochez une autre ligne, « Rejeter » avec un motif.

   Résultat attendu : Les deux lignes quittent la liste ; l'auteur du rejet est prévenu.

4. **Créer un accès** (`demo.kadiatou`)
   - Réglages › « Utilisateurs et rôles » › « + Nouvel utilisateur ».
   - Rôle « Caisse et commercial », « Créer et envoyer l'accès ».

   Résultat attendu : Le mot de passe temporaire s'affiche, avec le bouton WhatsApp.

5. **Ajouter un droit à une personne** (`demo.kadiatou`)
   - Fiche de demo.aissata (Graphiste PAO) : cochez « Encaissements », « Enregistrer ».
   - Vérifiez avec demo.aissata : « Caisse » s'ouvre.
   - Décochez, « Enregistrer ».

   Résultat attendu : « Droits de … enregistrés. » ; la case est marquée « ajouté pour cette personne », puis revient à « non accordé ».

6. **Trouver un réglage** (`demo.kadiatou`)
   - Réglages : tapez « Orange Money » dans « Que voulez-vous régler ? ».

   Résultat attendu : La tuile Paiements s'ouvre.

7. **Lire un fournisseur** (`demo.kadiatou`)
   - Stock et achats › « Fournisseurs » › Dakar Signs.

   Résultat attendu : La personne dit son délai réel moyen, sa part à l'heure, ce qui lui est dû, et lit le « Conseil » sur le délai.

8. **Préparer une commande d'achat** (`demo.kadiatou`)
   - Stock et achats › « Fournisseurs » : touchez une « Prochaine commande » en rouge.
   - Lisez le total rendu Bamako, le coût de revient et la livraison prévue, puis « Brouillon ».

   Résultat attendu : La commande CA-… est enregistrée en brouillon chez le bon fournisseur, avec l'article en alerte ; rien n'est envoyé.

9. **Lire une réception avec casse** (`demo.kadiatou`)
   - Stock et achats › « Réceptions » : « Voir » sur la réception de Dakar Signs marquée « paiement bloqué ».

   Résultat attendu : La personne lit le rouleau écrasé, la réclamation ouverte et « paiement du solde bloqué » ; elle sait qu'il se débloque quand la réclamation est remplacée, déduite ou close.

10. **Vérifier les sauvegardes** (`demo.kadiatou`)
   - Tuile « Sauvegardes ».

   Résultat attendu : La personne lit la dernière sauvegarde réussie et sait dire s'il y a une copie hors de l'imprimerie.

11. **Préparer l'import des clients** (`demo.kadiatou`)
   - Tuile « Import des données » › Clients › « Modèle ».

   Résultat attendu : Le modèle Excel se télécharge ; rien n'est importé sans l'aperçu.

### Exercice final : une journée de direction

Seul, sans le formateur et sans le guide ouvert.

1. Valider les opérations en attente de la journée.
2. Ajouter « Trésorerie et soldes » à une personne précise, vérifier, puis le retirer.
3. Dire ce qui est dû aux fournisseurs et quel paiement est bloqué.
4. Dire l'état des sauvegardes et de la synchronisation.

Résultat attendu : Aucune opération en attente oubliée ; le droit ajouté puis retiré ; le paiement bloqué expliqué ; l'état des sauvegardes connu.

## Valider le jalon J7

Le jalon est atteint quand une personne nouvelle, après sa demi-journée « Caisse et commercial », réussit seule l'exercice final J7 : un client, un devis accepté, la commande, l'acompte encaissé avec son reçu et la facture. Le formateur le vérifie dans « Encaissements » et signe la fiche.

## Après la formation

- La Direction crée le vrai compte de la personne (fiche « Donner un accès à un employé ») sur l'ERP de l'imprimerie, avec son rôle. Un droit en plus se coche sur sa fiche (par exemple « Encaissements » pour un graphiste).
- La personne garde son guide imprimé ; l'aide de l'application (« ? Aide ») suit toujours la dernière version.
- Le formateur remet la base de formation à zéro (`app:demo`) avant la séance suivante.
