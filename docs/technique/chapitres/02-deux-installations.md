# Deux installations

## Le même code, deux machines

Le dépôt s'installe deux fois, avec la même version du code et les mêmes migrations :

| | ERP local | Site en ligne |
|---|---|---|
| Machine | l'ordinateur de l'imprimerie (Windows, XAMPP dans `C:\xamppok`) | le serveur loué (Linux, Nginx, docs/deploiement.md) |
| Réglage | `APP_EN_LIGNE=0` | `APP_EN_LIGNE=1` |
| Base | la base maître, avec toutes les données de l'imprimerie | sa propre base, remplie par la synchronisation |
| Public | le personnel, sur le réseau de l'atelier | les visiteurs (site vitrine), les clients (`/espace`), quelques personnes du personnel depuis un appareil autorisé |
| Synchronisation | `SYNC_URL` (l'adresse du site) et `SYNC_CLE` dans `.env.local` | `SYNC_CLE` dans `.env.local` |

Le code lit `APP_EN_LIGNE` par le service `App\Security\AccesEnLigne` (méthode `estEnLigne()`) ; d'autres services reçoivent le même réglage par `#[Autowire('%env(bool:APP_EN_LIGNE)%')]`. Le guide liste dans `securite.separation_en_ligne_local` les classes qui en dépendent :

- les souscripteurs `…HorsLigneSubscriber` rendent « introuvables » en ligne les écrans réservés au local : API, atelier de production, import des données, messagerie de l'équipe, patrimoine et primes, statistiques et rapports, guide utilisateur ;
- `AccesEnLigneSubscriber` durcit l'administration en ligne : appareil autorisé avant le mot de passe, code de sécurité reçu par e-mail (ou SMS), session fermée après 15 minutes sans activité, rôle en ligne de l'appareil (docs/securite.md) ;
- plusieurs commandes refusent de tourner en ligne (rapports par e-mail, primes, fidélité, promotions…) : elles appellent `estEnLigne()` au début (`classes_qui_testent_estEnLigne`).

Les comptes du personnel ne descendent pas par la synchronisation. Les personnes qui travaillent en ligne ont un compte créé sur le serveur (`app:creer-utilisateur`) et un appareil autorisé (`app:autoriser-appareil`), docs/deploiement.md, « Comptes du personnel en ligne ».

## Ce qui tourne où

- **Seulement au local** : la production et les postes des machines, le pointage, la paie, la trésorerie et la comptabilité, les achats et le stock, le dossier partagé et les dossiers clients, l'API `/api/v1`, les rapports par e-mail, les sauvegardes complètes de la base maître.
- **Seulement en ligne** : les adresses `/api/synchro/*` (elles n'existent pas sur le local, ni sur un site sans clé) et la mesure d'audience Google des pages publiques. Le paiement Orange Money de l'espace client et sa notification `POST /om/notification/{o}` servent sur le site en ligne (table `transaction_orange_money`) ; le paiement reçu descend ensuite au local comme paiement « en attente ».
- **Des deux côtés** : le site vitrine et l'espace client existent dans le code des deux installations, mais le public ne va que sur le site en ligne.

La fiche de chaque tâche planifiée (`commandes[].planification[].ou`) dit sur quelle machine elle tourne (chapitre « Tâches planifiées »).

## La synchronisation

Tout est décrit dans docs/synchronisation.md. L'essentiel :

- **Sens des appels.** L'imprimerie n'a pas d'adresse fixe : c'est toujours l'agent du local (`app:synchro`) qui appelle le site, chaque minute. Le site ne contacte jamais le local.
- **Liste des champs.** Ce qui part est écrit champ par champ dans `src/Service/Synchro/CatalogueSynchro.php` (`types()`). Une colonne ajoutée plus tard reste au local tant qu'on ne l'ajoute pas à la liste. Le guide reprend cette liste dans `synchro.types` (type, entité, sens, champs, relations) et l'indique sur chaque entité (`entites[].synchronisee`).
- **Les sens.** `descendant` : du local vers le site seulement (sociétés, produits, tarifs, réglages publics…). `deux` : dans les deux sens, avec des règles (un nouveau client créé en ligne monte ; la modification en ligne d'une fiche du local est un « Conflit » et n'est pas appliquée). `ascendant` : du site vers le local seulement (alertes système du site, par exemple).
- **Ce qui ne part jamais** : coûts de revient, marges, comptabilité, trésorerie, dépenses et budgets, relevés Orange Money importés, salaires et données du personnel, notes internes sur les clients.
- **La file.** Chaque création, modification ou suppression d'une donnée suivie ajoute une ligne à `synchro_journal`, dans la même transaction (écouteur `JournalSynchro`). L'autre côté note chaque envoi appliqué dans `synchro_recu`, donc un rejeu ne crée pas de doublon. Un élément qui échoue 10 fois est bloqué et s'affiche sur l'écran Réglages › Sécurité › Synchronisation.
- **La signature.** Chaque requête et chaque réponse est signée en HMAC-SHA256 avec `SYNC_CLE` et datée. Une requête signée avec une autre clé, modifiée ou vieille de plus de 5 minutes est refusée. Le pare-feu `synchro` de `security.yaml` n'a ni session ni utilisateur : la signature est le seul contrôle (`routes[].acces.note`).
- **La commande `app:synchro`.**
  - sans option : un cycle (vérifier le site, envoyer la file, recevoir les créations faites en ligne, accuser réception) ;
  - `--boucle` : un cycle toutes les 30 secondes ;
  - `--initialiser[=type]` : envoie tout, ou un seul type, après une mise à jour qui ajoute des champs ;
  - `--empreinte` : affiche l'empreinte de la clé (jamais la clé), pour comparer les deux machines ;
  - `--nouvelle-cle` : génère une clé, une seule fois ;
  - `--diagnostic` et `--relancer-bloques` : pour une file qui n'avance pas.

## Mise en production

La bascule est décrite pas à pas dans docs/mise-en-production.md : J-7 (préparer, copie de la structure de la base, vérifier l'ordinateur, clés), J-1 (sauvegarder et geler), puis le jour J (bascule de la base du local, sauvegardes et tâches planifiées, données de démarrage, synchronisation, site en ligne, recette, ouverture) et le retour en arrière possible. La règle qui compte pour un auditeur : les clés et les mots de passe (`.env.local`) ne s'envoient jamais, et la seule copie de base qui sort de l'imprimerie est la structure sans données.
