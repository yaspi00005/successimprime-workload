# Dossiers clients

Maquettes du 19/10/2026, DS01 à DS04 (`/mnt/project-files/maquettes/dossiers-clients/`). L'ERP local montre, range et relie les fichiers des dossiers clients du serveur de l'atelier (`D:\Clients`, `\\SERVEUR\Clients`). Il s'appuie sur ce qui existait pour « Mes produits » (`ProduitsClient\DossierClient`, `LiensFichiers`, `ControleFichierLie`) et sur `FichiersSynchro` pour le site en ligne.

## La règle d'or : l'ERP ne déplace et ne supprime rien

L'ERP **lit** le dossier des clients. Il ne déplace, ne renomme et ne supprime jamais un fichier de l'atelier. Deux exceptions seulement :

1. **Créer le dossier d'un nouveau client** avec ses sous-dossiers (bouton « Créer le dossier », ou analyse de la nuit pour les clients créés depuis 2 jours).
2. **Classer un fichier « à classer »** : une **copie** renommée est posée dans le sous-dossier choisi. L'original reste dans « À classer ».

Dans « Qui voit quoi », **« Supprimer » veut dire retirer de l'ERP** (retirer un lien, une publication, une ligne de la liste « à classer »). Le fichier reste sur le disque. Cela est écrit sur l'écran des réglages.

## Ce qui est fait

### DS01 : fiche client, onglet Fichiers

Fiche client (`/clients/{id}`) › onglet **Fichiers**, au-dessus de « Fichiers reçus avec les commandes ». Même écran seul : `/dossiers-clients/client/{publicId}` (utile aux rôles qui n'ouvrent pas la fiche client).

- Barre du dossier : chemin vu des postes (bouton « Copier »), « Afficher dans le dossier », « Analyser maintenant ».
- Sous-dossiers du modèle avec leur couleur et leur nombre de fichiers ; ceux que la personne n'a pas le droit de voir n'apparaissent pas.
- Fichiers en vignettes ou en liste (choix gardé dans le navigateur), recherche par nom, tri.
- Panneau de détail : aperçu, type, poids, dimensions, date, qui l'a ajouté (si c'est passé par l'ERP), commande liée, **versions** (`_v2`, `_v3`, `-final`… regroupées), BAT envoyé correspondant.
- Actions : **Ouvrir avec Illustrator** (ou le programme du poste), **Afficher dans le dossier**, **Lier à une commande**, **Envoyer au client** (publication), télécharger, « Retirer le lien ».
- **Envoyer au client** publie une copie du fichier dans l'espace client (« Fichiers de l'imprimerie »). Les AI, PSD, INDD et autres sources ne sont **jamais** proposés par défaut : il faut cocher « Envoyer quand même le fichier source ».
- Dépôt de fichiers (rôles avec le droit « ajouter » sur le sous-dossier).

### DS02 : lier les dossiers existants

Réglages › Dossiers clients › « Lier les dossiers existants » (`/dossiers-clients/liaison`).

- Chaque dossier du disque est rapproché d'un client : **le code d'abord** (« DEMO-0002 Salon Espoir »), puis la ressemblance du nom (accents, casse, « SARL », « Ets » ignorés).
- États : **Lié**, **Suggéré** (avec le score), **Introuvable** (aucun client proche).
- « Valider les suggestions » (score de 60 ou plus), « Choisir un autre » (recherche de client), « Créer le dossier » pour un client sans dossier.
- Le lien est écrit dans un petit fichier caché `.succes` dans le dossier du client (identifiant du client) ; si ce fichier ne peut pas être écrit, le lien se fait par le chemin.
- Vues par état (suggérés, liés, introuvables, clients sans dossier), 50 lignes par page.

### DS03 : fichiers à classer

Atelier › **Fichiers à classer** (`/dossiers-clients/a-classer`), rôle Prépresse/Caisse avec le droit « ajouter ».

- Le dossier « À classer » (photos WhatsApp, scans, téléchargements) est lu par l'analyse.
- Pour chaque fichier l'ERP propose **le client, la commande et le sous-dossier**, d'après le nom du fichier et de son dossier d'origine, les numéros (`CM-0175`, `CMD-DC0001`) et la date.
- Nom automatique : `CM-0175_photo-facade_2026-10-19.jpg` (numéro de commande, origine sans accents, date).
- « Classer » (une ligne), « Classer les N suggestions » (propositions sûres seulement), « Retirer de la liste ».
- Un fichier non classé depuis **24 h** crée une ligne dans **Mes actions** (actions caisse), qui disparaît quand il est classé.

### DS04 : réglages et droits

Réglages › **Dossiers clients** (`/dossiers-clients/reglages`, Direction et Prépresse).

