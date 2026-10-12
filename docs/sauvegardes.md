# Sauvegardes automatiques (jalon J4)

## Ce qui est sauvegardé

- **La base complète**, en `.sql.gz`. `mysqldump` (ou `mariadb-dump`) est utilisé s'il est trouvé, y compris dans les dossiers de XAMPP. Sinon, ou s'il échoue, l'export est fait en PHP. Les deux se restaurent de la même façon.
- **Les fichiers**, dans une archive `.tar.gz` :
  - `public/uploads` : photos des employés, fichiers clients, BAT, chat, réclamations, logos, écran TV, étiquettes ;
  - `var/uploads` : fichiers des commandes, livraisons, entretien ;
  - `var/storage` : factures PDF ;
  - `var/pointage` : photos de pointage ;
  - `var/espace-employe` ;
  - `var/ecran-tv`.

  L'archive s'ouvre aussi avec tar, 7-Zip ou WinRAR.

Chaque sauvegarde est un dossier `AAAAMMJJ-HHMMSS` qui contient `base.sql.gz`, `fichiers.tar.gz` (une fois par jour) et `manifeste.json`. Le manifeste donne les tailles, les empreintes sha256 et la version du schéma.

## Quand

La tâche planifiée lance `app:sauvegarde --auto` **chaque heure**. L'application décide alors quoi faire, selon la tuile Réglages › Paramètres › Sauvegardes :

| Réglage | Défaut | Effet |
|---|---|---|
| Sauvegarde complète chaque jour à | 02:00 | Base et fichiers, une fois par jour. Si la machine était éteinte à cette heure, elle est faite au démarrage. |
| Base seule toutes les | 6 heures | La base seule entre deux complètes. |
| Garder une par jour pendant | 14 jours | |
| Puis une par semaine pendant | 8 semaines | |
| Puis une par mois pendant | 12 mois | |

Tout ce qui a moins de 48 h est gardé. La rotation s'applique séparément aux bases et aux archives de fichiers. La sauvegarde la plus récente n'est jamais supprimée.

## Sécurité

- Chaque sauvegarde est relue avant d'être gardée : décompression complète, marque de fin du dump, relecture de chaque entête de l'archive. Elle est écrite sous un nom provisoire, puis renommée. Une sauvegarde interrompue n'apparaît donc jamais.
- Le dossier des sauvegardes est hors de `public/`. Les fichiers sont créés en lecture pour le seul compte du serveur (0600).
- **Aucun téléchargement ni aucune restauration depuis le site.** Une sauvegarde contient toutes les données, salaires compris.
- L'écran Réglages › Sécurité › Sauvegardes est réservé à `ROLE_ADMIN`. Il montre l'état et a un bouton « Sauvegarder la base maintenant ».
- Le tableau de bord de l'administrateur affiche une alerte dans trois cas :
  - pas de sauvegarde réussie depuis 26 h ;
  - la dernière sauvegarde a échoué ;
  - la copie sur le second disque a échoué.
- Le mot de passe de la base est passé à mysqldump par la variable `MYSQL_PWD`, jamais sur la ligne de commande.

## Réglages de la machine (`.env.local`, jamais dans le dépôt)

```
# Vide = var/sauvegardes du projet
SAUVEGARDE_DIR=
# Second disque, clé USB ou NAS : chaque sauvegarde y est recopiée et vérifiée
SAUVEGARDE_COPIE_DIR=D:\Sauvegardes-SI
# Seulement si mysqldump n'est pas trouvé tout seul
SAUVEGARDE_MYSQLDUMP=C:\xamppok\mysql\bin\mysqldump.exe
```

Une copie sur le même disque ne protège pas d'une panne de ce disque. Il faut régler `SAUVEGARDE_COPIE_DIR` sur un autre disque. Si ce disque est débranché, la sauvegarde principale est faite quand même et l'alerte s'affiche.

## Copie chiffrée hors de la boutique

Un incendie ou un vol emporterait la machine et le second disque. Une copie part donc chaque jour vers le serveur en ligne, **chiffrée à la boutique** :

- la base de la dernière sauvegarde, chaque jour ;
- l'archive des fichiers de la dernière sauvegarde complète, une fois par semaine.

