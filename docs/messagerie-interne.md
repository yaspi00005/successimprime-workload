# Messagerie de l'équipe (maquettes MI01 à MI05)

Demande de Yaya du 19/10/2026. L'imprimerie a une messagerie pour l'équipe, à côté des messages des clients : des **groupes** (Général, Atelier, Prépresse, Caisse et finances, projets, annonces) et des **messages privés** entre collègues. Elle remplace l'ancien chat interne (`/chat`) : ses conversations à deux sont gardées comme messages privés.

Les clients ne voient **jamais** cette messagerie. Elle n'existe que dans l'ERP de l'imprimerie : sur le site en ligne (`APP_EN_LIGNE=1`), ses pages répondent « page introuvable » et ses tables ne partent pas vers le site.

## Où la trouver

Menu **Messages** › onglet **Équipe** (`/equipe/messages`). L'onglet **Clients** mène aux messages des clients (docs/messagerie-clients.md). Chaque onglet a sa pastille rouge : messages non lus de l'équipe, et conversations clients en attente.

## L'écran (MI01, MI05)

- **Ordinateur, trois colonnes** :
  - à gauche, la liste des groupes et des messages privés (recherche, non lus en gras, pastilles) ;
  - au centre, le fil ;
  - à droite, le panneau du groupe : onglets Fichiers / Photos / Liens, messages épinglés, membres et réglages.
- **Écran moyen (moins de 1 280 px)** : le panneau de droite s'ouvre par-dessus le fil (bouton « i »).
- **Téléphone (moins de 768 px)** : d'abord la liste ; on touche un groupe pour ouvrir le fil (bouton retour). Le bouton **+** ouvre les outils (fichier, photo, commande, note vocale), et le bouton appareil photo prend une photo directement.
- **En haut du fil** : le nom, le type de groupe, les membres, et la phrase de confidentialité (voir plus bas). Un bandeau signale aussi :
  - un groupe fermé (« Groupe fermé : la commande CMD-… est livrée ») ;
  - un groupe d'annonces (seuls les responsables écrivent) ;
  - une lecture par la Direction sans être membre.

## Écrire

- **Texte** : jusqu'à 4 000 caractères. **@** propose les membres du groupe ; la personne citée reçoit une notification.
- **Répondre à un message** : la citation apparaît au-dessus de la réponse.
- **Réactions** : 👍 ❤️ 😂 😮 🙏 ✅ 👀 🔥, un clic pour ajouter, un autre pour retirer. Il y a aussi un choix d'emojis pour le texte.
- **Épingler** : qui peut écrire dans le groupe épingle ou retire un message. Les messages épinglés restent dans le panneau de droite.
- **Commande** : le bouton « Commande » cherche une commande (numéro, client) et insère sa carte dans le message. La carte montre :
  - le numéro, le client, l'article, le statut et l'échéance ;
  - le total et le reste à payer, **seulement** pour qui a le droit de voir les paiements (`ROLE_PAIEMENT_VOIR`). Les autres voient la carte sans montant ;
  - le lien vers la fiche, seulement avec le droit « Commandes ».

  Un numéro tapé dans le texte (« CMD-PM0182 » ou « CM-0182 ») devient aussi une carte.
- **Note vocale** : même enregistreur que les messages des clients (3 minutes, réécoute, puis envoyer ou annuler). La note s'ajoute au fil sans recharger la page.
- **Rythme** : la page demande les nouveaux messages toutes les 5 secondes, et toutes les 30 secondes quand l'onglet est caché.

## Fichiers (MI03, MI04)

- **Envoyer** : trombone, glisser-déposer ou coller (Ctrl+V). On peut envoyer **plusieurs fichiers** d'un coup.
  - Chaque fichier part en morceaux de 4 Mo, avec une barre de progression. Un morceau raté est renvoyé, et un envoi coupé reprend où il s'était arrêté.
  - La taille maximale d'un fichier est de **50 Mo** (réglable de 1 à 200 Mo).
  - Les fichiers sont rangés sous `var/uploads/chat`, jamais sous `public/`. Ils sont servis seulement à qui peut lire la conversation.
  - Les photos ont une vignette.
  - Les morceaux d'un envoi abandonné sont effacés après 24 h.
