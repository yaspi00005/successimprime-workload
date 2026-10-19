# Prépresse : liste par commande, dépôt groupé, tableau et relances

Maquettes PQ01 à PQ04 du 19/10/2026 (`maquettes/prepresse-affichage-1910`). L'écran « Prépresse et BAT » (Atelier) montre maintenant une ligne par commande, et non plus une ligne par format. Le contrôle d'un fichier, le BAT et les retours du machiniste ne changent pas : ils restent sur la page « Contrôler » de chaque format (voir docs/prepresse-retouche.md et docs/poste-machiniste.md).

Accès : le rôle Prépresse (`ROLE_PREPRESSE`), comme avant. Le dossier partagé est aussi réservé à ce rôle.

## La liste (PQ01)

Adresse : `/pre-presse/`.

- **Cinq indicateurs** en haut : commandes sans fichier (dont celles qui attendent depuis plus de 7 jours), à contrôler (dont celles à livrer aujourd'hui), BAT en attente du client (avec l'ancienneté du plus vieux), retours du machiniste (avec l'heure limite la plus proche), validés aujourd'hui (avec le temps moyen entre le fichier et la validation). Un clic ouvre l'onglet correspondant.
- **Onglets** : Sans fichier · À contrôler · BAT en attente · Retours · Tout. La page s'ouvre sur le premier onglet qui a des commandes. Une commande peut être dans plusieurs onglets (un format sans fichier, un autre à contrôler).
- **Tri** : les plus anciennes d'abord par défaut, ou les plus récentes, ou la livraison la plus proche. **Recherche** : numéro de commande, client ou travail. Dix commandes s'affichent, puis « + N autres ».
- **Chaque commande** montre son numéro, le client, la date, le travail (« Étiquettes vinyle · 6 formats · 150 pièces »), une barre « 2 / 6 fichiers reçus » (un trait par format), l'ancienneté et **une seule action** : Déposer (N), Contrôler, Corriger, Relancer le BAT ou Voir. À côté, une action secondaire : Relancer le client, ou Associer quand la commande n'a pas de client joignable.
- **Client non renseigné** : une commande a toujours un client dans l'ERP. Elle est signalée en rouge quand ce client n'a pas de numéro de téléphone (« Comptoir · sans numéro ») : on ne peut pas le relancer. « Associer » ouvre la commande pour choisir le bon client.
- **Dépliée**, la commande montre ses formats : format, quantité, fichier reçu (ou « glisser ici »), statut et le lien « Contrôler ». On peut glisser un fichier directement sur un format.
- **Petits formats** : quand au moins deux formats de 30 cm ou moins sont sur le même support, un conseil propose de les monter sur une seule planche (lien vers le montage).
- En bas, le nombre de fichiers du **dossier partagé** pas encore rattachés, avec les liens vers le dossier partagé et vers « Fichiers à classer ».

Le son et la fenêtre qui signalent un nouveau fichier à contrôler restent en place (vérification toutes les 30 secondes).

## Déposer les fichiers (PQ02)

Le bouton « Déposer » ouvre une fenêtre. On y glisse plusieurs fichiers d'un coup. Chaque fichier part par morceaux de 5 Mo (reprise si la connexion coupe, 750 Mo au plus par fichier, comme sur la fiche de la commande). Une fois les envois terminés, le serveur associe chaque fichier à un format de la commande :

1. **nom du fichier** : des dimensions écrites dans le nom (« 7,5×8 », « 55x70mm », « 1.2x3m » ; en cm sans unité) qui correspondent à un format. Sûr.
2. **dimensions de l'image** : pour une image, les pixels et la résolution (300 dpi par exemple) ; pour un PDF, la TrimBox de la première page, sinon la MediaBox ; pour un EPS, la BoundingBox. Tolérance de 1,5 mm (ou 2 %), avec ou sans 3 mm de fonds perdus de chaque côté, et l'échelle 1/10 pour les grands formats. Sûr. Une résolution inférieure à 100 dpi (photo de téléphone à 72 dpi) ne compte pas.
3. **proportions proches** : à 3 % près, en préférant un format qui n'a pas encore de fichier. **À vérifier.**
4. **image carrée** : une image carrée va au seul format carré (ou au seul format carré encore sans fichier). Sûr.
5. Sinon : **à choisir**.

La fenêtre affiche pour chaque fichier le format choisi (une liste pour corriger), « Associé par », les dimensions lues (« 650 × 827 px · 300 dpi », « 75 × 80 mm · vectoriel », « 4 032 × 3 024 px · photo ») et le résumé « 2 sûrs · 1 à vérifier · 1 à choisir ». Un fichier laissé sans format n'est pas rattaché : il est retiré du serveur. Fermer la fenêtre sans enregistrer retire aussi les fichiers envoyés.

« Enregistrer · passer en « À contrôler » » rattache les fichiers. La commande passe dans l'onglet « À contrôler ». Chaque rattachement est noté dans le journal d'activité de la commande (qui, quand, quel fichier, quel format).

**Dossier du client** : quand les dossiers clients sont réglés (docs/dossiers-clients.md), une copie de chaque fichier est rangée dans le dossier du client, sous « 02 Fichiers reçus\AAAA-MM ». Le dossier du client est créé s'il n'existe pas. L'ERP copie, sans jamais déplacer ni supprimer un fichier de l'imprimerie, et n'écrase rien (un nom déjà pris reçoit « -2 »).

**Onglet « Dossier partagé »** : la fenêtre propose les fichiers du dossier partagé qui semblent être pour cette commande (numéro de la commande ou d'un de ses travaux, nom du client ou un format dans le nom). Cochés, ils sont associés comme les autres ; à l'enregistrement, ils sont déplacés vers les fichiers de la commande, renommés « commande_format » (`CMD-000378_5,5x7cm.pdf`), et la fiche du fichier garde qui l'a pris, quand, et son nom d'origine.

## Le dossier partagé

Le lien vers la page du dossier partagé est maintenant dans Prépresse (il était dans Réglages › Grille tarifaire). La tâche `app:dossier-partage:importer` tourne chaque minute (docs/fichiers-lourds.md) :

- `CMD-000378_5,5x7.pdf` ou `CMD-000378-10-2026_affiche.pdf` : rattaché à la commande, au format écrit dans le nom. Pour une commande d'un seul format, ou dont un seul format attend encore son fichier, le format n'est pas nécessaire ;
- `123_nom.pdf` : rattaché au travail « Réf: #123 », comme avant ;
- tout autre fichier reste où il est. Il s'affiche en rouge sur la page du dossier partagé, avec la raison, et dans « Fichiers à classer ».

Le fichier est déplacé, jamais lu en mémoire. Un fichier en cours de copie (taille qui change, modifié il y a moins de 30 secondes, extension `.part`, `.crdownload`, `.tmp`…) attend la minute suivante. Les fichiers cachés, les sous-dossiers et les liens sont ignorés : aucun nom ne peut faire sortir du dossier. Relancer la tâche ne rattache jamais deux fois. La prépresse reçoit une notification à chaque rattachement.

## Le tableau (PQ03)

Adresse : `/pre-presse/tableau`. Quatre colonnes : Sans fichier → À contrôler → BAT chez le client → Prêt pour la production, avec leur nombre. Chaque carte montre une vignette (la première image reçue, sinon la couleur et le support), le numéro, le client, le travail et un repère : ancienneté sans fichier, « livrer aujourd'hui 14:00 », « retour machiniste », « V2 · envoyé 09:12 » pour un BAT, « BAT validé 10:40 → machine » pour une commande prête. Six cartes par colonne, puis « + N autres » (lien vers la liste). Les commandes avec un retour du machiniste sont dans « À contrôler ».

Pas de glisser-déposer entre colonnes : une carte ouvre le contrôle du bon format (ou la commande), et c'est le contrôle qui fait avancer la commande.

## Fichiers manquants et relances (PQ04)

Adresse : `/pre-presse/relances`. Les commandes à qui il manque au moins un fichier, de la plus ancienne à la plus récente : commande, client, attente en jours, formats manquants (« 4 fichiers sur 6 »), relances faites (J+1 ✓ · J+3 ✓ · J+7) et réponse du client (« pas de réponse », « lu », « je passe samedi », « à associer »).

- On coche les commandes, puis « Relancer la sélection (N) ». Pour chaque commande, un lien WhatsApp (wa.me) s'ouvre avec le message prêt. Il n'y a pas d'envoi par l'API de Meta : c'est la personne qui envoie depuis WhatsApp. Le premier lien s'ouvre tout de suite, les autres un par un (les navigateurs bloquent l'ouverture de plusieurs onglets d'un coup).
- **Chaque lien ouvert est noté** : date, étape et personne. Un message envoyé à la main au moment de J+7 ou J+15, ou une deuxième fois pour la même étape, est noté « Relance ».
- Une commande sans client joignable ne peut pas être cochée (« impossible : pas de numéro »).
- La réponse du client se note sur la ligne ; elle s'affiche ensuite dans la colonne Réponse.
- **Le message** : « Bonjour Fofana, votre commande CMD-000378 (étiquettes vinyle, 6 formats) attend encore 4 fichiers : 5,5 × 7 · 5,5 × 5 · 5,5 × 4 · 5,5 × 5,5 cm. Envoyez-les ici : <lien> ou en réponse. Merci ! Succès Imprim ». Le lien est celui de la commande dans l'espace client du site, où le client peut envoyer ses fichiers après s'être connecté (il n'existe pas encore de page d'envoi sans connexion).

### Relances automatiques

Réglages › Atelier › **Relances des fichiers** (Direction et prépresse) : quatre interrupteurs, tous actifs au départ. La tâche `app:prepresse:relances` tourne chaque heure sur l'ERP local :

- **J+1** : un WhatsApp est préparé, et la prépresse reçoit une notification qui ouvre la page des relances sur la commande (le lien wa.me est prêt) ;
- **J+3** : même chose, et un message de l'atelier est écrit dans la conversation de la commande de l'espace client (le client le voit dans son fil) ;
- **J+7** : une action « Fichier manquant » apparaît dans « Mes actions » de la caisse : appeler le client. La note saisie en la terminant s'affiche dans l'historique de la fiche client et dans la colonne Réponse ;
- **J+15** : la commande est mise en pause et sa date de livraison est retirée (gardée de côté). Dès qu'un fichier arrive (dépôt, dossier partagé), la date de livraison revient et la pause est levée.

Chaque étape n'est faite qu'une fois, notée dans le journal des relances : relancer la tâche ne refait rien. Si la tâche s'est arrêtée plusieurs jours, seule la dernière étape due est faite (pas trois messages d'un coup). Une étape désactivée est sautée.

### À la caisse

Une commande a toujours un client dans l'ERP (le formulaire l'exige). Rendre aussi le numéro de téléphone obligatoire changerait la saisie de toutes les ventes : ce n'est pas fait. La page affiche plutôt le nombre de commandes du mois sans client joignable.