L'agent de synchronisation l'envoie après le reste, par morceaux de 4 Mo, 64 Mo au plus par minute, et reprend après une coupure. Le serveur vérifie l'empreinte, range la copie dans `var/sauvegardes-hors-site` (hors de `public/`, lisible du seul compte du serveur) et garde les 14 dernières bases et les 4 dernières archives de fichiers. **Le serveur ne peut pas la lire** : la clé reste à la boutique. Rien ne se télécharge par le site ; on récupère une copie par l'accès au serveur (SFTP).

Chiffrement : libsodium, XChaCha20-Poly1305 en flux. Une copie modifiée, tronquée ou ouverte avec une autre clé est refusée.

**Mise en route** (ERP local seulement) :

1. `php bin/console app:sauvegarde --nouvelle-cle-hors-site`
2. Mettre la ligne `SAUVEGARDE_CLE_HORS_SITE=...` dans `.env.local` de l'ERP local. **Jamais sur le serveur en ligne**, ni dans le dépôt, ni par message.
3. **Garder une copie de la clé hors de la boutique** (gestionnaire de mots de passe, ou papier au coffre). Sans elle, les copies hors site sont illisibles.

La synchronisation doit être en route (docs/synchronisation.md). L'écran Sauvegardes montre la dernière copie partie ; le tableau de bord alerte si la base n'est pas partie depuis 48 h.

**Restaurer depuis une copie hors site** : récupérer le fichier `.sihs` sur le serveur, puis sur une machine qui a la clé dans `.env.local` :

```
php bin/console app:sauvegarde-dechiffrer 20261012-020000-base.sihs
```

La copie est déchiffrée, vérifiée et rangée parmi les sauvegardes. Elle se restaure ensuite comme les autres (ci-dessous), par exemple `php bin/console app:sauvegarde:restaurer 20261012-020000 --base`.

## Installer la tâche

**Windows (XAMPP)** : vérifier les chemins en tête de `scripts\windows\backup_db.bat`. Lancer ensuite **une fois** `scripts\windows\installer_taches_planifiees.bat` en administrateur. Il crée deux tâches, une au démarrage et une chaque heure, et supprime l'ancienne tâche « toutes les 6 h ». Journal : `var\log\sauvegarde.log`.

**Linux (VPS)** : ajouter dans `crontab -e` du compte qui fait tourner le site :

```
0 * * * * cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
@reboot   sleep 60 && cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
```

**Mac (XAMPP)** : la même ligne dans `crontab -e`, avec `/Applications/XAMPP/xamppfiles/bin/php`.

## Commandes

```
php bin/console app:sauvegarde                   # base + fichiers maintenant
php bin/console app:sauvegarde --base-seulement  # base seule
php bin/console app:sauvegarde --liste           # sauvegardes présentes et alerte éventuelle
```

## Restaurer

```
php bin/console app:sauvegarde:restaurer                       # liste
php bin/console app:sauvegarde:restaurer 20261012-020000       # base + fichiers
php bin/console app:sauvegarde:restaurer derniere --base       # base seule
php bin/console app:sauvegarde:restaurer derniere --fichiers   # fichiers seuls
```

- La commande demande de taper le nom de la base pour confirmer. Elle fait d'abord une sauvegarde de l'état actuel (« avant restauration »).
- L'empreinte de chaque fichier est vérifiée avant d'écraser quoi que ce soit.
- La base est vidée puis rejouée, y compris les tables créées depuis.
- Les fichiers sont remis par-dessus ceux du site ; ceux ajoutés depuis restent en place. Seuls les dossiers de la liste ci-dessus peuvent être écrits.
- Si le code est plus récent que la sauvegarde, lancer ensuite `php bin/console doctrine:migrations:migrate -n`.

**Vérifier qu'une sauvegarde se restaure, sans rien toucher** (à faire une fois par mois) :

```
php bin/console app:sauvegarde:restaurer derniere --base-cible=si_verif
```

La sauvegarde est remise dans une autre base, `si_verif`. La commande vérifie que toutes les tables sont là. Supprimer ensuite cette base.

Testé le 12/10/2026 : export PHP et export mariadb-dump restaurés dans une autre base, 99 tables identiques à l'original (CHECKSUM TABLE). Restauration complète sur la base de développement : données et fichiers revenus.