| Réglage | Rôle |
|---|---|
| Dossier racine des clients | Tel que le voit le serveur de l'ERP (`D:\Clients`, `/srv/clients`) |
| Le même dossier vu des postes | Pour « Ouvrir », « Afficher » et « Copier le chemin » (`\\SERVEUR\Clients`) |
| Dossier « À classer » | Fichiers en vrac |
| Surveiller les changements | Analyse rapide toutes les 5 minutes |
| Analyse complète chaque nuit / heure | 02:00 par défaut |
| Aperçus | Miniatures GD (JPG, PNG, GIF, WEBP ; aperçu intégré des AI, PSD, PDF quand il existe) |
| Recherche dans le texte des PDF | Grisé avec l'explication si `pdftotext` n'est pas installé sur le serveur |
| Modèle de sous-dossiers | Nom, ordre, couleur ; appliqué aux nouveaux dossiers |
| Qui voit quoi | Voir / Ajouter / Supprimer (= retirer de l'ERP) par rôle et par sous-dossier |
| Version en ligne | Rappel : le site ne voit pas le serveur, seuls les fichiers publiés y sont copiés |
| Sécurité | Clé des postes (Direction seulement), liens signés, journal |

Les droits par défaut : Direction tout ; Graphiste voit et ajoute partout ; Caisse voit tout, ajoute dans « Fichiers reçus » et « Documents » ; Production voit « Prêts à imprimer » ; Livreur rien. Les droits de la personne s'ajoutent à ceux de son rôle (Réglages › Utilisateurs, droit « Dossiers clients »).

Tout est écrit au **journal** : liaison, création de dossier, classement, publication, retrait, ouverture, téléchargement, réglages.

## Analyse planifiée

Rien de lourd pendant une page : les pages lisent la base. L'analyse lit le disque :

```
php bin/console app:dossiers-clients:analyser            # rapide : fichiers ajoutés ou modifiés
php bin/console app:dossiers-clients:analyser --complete # tout relire, rechercher les dossiers
php bin/console app:dossiers-clients:analyser --client=DEMO-0002
```

La commande se verrouille (pas deux analyses en même temps). Appelée toutes les 5 minutes, elle fait l'analyse complète une fois par nuit à l'heure réglée. Les miniatures sont rangées dans `var/dossiers-clients/miniatures/`.

- **Windows** : `scripts/windows/installer_tache_dossiers_clients.bat` (en administrateur) crée la tâche qui lance `dossiers_clients.bat` toutes les 5 minutes. Elle demande un **compte réseau** : le compte SYSTEM ne voit pas les partages `\\SERVEUR\…`.
- **Linux** : `*/5 * * * * www-data php /chemin/bin/console app:dossiers-clients:analyser`.
- Le compte du service web (Apache) doit lui aussi pouvoir lire le dossier (et écrire pour créer un dossier ou classer).

## Programme des postes : « succes-fichier »

Un navigateur ne peut pas ouvrir Illustrator ni l'explorateur. Les boutons « Ouvrir » et « Afficher dans le dossier » produisent un lien `succes-fichier://` **signé** (HMAC-SHA256 avec la clé des postes, valable 10 minutes) et limité au dossier racine.

Installation sur chaque poste (sans droits administrateur) :

1. Copier `outils/succes-fichier/` sur le poste.
2. Clic droit sur `installer.ps1` › « Exécuter avec PowerShell ».
3. Coller la **clé des postes** (Réglages › Dossiers clients › Sécurité, visible par la Direction) et le dossier des clients vu du poste (`\\SERVEUR\Clients`).
4. La première fois, le navigateur demande d'autoriser « succes-fichier » : cocher « toujours autoriser ».

Le programme vérifie la signature et la date, refuse tout chemin hors du dossier racine (y compris `..`) et refuse d'ouvrir un exécutable (`.exe`, `.bat`, `.ps1`…). La configuration est dans `C:\ProgramData\SuccesFichier\config.json`. Sans le programme, « Copier le chemin » reste disponible.

## Version en ligne (APP_EN_LIGNE=1)

Le site ne voit pas le serveur de l'atelier. Aucun écran en ligne ne lit le disque : l'onglet Fichiers montre seulement les fichiers **publiés** (« Envoyer au client »), les écrans de liaison, de classement et de réglages sont fermés. Les fichiers publiés montent par la synchronisation (`fichier_publie`, copies dans `var/uploads/publies/`, voir `docs/synchronisation.md`).

## Données de démonstration

`php bin/console app:dossiers-clients:demo` (lancé aussi par `app:demo`) crée `var/dossiers-demo/Clients` et `var/dossiers-demo/A classer` : 6 dossiers (1 lié par le code, 4 suggestions, 1 introuvable), 21 fichiers dont des versions de kakémono, 5 fichiers à classer, 2 BAT publiés. Identifiants à partir de 900001. `--purger` retire tout.

## Ce qui n'est pas fait / limites

- **La sauvegarde du dossier des clients n'est pas fournie** par l'ERP : elle reste à prévoir (copie du serveur, disque externe, NAS).
- « Modifié par » n'est connu que pour les fichiers ajoutés ou classés par l'ERP : le propriétaire Windows du fichier n'est pas lu.
- L'association BAT ↔ fichier dépend des événements de conception enregistrés dans l'ERP.
- Pas d'envoi WhatsApp direct (pas d'API) : « Envoyer au client » publie dans l'espace client.
- Recherche dans le texte des PDF : seulement si `pdftotext` (poppler-utils) est installé sur le serveur de l'ERP ; sinon grisée.
- Aperçus des AI, PSD, INDD : seulement l'aperçu intégré au fichier ; pas d'Imagick ni de Ghostscript.
- Les opérateurs (Production, Livreur) n'ont pas d'entrée de menu vers les fichiers : ils passent par le lien de l'écran client.

## Repères techniques

- Services `src/Service/DossiersClients/` : `ReglagesDossiers`, `ModeleSousDossiers`, `DroitsDossiers`, `Rapprochement`, `NommageFichier`, `LienProtocole`, `Miniatures`, `AnalyseDossiers`, `ClassementFichiers`, `PublicationFichiers`, `ExplorateurDossier`, `JournalDossiers`.
- Entités : `LienDossierClient`, `FichierDossier`, `FichierAClasser`, `FichierPublie` (migration `Version20261019100000`).
- Droits : `DossiersClientsVoter` (`DOSSIERS_VOIR`, `DOSSIERS_CLASSER`, `DOSSIERS_REGLER`, toujours refusés en ligne).
- Contrôleur `DossiersClientsController` (`/dossiers-clients`), gabarits `templates/dossiers_clients/`.
- Tests `tests/Unit/Service/DossiersClients/`.
