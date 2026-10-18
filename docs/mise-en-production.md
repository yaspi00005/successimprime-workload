# Mise en production (jalon J7)

Le déroulé, dans l'ordre, pour les deux machines :

- **ERP local** : l'ordinateur de la boutique (Windows, XAMPP dans `C:\xamppok`). Sa base existe déjà, avec les données de la boutique : elle est **basculée** vers la nouvelle version.
- **Site en ligne** : le serveur loué ([docs/deploiement.md](deploiement.md)). Sa base est neuve : elle est **créée**, puis remplie par la synchronisation.

Chaque étape renvoie à la page qui l'explique. Cocher au fur et à mesure.

Les commandes `php bin/console …` se tapent dans une invite de commandes ouverte dans le dossier de l'application (`cd C:\xamppok\htdocs\successImprim` sur l'ERP local). Si `php` n'est pas reconnu, écrire `C:\xamppok\php\php.exe` à sa place.

**Trois règles pour tout le déroulé**

1. Les clés et les mots de passe (fichiers `.env.local`) ne s'envoient **jamais** : ni message, ni e-mail, ni WhatsApp, ni capture d'écran. Yaya les tape lui-même sur chaque machine.
2. La sauvegarde complète de la base reste à la boutique. Le seul fichier de base qu'on nous envoie est `structure-….sql` (la structure, sans aucune donnée).
3. Les deux machines ont toujours la même version du code.

## J-7 : préparer

### Copie de la structure de la base

- [ ] Sur l'ordinateur de l'ERP, MySQL démarré dans le panneau XAMPP, copier `scripts\windows\avant_bascule.bat` de la nouvelle version sur le Bureau et le lancer d'un double-clic. Il ne modifie rien :
  - il fait une **sauvegarde complète** dans `C:\xamppok\sauvegardes-bascule` (à garder à la boutique) ;
  - il met sur le **Bureau** la copie de la **structure seule** : `structure-<base>-<date>.sql`.