À la saisie d'une nouvelle commande, la case **« Fichier à venir »** (dans le récapitulatif) note la commande. Après l'enregistrement, un bandeau propose « Ouvrir WhatsApp » avec le message et le lien d'envoi. Les relances J+1, J+3, J+7 suivent si le fichier n'arrive pas.

**Indicateur** : « Temps moyen avant réception du fichier », sur les commandes des 30 derniers jours (de la commande au premier fichier reçu). Objectif : moins d'un jour.

## Données

- Table `relance_fichier` : une ligne par relance (commande, étape J1/J3/J7/J15/manuelle, canal whatsapp/espace/appel/pause, préparée le, par, envoyée le, par, nombre de fichiers manquants, message, lien, réponse et sa date).
- Table `commandes` : `en_pause_fichiers_le`, `date_livraison_avant_pause`, `fichier_a_venir`. Ces colonnes restent sur l'ERP local (pas envoyées au site).
- Migration `Version20261020040000`.
- Action de caisse de type `fichier` (cause `fichier:<id de la commande>`).

## Démonstration

`php bin/console app:prepresse:demo` (lancée par `app:demo`) : commandes `CMD-PQ…`. Fofana avec 6 formats d'étiquettes et 2 fichiers reçus, relancée J+1 et J+3 ; Sacko qui « passe samedi » ; une commande au comptoir sans numéro ; Diallo depuis 2 jours ; un kakémono à contrôler à livrer aujourd'hui ; un BAT chez Samaké ; un vinyle de Coulibaly prêt pour la machine. Relancer la commande remplace la démonstration précédente.

## Tâches planifiées

| Tâche | Quand | Windows | Linux ou Mac |
|---|---|---|---|
| `app:dossier-partage:importer` | chaque minute | dans `scripts\windows\synchro.bat` | `* * * * * cd /chemin/du/projet && php bin/console app:dossier-partage:importer -q` |
| `app:prepresse:relances` | chaque heure | dans `scripts\windows\devis_relances.bat` | `5 * * * * cd /chemin/du/projet && php bin/console app:prepresse:relances -q` |
