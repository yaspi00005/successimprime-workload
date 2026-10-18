# Devis : faire, envoyer, relancer, transformer

Ce que fait l'application avec un devis, du brouillon à la commande, côté imprimerie et côté client. Maquettes GC03 (liste), GC04 (saisie), DT03 (fiche), DT01 et DT04 (espace client).

## Le chemin d'un devis

1. **Brouillon** : la personne fait le devis (`/devis/new`) et clique « Enregistrer le brouillon ». Le client ne voit rien.
2. **Envoyé** : « Envoyer au client » (saisie ou fiche). Le devis part par les moyens cochés sous « Envoyer par » :
   - **espace client** : il apparaît dans « Mes devis » du client (après la synchronisation), avec le message facultatif écrit sous les canaux, qui arrive aussi dans ses messages ;
   - **WhatsApp** : voir plus bas ;
   - **e-mail** : le PDF en pièce jointe, si la fiche du client a une adresse.
   La validité (7, 15, 30 ou 60 jours) part du jour de l'envoi.
3. **Vu** : le client a ouvert le devis, dans son espace ou par le lien du message (`/d/…`). Deux ouvertures à moins de 30 minutes comptent pour une. Les ouvertures faites par une personne de l'atelier connectée ne comptent pas.
4. **Réponse du client**, dans son espace :
   - **Accepter** : la commande est créée tout de suite, au prix du devis, sans ressaisie. C'est une commande en ligne « à confirmer » par l'imprimerie, comme une commande passée dans l'espace. Puis la personne qui paie part au paiement de l'acompte par Orange Money. Sinon, l'acompte attend la personne qui paie pour l'entreprise.
   - **Demander une modification** : son texte est noté sur le devis et arrive dans les messages. L'imprimerie fait une nouvelle version.
   - **Refuser** : avec un motif (le prix, le délai, fait ailleurs, projet abandonné, autre) et une précision facultative.
5. **Expiré** : la validité est passée sans réponse. La tâche planifiée le marque (voir plus bas). Le client peut demander une nouvelle version.
6. **Accepté → commande** : le devis montre la commande (« Voir CMD »).