- [ ] Nous envoyer **seulement** le fichier `structure-….sql`, et le nom de la base affiché (« Base de l'ERP : … »).
- [ ] Nous la rejouons sur une base d'essai avec `app:bascule:verifier` ([docs/migration-de-base.md](migration-de-base.md)) et la bascule complète. Nous renvoyons la liste des points à vérifier sur la vraie base et, s'il y en a, les décisions à prendre ensemble (lignes qui pointent vers une commande absente, colonne en trop remplie…). Avec la structure du 15/10/2026, la bascule fait aussi des **conversions de données** : les remises des lignes de commande et de devis passent du pourcentage aux F par unité, et les articles non vendables deviennent vendables ([« Conversions de données »](migration-de-base.md#conversions-de-données)).

### Vérifier l'ordinateur de l'ERP

- [ ] `C:\xamppok\php\php.exe -v` affiche PHP **8.4**. Sinon, s'arrêter et nous le dire : la nouvelle version ne démarre pas avec une version plus ancienne.
- [ ] Conseillé : installer la nouvelle version **à côté** de l'ancienne, dans `C:\xamppok\htdocs\successImprim-nouveau` (code, `composer install --no-dev --optimize-autoloader`, thème : `php bin/console app:installer-theme <dossier du thème>`). Son `.env.local` : celui de l'ancienne version, avec `APP_ENV=prod` et `serverVersion=10.4.28-MariaDB` dans `DATABASE_URL`. Puis, dans ce dossier :

  ```
  php bin/console app:verifier-installation
  php bin/console app:bascule:verifier
  ```

  La vérification ne modifie pas la base de la boutique (elle crée une base provisoire `<base>_ref_bascule` et la supprime). Nous envoyer le rapport `var\bascule\verification-….txt` : il ne contient que des noms de tables et des nombres. Si « Bloquants » n'est pas à 0, on en parle avant le jour J. Dans « Conversions de données à faire », la ligne de chaque remise donne l'essai du calcul sur les vraies lignes : attendu « 0 au-delà ». Ce dossier servira le jour J.

### Clés

- [ ] Rassembler les clés des comptes extérieurs (Orange SMS et Orange Money, Meta WhatsApp, boîte e-mail, Anthropic). La liste du site en ligne est dans [docs/deploiement.md, « Installation »](deploiement.md#installation). Sur l'ERP local, garder celles de l'ancien `.env.local` qui servent encore.
- [ ] Générer la clé de synchronisation, une seule fois, sur le site en ligne : `php bin/console app:synchro --nouvelle-cle` ([docs/synchronisation.md, « Mise en route »](synchronisation.md#mise-en-route), étape 1). Elle ira dans le `.env.local` des deux machines.
- [ ] Prévoir où ranger la clé de la copie hors site, générée le jour J sur l'ERP local ([docs/sauvegardes.md, « Copie chiffrée hors de la boutique »](sauvegardes.md#copie-chiffrée-hors-de-la-boutique)) : gestionnaire de mots de passe ou papier au coffre, hors de la boutique.

### Site en ligne, données et personnes

- [ ] Serveur installé et essayé avec la même version du code ([docs/deploiement.md](deploiement.md), jusqu'à « Tâches planifiées du serveur »). Sa base est neuve : `doctrine:migrations:migrate` la crée, il n'y a rien à basculer.
- [ ] Fichiers de l'import préparés avec les modèles : clients, articles, stock, soldes d'ouverture ([docs/import-donnees.md, « Déroulé »](import-donnees.md#déroulé), étapes 1 et 2).
- [ ] Liste du personnel : qui a un compte sur l'ERP, avec quel rôle ; qui a besoin de l'administration en ligne ([docs/deploiement.md, « Comptes du personnel en ligne »](deploiement.md#comptes-du-personnel-en-ligne)).
- [ ] Installations d'essai prêtes pour la recette, avec la même version du code et les données de démonstration (`app:demo`) : une sur l'ERP local, une en ligne ([docs/recette-par-role.md, « Vérification automatique des accès »](recette-par-role.md#vérification-automatique-des-accès)).
- [ ] Séances de formation prévues ([docs/formation.md, « Préparer la séance »](formation.md#préparer-la-séance)).

## J-1 : sauvegarder et geler

- [ ] Prévenir l'équipe : demain, l'ERP est arrêté de … h à … h ; plus aucune saisie après … h.
- [ ] ERP local : dans `C:\xamppok\htdocs\successImprim\backups`, le fichier le plus récent est d'aujourd'hui et n'est pas vide. Sinon l'ancienne sauvegarde automatique ne marche pas : nous le dire.
- [ ] ERP local : lancer `avant_bascule.bat`, puis copier la sauvegarde complète (`C:\xamppok\sauvegardes-bascule\…_complete_….sql`) sur le second disque ou une clé USB.
- [ ] Site en ligne : une sauvegarde existe (`php bin/console app:sauvegarde --liste`, [docs/sauvegardes.md](sauvegardes.md)).
- [ ] Dans l'ancien ERP, choisir **3 commandes et 1 devis qui ont une remise** sur une ligne (si possible une ligne au m² et une grosse quantité de flyers ou de cartes). Noter sur papier, pour chacun : le numéro, le total de chaque ligne remisée, le total HT, le total TTC et le reste à payer. Ces notes restent à la boutique.
- [ ] Le soir, après la dernière saisie : **gel**. Plus personne ne saisit dans l'ancien ERP.

## Jour J

### 1. ERP local : bascule de la base

- [ ] Personne n'utilise l'ERP ; les navigateurs ouverts dessus sont fermés.
- [ ] Lancer `avant_bascule.bat` : dernière sauvegarde complète avant la bascule. La copier sur le second disque.
- [ ] Renommer `C:\xamppok\htdocs\successImprim` en `successImprim-ancien`. **Ne pas le supprimer** : c'est le retour en arrière, à garder pendant toute la garantie.
- [ ] Mettre la nouvelle version à sa place, dans `C:\xamppok\htdocs\successImprim` (même chemin : les tâches planifiées l'attendent). Si `successImprim-nouveau` a été préparé à J-7, le renommer ; sinon installer comme à J-7.
- [ ] Recopier les fichiers envoyés depuis `successImprim-ancien`, au même endroit : `public\uploads`, `var\uploads`, `var\storage` (et `var\pointage`, `var\espace-employe`, `var\ecran-tv` s'ils existent).
- [ ] `.env.local` de la nouvelle version : `APP_ENV=prod`, `DATABASE_URL` vers la même base avec `serverVersion=10.4.28-MariaDB` (ou la version que donne la vérification), les clés préparées.
- [ ] `php bin/console app:bascule:verifier`
  - Attendu : « Bloquants 0 », et les nombres de lignes habituels (clients, commandes…).
  - Si « Bloquants » n'est pas à 0 : **s'arrêter**. Rien n'a été modifié. Nous envoyer le rapport (`var\bascule\verification-….txt`) et remettre l'ancienne version (« Revenir en arrière » plus bas).
- [ ] `php bin/console app:bascule:verifier --corriger`, puis taper le nom de la base quand il est demandé. Ajouter `--garder-orphelins` seulement si nous l'avons décidé ensemble à J-7.
  - Elle fait d'abord une sauvegarde : **noter son nom** (« Sauvegarde de la base faite : … »).
  - Puis les conversions de données (« Conversion commandes_details.remise : … ligne(s) passées du pourcentage aux F par unité », « Conversion articles.vendable : … »).
  - À la fin, le tableau « Lignes avant / Lignes après » doit montrer les mêmes nombres, et « Remises converties » doit finir par **« 0 au-delà »** pour les commandes et pour les devis. Noter les nombres « déjà différente(s) avant la bascule » s'il y en a (lignes dont l'ancien calcul ne redonnait déjà pas le total) et nous les envoyer : des nombres seulement.
  - Juste avant, « … ligne(s) gardent leur total d'origine » : ces lignes garderont leur total tant qu'on ne change ni leur quantité, ni leur prix, ni leur remise, ni leurs dimensions, ni leurs finitions ([docs/migration-de-base.md, « Garder le total »](migration-de-base.md#garder-le-total)). Noter ces nombres aussi.
  - Si elle s'arrête sur une ligne de remise, elle donne la ligne pour remettre la sauvegarde : suivre « Revenir en arrière » plus bas.
- [ ] `php bin/console doctrine:migrations:migrate -n` (l'avertissement « Version20261015231500 was executed but did not result in any SQL statements » est normal)
- [ ] `php bin/console doctrine:schema:validate` : deux lignes « [OK] ».
- [ ] `php bin/console cache:clear`, puis `php bin/console app:verifier-installation` : aucune erreur.
- [ ] Ouvrir l'ERP dans le navigateur avec son compte habituel : la liste des clients, celle des commandes, quelques fiches. Si un mot de passe ne passe plus : `php bin/console app:creer-utilisateur <identifiant>` le remplace.
- [ ] Ouvrir les 3 commandes et le devis notés la veille : chaque total de ligne, le total HT, le total TTC et le reste à payer doivent être **les mêmes que sur le papier**. La remise s'affiche maintenant en F par unité (par exemple 12,5 % sur des flyers à 25 F → 3 F par flyer, arrondi au franc) ; une ligne dont l'arrondi ferait bouger le total porte la mention « Total d'origine conservé ». La réenregistrer sans rien changer garde son total ; changer sa quantité, son prix, sa remise, ses dimensions ou ses finitions la recalcule (à 1 F près par unité). Si un total a changé sans qu'on touche à la ligne : s'arrêter et nous appeler.
- [ ] Liste des articles : tous sont vendables (comme l'ancienne mise à jour du 19/08/2026). Pour ceux qui ne se vendent pas (encre, papier, bâche en rouleau…), décocher « Cet article peut être vendu directement » sur leur fiche : ils reviennent dans l'écran Consommables.

### 2. ERP local : sauvegardes et tâches planifiées

- [ ] Dans `.env.local` : `SAUVEGARDE_COPIE_DIR` (le second disque). Lancer une fois, en administrateur, `scripts\windows\installer_taches_planifiees.bat` : il remplace l'ancienne tâche « toutes les 6 h ». Puis `php bin/console app:sauvegarde` et `php bin/console app:sauvegarde --liste` ([docs/sauvegardes.md](sauvegardes.md)).
- [ ] Copie hors site : générer la clé et la ranger ([docs/sauvegardes.md, « Copie chiffrée hors de la boutique »](sauvegardes.md#copie-chiffrée-hors-de-la-boutique), étapes 1 à 3).
- [ ] La direction relit les règles de Communications › Rappels de paiement (des commandes anciennes non soldées recevraient un rappel), puis lancer une fois, en administrateur : `installer_tache_rappels_paiement.bat`, `installer_tache_campagnes.bat`, `installer_tache_decaissements_recurrents.bat`, `installer_tache_corbeille.bat` (dossier `scripts\windows` ; la corbeille : [docs/corbeille.md](corbeille.md)).

### 3. ERP local : données de démarrage et comptes

- [ ] Import des données de démarrage : clients, articles, stock, soldes d'ouverture, avec l'aperçu avant de confirmer ([docs/import-donnees.md](import-donnees.md)).
- [ ] Comptes du personnel : la direction donne un accès à chaque employé, avec son rôle ([docs/formation.md, « Après la formation »](formation.md#après-la-formation)), ou `php bin/console app:creer-utilisateur <identifiant> --role=…`.

### 4. Synchronisation

- [ ] `.env.local` des deux machines : [docs/synchronisation.md, « Mise en route »](synchronisation.md#mise-en-route), étapes 2 et 3 (la même `SYNC_CLE` des deux côtés).
- [ ] `php bin/console app:verifier-installation` sur les deux machines.
- [ ] Le site en ligne qui a servi aux essais peut garder des données de démonstration envoyées par une machine de test : avant d'ouvrir, repartir d'une base en ligne vide (nouvelle base dans le panneau, `DATABASE_URL` du serveur vers elle, puis `php bin/console doctrine:migrations:migrate -n` sur le serveur), que la ligne suivante remplit depuis l'ERP de la boutique.
- [ ] Sur l'ERP local, une seule fois : `php bin/console app:synchro --initialiser`.
- [ ] Tâche planifiée de la synchronisation (« Mise en route », étape 5) : `scripts\windows\installer_tache_synchro.bat` en administrateur. L'écran Réglages › Sécurité › Synchronisation montre une synchronisation réussie.

### 5. Site en ligne

- [ ] Comptes du personnel en ligne ([docs/deploiement.md, « Comptes du personnel en ligne »](deploiement.md#comptes-du-personnel-en-ligne)).
- [ ] Toutes les cases de [docs/deploiement.md, « Avant d'ouvrir au public »](deploiement.md#avant-douvrir-au-public).

### 6. Recette

- [ ] `php bin/console app:recette:acces` sur l'installation d'essai de l'ERP local, puis sur celle du site en ligne ([docs/recette-par-role.md, « Vérification automatique des accès »](recette-par-role.md#vérification-automatique-des-accès)). Elle refuse de tourner en production : c'est normal. Attendu : aucune fuite, aucune erreur.
- [ ] La recette à la main, rôle par rôle ([docs/recette-par-role.md](recette-par-role.md)).
- [ ] Formation ([docs/formation.md](formation.md)) ; le jalon J7 est atteint selon « Valider le jalon J7 ».

### 7. Ouverture

- [ ] Fin du gel : l'équipe travaille sur la nouvelle version.
- [ ] Le site est ouvert au public.

## Revenir en arrière (jour J)

- **Avant `--corriger`** : la base n'a pas été modifiée. Renommer `C:\xamppok\htdocs\successImprim` en `successImprim-echec`, puis `successImprim-ancien` en `successImprim`.
- **Après `--corriger`** : dans le dossier de la nouvelle version, remettre la sauvegarde notée (le message d'erreur donne la ligne exacte) :

  ```
  php bin/console app:sauvegarde:restaurer <nom de la sauvegarde> --base
  ```

  Taper le nom de la base pour confirmer. **Ne pas lancer** `doctrine:migrations:migrate` ensuite, même si la commande le propose. Puis renommer les dossiers comme ci-dessus.
- Si même cette commande ne marche pas : ne rien supprimer et nous appeler. La sauvegarde complète d'`avant_bascule.bat` permet de tout remettre.
- Dans tous les cas : nous envoyer le message affiché à l'écran et le rapport `var\bascule\verification-….txt`.

## Garantie : de J+1 à J+90

### Signaler un problème

Un message par problème, avec :

- [ ] la machine (ERP local ou site en ligne) et l'adresse de la page (ou le menu suivi) ;
- [ ] ce qui a été fait, ce qui était attendu, ce qui s'est passé ;
- [ ] le jour et l'heure ;
- [ ] l'identifiant du compte utilisé et son rôle (jamais le mot de passe) ;
- [ ] le message d'erreur exact, ou une capture de l'écran **en cachant** les noms, téléphones et montants des clients.

**Urgent** (appeler, pas seulement écrire) : l'ERP ne s'ouvre plus, on ne peut plus encaisser, le site ne répond plus, l'alerte des sauvegardes reste affichée.

### Ce qu'on peut nous envoyer

Rien de ce qui suit ne contient de secret ni de donnée de client :

| Sujet | À envoyer |
|---|---|
| Installation | le résultat de `php bin/console app:verifier-installation` |
| Bascule de la base | le rapport `var\bascule\verification-….txt` |
| Synchronisation | le résultat de `php bin/console app:synchro --empreinte` (sur les deux machines) |
| Sauvegardes | le résultat de `php bin/console app:sauvegarde --liste` |
| Erreur d'une page | le jour, l'heure exacte et l'adresse de la page, et la capture de l'écran d'erreur |

### Ce qu'on ne nous envoie jamais

- le fichier `.env.local`, une clé ou un mot de passe (synchronisation, copie hors site, Orange, Meta, e-mail, Anthropic, base de données) ;
- une sauvegarde (`.sql`, `.sql.gz`, `.sihs`) ou la base ;
- les fichiers des clients.

Une clé envoyée par erreur se change aussitôt : par exemple une nouvelle clé de synchronisation (`app:synchro --nouvelle-cle`), mise dans le `.env.local` des deux machines.

### Pendant la garantie

- [ ] Chaque jour : pas d'alerte de sauvegarde sur le tableau de bord de la direction ; Réglages › Sécurité › Synchronisation montre une synchronisation récente.
- [ ] Chaque mois : vérifier qu'une sauvegarde se restaure ([docs/sauvegardes.md, « Restaurer »](sauvegardes.md#restaurer)).
- [ ] Garder `successImprim-ancien` et `C:\xamppok\sauvegardes-bascule` jusqu'à J+90 ; en parler avec nous avant de les supprimer.
