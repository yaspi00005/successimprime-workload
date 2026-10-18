# Fichiers lourds : envoi, récupération en local, reprise de l'ancien dossier

Demande de Yaya du 10/10/2026. Les fichiers d'impression sont souvent lourds (PDF de bâche, TIFF, PSD de plusieurs centaines de Mo). L'application va sur un VPS, alors que l'impression se fait sur les PC de l'imprimerie. Il faut que :
- les fichiers arrivent sur le serveur sans bloquer personne ;
- ils redescendent tout seuls sur le PC du prépresse ;
- les clients puissent aussi en envoyer.

## Ce qui existe déjà

- **Envoi depuis la page commande et depuis le contrôle prépresse.** Le fichier part en morceaux de 5 Mo. Si la connexion coupe, l'envoi reprend là où il s'était arrêté. La limite est de 750 Mo par fichier (`FichierUploadController`).
- **« Dossier partagé » sur le serveur** (`var/uploads/dossier_partage`). On y dépose `123_nom.pdf`, où 123 est la référence du travail, et le bouton Importer rattache le fichier au travail sans le recopier.
- **Nettoyage automatique** des fichiers de production après la durée de conservation (`FICHIER_PRODUCTION_RETENTION_MONTHS`).

Une fois l'application sur le VPS, le dossier partagé n'est plus sur un PC de l'imprimerie. Il faut donc un moyen de faire passer les fichiers entre l'imprimerie et le serveur.

## Trois façons de faire entrer un fichier

| Par où | Pour qui | Comment |
|---|---|---|
| **Navigateur** | Agents, et les clients dans l'espace client (J5) | L'envoi par morceaux existant, avec la limite portée à 2 Go. L'envoi continue en arrière-plan pendant qu'on remplit la commande, avec une barre de progression sur la ligne. |
| **Lien** | Client qui envoie un WeTransfer, Google Drive, Dropbox ou OneDrive (par WhatsApp ou e-mail) | On colle le lien sur la ligne de commande. **C'est le serveur qui télécharge**, pas la connexion de l'imprimerie. La ligne affiche « Téléchargement… », puis le fichier. Si le lien a expiré, la ligne le signale. |
| **Dossier de dépôt sur le PC** | Fichiers apportés sur clé USB, ou reçus autrement | Un dossier « Dépôt Succès Imprim » sur le PC est envoyé au serveur en arrière-plan. Le nommage reste `123_nom.pdf`, et l'import se fait tout seul chaque minute, sans bouton. |

Sécurité des liens :
- seulement `https` ;
- jamais d'adresse interne au serveur ;
- taille maximale ;
- les liens de partage des sites connus sont convertis en lien de téléchargement direct.

## Récupération en local, sur le PC du prépresse et du RIP

Un dossier **« À imprimer »** sur le PC se remplit tout seul.
- Dès qu'une commande passe en préparation, son fichier de production y descend, rangé par commande : `C-2026-0456 Client/123_bache.pdf`.
- Les gros fichiers se téléchargent en arrière-plan, avec reprise si la connexion coupe.
- Quand la commande est livrée, son dossier est retiré du PC. Le serveur garde le fichier jusqu'à la fin de la durée de conservation.

**Outil : Syncthing**, installé une fois sur le PC du prépresse. Il est gratuit, chiffré et reprend après une coupure. Le même outil sert au dossier de dépôt dans l'autre sens. Le PC n'a rien à ouvrir sur Internet.

Pour les autres postes, la page commande propose « Télécharger » sur chaque fichier et « Tout télécharger » (zip) par commande.

## Dossier des clients et fichiers liés (« Mes produits », 18/10/2026)

Pour « Mes produits », le fichier traité d'un produit n'est **pas envoyé** : il reste dans le dossier du client sur le serveur de l'atelier (réglage Paramètres › Atelier › Dossier des clients : un dossier par client, nommé par son code, avec un sous-dossier « Produits »). Le navigateur ne peut pas lire un chemin du disque ; c'est l'ERP local qui liste ce dossier, contrôle le fichier (dimensions, CMJN, découpe, seulement quand il les trouve) et calcule son empreinte SHA-256. Le site en ligne ne reçoit que la référence. Avant chaque impression, l'empreinte est recalculée : si le fichier a changé ou bougé, une alerte bloque l'ouverture. Voir docs/mes-produits.md.

## Droits

- Un fichier client n'est visible que par les personnes qui ont accès à la commande : son agent, le prépresse et la production (voir les droits U01–U06).
- Un client ne voit que ses propres fichiers dans l'espace client.
- Chaque téléchargement est inscrit au journal d'activité.

## Reprise de l'ancien dossier

On reprend une seule fois les fichiers de l'ancien projet :
1. le dossier est copié sur le serveur avec `rsync` (avec reprise, de préférence la nuit) ;
2. une commande `app:importer-anciens-fichiers` rattache chaque fichier à sa commande, d'après le nom du dossier ou le préfixe ;
3. elle liste ce qu'elle n'a pas pu rattacher, et ne supprime rien.

## Place disque

Il faut prévoir au moins 200 Go sur le VPS pour les fichiers, à revoir avec le volume réel. Au-delà, les fichiers anciens partent sur un stockage objet, moins cher, sans rien changer à l'écran.

## Ordre

1. Le lien téléchargé par le serveur, la limite à 2 Go, « Tout télécharger » et le contrôle des droits sur les fichiers.
2. Les dossiers « Dépôt » et « À imprimer » avec Syncthing, à la mise en ligne sur le VPS (J4).
3. L'envoi par les clients, avec l'espace client (J5).
4. La reprise de l'ancien dossier, juste avant la bascule.