À l'imprimerie, un client qui répond par téléphone ou au comptoir :
- **Convertir en commande** (fiche du devis) : le devis passe « accepté » (« noté à l'imprimerie ») puis devient la commande.
- **Le client a refusé** (fiche, sous « Suivi ») : le refus est noté avec son motif.

## Versions

« Nouvelle version » (fiche) ou « Modifier » sur un devis déjà envoyé : le devis repasse en brouillon, version suivante. Le client garde la version d'avant jusqu'au nouvel envoi.

Chaque envoi note la version : date, total, lignes (désignation, quantité, total) et la demande du client qui l'a fait naître. Le coût n'y est jamais. Ces versions servent à :
- la carte « Versions » de la fiche, avec « ajout de 3 × Totem » ou la demande du client ;
- « Ce qui a changé depuis la version 1 » dans l'espace client.

Un devis accepté ne se modifie plus : on modifie sa commande.

## Relances WhatsApp

Trois jours après l'envoi, un devis pas ouvert ou pas accepté est à relancer, une fois par version, tant qu'il est valable.
- **Clé Meta posée** (docs/messagerie-marketing.md) : la tâche `app:devis:relances` envoie elle-même le message (modèle « document », avec le PDF), entre 8 h et 19 h.
- **Clé vide** (cas actuel) : le devis apparaît dans « À relancer aujourd'hui » de la liste, avec le bouton « Relancer ». Le bouton ouvre WhatsApp avec le message déjà écrit (lien wa.me). La personne n'a plus qu'à appuyer sur « Envoyer ». La relance est notée dans le suivi.

L'envoi par WhatsApp suit la même règle : sans clé, après « Envoyer au client », un bandeau « Ouvrir WhatsApp » donne le message prêt. Les messages WhatsApp ne contiennent jamais de montant : le prix se lit derrière le lien. Les clients de démonstration (DEMO-) ne reçoivent jamais rien automatiquement.

Tâche planifiée de l'**ERP local**, chaque heure :
- Windows : `scripts\windows\installer_tache_devis_relances.bat` ;
- Linux ou Mac : `0 * * * * cd /chemin/du/projet && php bin/console app:devis:relances -q >> var/log/devis-relances.log 2>&1`.

`--a-blanc` montre ce qui serait fait sans rien changer. Sur le site en ligne, la commande refuse de tourner.

## Ce que chacun voit

Imprimerie (ERP) :
- Liste, saisie et fiche : droit « Devis ».
- **Rentabilité** (coût estimé, marge, taux, alerte sous 25 %) : seulement avec le droit « Marges et coûts ». Le client ne la voit jamais : elle n'est pas dans le PDF, ni dans les versions, ni dans la synchronisation.
- Solde impayé du client sur la fiche : le montant avec le droit « Paiements », sinon le nombre de commandes pas encore payées.

Espace client :
- Montants (prix, total, acompte, PDF) : pour qui voit les montants (particulier, valideur, payeur). Un demandeur voit les lignes sans prix. Un compte « Lecture · suivi » n'ouvre pas les devis.
- Accepter, refuser, demander une modification : pour qui valide (valideur, titulaire, particulier).
- Payer l'acompte : pour qui paie.

## Écrans

- **Liste** (`/devis/`) : barre « Devis du mois par statut », taux d'acceptation (acceptés sur envoyés du mois), montant en attente de réponse (tous les devis envoyés ou vus), nombre à relancer aujourd'hui, puces par statut, recherche sur tous les mois. Flèches pour changer de mois. Action de chaque ligne : Terminer (brouillon), Relancer, Ouvrir, Voir la commande.
- **Saisie** (`/devis/new`, `/devis/{id}/edit`) : client, contact, validité, délai, objet ; lignes depuis le catalogue ou « + Ligne libre » (pose, déplacement) ; calculateur grand format (largeur × hauteur × prix au m² de la configuration, au tarif du client) ; conditions (acompte 0, 30, 50 ou 100 %, solde, fichier, garantie) ; envoyer par ; total, remise, code promo, acompte ; rentabilité « Interne ». Sur téléphone, 3 étapes.
- **Fiche** (`/devis/{id}`) : lignes et totaux, versions, suivi (envoyé par, ouvert par, ouvertures suivantes, demande du client, relances, relance prévue, expiration), carte du client (nombre de devis et d'acceptés, solde impayé), rentabilité, responsable.
- **Espace client** (`/espace/devis`, `/espace/devis/{id}`) : lignes, version, « valable jusqu'au », total, acompte, délai ; « Suivi du devis » (Demandé, Version n envoyée, Votre réponse, Commande) ; ce qui a changé ; le dernier message de la conversation qui cite le devis ; Accepter et payer l'acompte, Demander une modification, Refuser, PDF, Message.
- **Lien du message** (`/d/{jeton}`) : compte l'ouverture puis mène au devis dans l'espace (connexion si besoin). `/d/{jeton}/pdf` : le PDF seul.

## Synchronisation

Le devis descend du local avec son suivi (versions, validité, conditions, envoi). Remontent du site, car ce sont des décisions du client :
- les ouvertures ;
- l'acceptation, avec la commande créée en ligne ;
- le refus et son motif ;
- la demande de modification.

Les relances restent au local. Voir docs/synchronisation.md.

## Dans le code

- Entité : `Devis` avec `SuiviDevisTrait`. Migration `Version20261018172000`.
- Services `App\Service\Devis\` :
  - `SuiviDevis` : règles, sans base, testées ;
  - `EnvoiDevis` : envoi et relances ;
  - `AcceptationDevis` : réponse du client ;
  - `RentabiliteDevis` ;
  - `ListeDevis` ;
  - `PdfDevis`.
- Contrôleurs : `DevisController` (imprimerie), `EspaceDevisController` (espace client), `DevisLienController` (`/d/…`).
- Commandes : `app:devis:relances`, `app:devis:demo` (lancée par `app:demo`).
- Gabarits : `devis/index`, `devis/_form`, `devis/show`, `espace/app/devis`, `espace/app/devis_detail`.
- Styles et scripts : `public/css/devis.css`, `public/css/espace-devis.css`, `public/js/saisie-devis.js`.
- Tests : `tests/Unit/Service/Devis/SuiviDevisTest.php`.