- **Gros fichiers déjà sur le serveur** : « Lien vers le dossier du client » parcourt le dossier du client (Mes produits, `produits_clients.dossier_racine`). Le message garde un **lien**, sans copie.
  - Un chemin hors de la racine des dossiers est refusé.
  - Le lien s'ouvre par le même contrôle que les fichiers privés (`FichiersPrivesController`).
- **Ranger dans le dossier du client** : dans un groupe lié à un client (projet, devis, commande), la case « Ranger aussi dans le dossier du client » **copie** le fichier dans `02 Fichiers reçus` du dossier du client.
  - Rien n'est jamais écrasé ni effacé : un nom déjà pris devient « nom (2).pdf ».
  - Sans racine des dossiers réglée, la case est grisée et la raison est écrite à côté.
- **Fichiers partagés (MI04)** : `/equipe/messages/fichiers`. On y voit tous les fichiers des groupes qu'on lit, avec :
  - des filtres par groupe, par type (PDF, images, maquettes, tableurs…) et par période ;
  - des onglets Tous / Production (BAT, prêts à imprimer) / Photos ;
  - trois cartes (nombre de fichiers, place occupée, liens vers le serveur).
- **Recherche** : `/equipe/messages/recherche`. Elle cherche dans les messages et les noms de fichiers des conversations qu'on lit.

## Groupes (MI02)

- **Types** :
  - **Permanent** (Atelier, Prépresse…) ;
  - **Projet**, lié à un client, un devis ou une commande. Il peut se **fermer tout seul à la livraison** : quand toutes les lignes de la commande sont livrées, le groupe passe en lecture seule avec un message « Groupe fermé tout seul : la commande … est livrée ». La fermeture a lieu à la fin de la requête qui livre (tournée, livraison directe, bon de livraison) ; la commande `app:messagerie-interne:fermer` rattrape le reste ;
  - **Annonces** : seuls les responsables écrivent.
- **Nouveau groupe** : nom, description, couleur, type, liaison (recherche de client, devis ou commande), membres par **rôle** (puces) ou un par un. Deux interrupteurs :
  - « Tout le monde peut envoyer des fichiers » ;
  - « Ranger les fichiers dans le dossier du client ».

  Celui qui crée le groupe en devient responsable.
- **Gérer** (responsables) : ajouter ou retirer des membres, nommer des responsables, fermer ou rouvrir le groupe. Chacun peut **quitter** un groupe (avec confirmation).
- **Groupes par défaut** (migration et `app:messagerie-interne:groupes`) :

  | Groupe | Rôles membres |
  |---|---|
  | Général | tout le personnel |
  | Atelier | production, PAO, livreurs |
  | Prépresse | PAO, production |
  | Caisse et finances | Direction, caisse |

  Les nouveaux comptes d'un rôle y sont ajoutés par la commande `app:messagerie-interne:groupes`.
- **Message privé** : « Nouveau message » › choisir un collègue. Un seul fil par paire de personnes.

## Droits

| Droit | Par défaut | Ce qu'il permet |
|---|---|---|
| Messagerie de l'équipe (`ROLE_MESSAGERIE_INTERNE`) | tous les rôles du personnel | ouvrir la messagerie, écrire dans ses groupes, messages privés |
| Créer des groupes de l'équipe (`ROLE_MESSAGERIE_GROUPES`) | Direction, responsables d'atelier | créer des groupes permanents, de projet ou d'annonces |
| Voir les paiements (`ROLE_PAIEMENT_VOIR`) | Direction, caisse | voir les montants sur les cartes de commande |

On retire ou ajoute ces droits par rôle ou par personne (Employés › Accès). La recherche de commandes, de devis et de clients pour lier un groupe suit les droits habituels de ces écrans.

**Qui lit quoi** (`src/Security/MessagerieInterneVoter.php`) :
- un groupe, ses membres ;
- un message privé, les deux personnes seulement ;
- un compte client, jamais : il est sur un autre pare-feu et ce n'est pas un compte du personnel ;
- la **Direction lit tous les groupes** sans en être membre, en lecture seule (pas de pastille ni de notification), **jamais les messages privés**. Le réglage **Paramètres › Messagerie de l'équipe › « La direction peut lire tous les groupes »** (activé par défaut) peut retirer cette lecture : la Direction ne lit alors que les groupes dont elle est membre.

La règle est écrite en clair dans la messagerie : « La direction peut lire les groupes, pas les messages privés. » Quand le réglage est coupé, la phrase devient « Seuls les membres lisent un groupe. Les messages privés ne sont lus que par les deux personnes. »

**Confidentialité** : le contenu des messages n'est jamais écrit dans les journaux. Les journaux gardent seulement des identifiants (conversation, message) en cas d'erreur.

## Notifications

- **@mention** : une notification « Mariam vous a cité dans Atelier : … » (cloche et téléphone).
- **Message privé non lu** : une notification. Les messages suivants mettent à jour la même notification tant qu'elle n'est pas lue, au lieu d'en ajouter une par message.
- **Pastille du menu** : le nombre de conversations avec des messages non lus (groupes et privés dont on est membre).

## Commandes

| Commande | Rôle |
|---|---|
| `app:messagerie-interne:groupes` | crée les groupes par défaut s'ils manquent et y ajoute les comptes de leurs rôles |
| `app:messagerie-interne:fermer` | ferme les groupes de projet dont la commande est livrée, efface les envois abandonnés (plus de 24 h) |
| `app:messagerie-interne:demo [--purger]` | données de démonstration (lancée par `app:demo`), `--purger` les retire |

`scripts/windows/synchro.bat` lance `app:messagerie-interne:fermer` à chaque passage (toutes les minutes).

## Migration

`migrations/Version20261019120000.php` :
- groupes (type, couleur, liaison client, devis ou commande, fermeture, annonces, fichiers permis) ;
- rôle des membres (responsable), dernier message lu ;
- pièces jointes (`messages_chat_fichier` : fichier, photo, lien vers le serveur, note vocale) ;
- réactions, épingles, réponses, mentions et commandes citées ;
- droits « Messagerie de l'équipe » à tous les rôles et « Créer des groupes » aux responsables d'atelier ;
- les quatre groupes par défaut avec leurs membres.

Les anciennes conversations du chat restent des messages privés. Leurs pièces jointes passent dans `messages_chat_fichier`, et les anciens liens `/uploads/chat/…` restent servis par `FichiersPrivesController`.

## Données de démonstration

`app:demo` lance `app:messagerie-interne:demo`. Identifiants à partir de 900001, mot de passe `demo1234`.

- **demo.ibrahim** (responsable d'atelier) voit l'écran de MI01 :
  - le groupe Atelier avec le planning épinglé, une mention sur CMD-PM0182, une réponse avec un lien vers le serveur, une photo de presse, une note vocale et des réactions ;
  - 4 messages privés, dont celui de Fanta non lu ;
  - le projet « Plaques Salon Espoir » lié à un devis.
- **demo.kadiatou** (Direction) lit tous les groupes, sans les messages privés, et publie dans Annonces.
- **demo.seydou** (PAO) voit les cartes de commande sans montant ; **demo.aminata** (caisse) les voit avec les montants.
- Le projet « Tote bags Salon Espoir » est fermé tout seul à la livraison.
- `var/demo/dossiers-clients` : petits dossiers de clients (maquettes, prêts à imprimer, fichiers reçus). La racine des dossiers n'est réglée sur ce dossier que si elle était vide.

## Tests

- `tests/Unit/Security/MessagerieInterneVoterTest.php` : un client jamais, un non-membre jamais, la Direction (groupes oui, privés non, selon le réglage), annonces, fichiers, création de groupe.
- `tests/Unit/Service/MessagerieInterne/` :
  - cartes de commande sans montant sans le droit ;
  - fichier du serveur hors de la racine refusé ;
  - rangement sans écrasement ;
  - envoi en morceaux et plusieurs fichiers ;
  - fermeture à la livraison ;
  - groupes par défaut et messages.
- Recette des accès : `app:recette:acces`, hors ligne et avec `APP_EN_LIGNE=1` (pages absentes en ligne).
