# Vue d'ensemble

## Ce que fait l'application

Succès Imprim est l'application de gestion d'une imprimerie de Bamako. Le même code sert trois publics :

- **le personnel**, dans l'administration (l'ERP) : devis, commandes, prépresse et BAT, production sur les machines, livraisons, factures, caisse, trésorerie, achats et stock, équipe (pointage, paie, primes), réglages ;
- **les clients**, dans l'espace client (`/espace`) : commander en ligne, envoyer leurs fichiers, valider un BAT, payer par Orange Money, suivre leurs commandes, écrire à l'atelier ;
- **les visiteurs**, sur le site vitrine (`/`, `/nos-produits`, `/demander-un-devis`…) : catalogue, calculateur de prix, demande de devis, suivi d'une commande sans compte.

Ce code tourne sur deux installations, l'ERP local de l'imprimerie et le site en ligne, reliées par la synchronisation (chapitre « Deux installations »).

## La pile technique

| Couche | Choix |
|---|---|
| Langage | PHP 8.4 |
| Cadre | Symfony 8.1 (routes par attributs `#[Route]`, injection automatique des services, Security, Forms, RateLimiter, Messenger) |
| Base | MariaDB (10.4 au local sous XAMPP, 10.11 sur le serveur), par Doctrine ORM 3 et Doctrine Migrations |
| Pages | Twig, avec le thème Workload (sous licence, hors dépôt, installé par `app:installer-theme`) |
| Scripts et styles | fichiers de `public/js` et `public/css`, bibliothèques copiées dans `public/vendor` (aucun CDN, docs/securite.md) |
| API | API Platform sous `/api/v1` (ERP local seulement, docs/api.md) |
| Tests | PHPUnit, dossier `tests/Unit` |

## Les dossiers

| Dossier | Contenu |
|---|---|
| `src/Controller` | les contrôleurs : une classe par écran ou groupe d'écrans, chaque méthode publique porte sa route `#[Route]`. `src/Controller/Api` : l'API de synchronisation. `src/Controller/Finances` : les écrans F01 à F07 |
| `src/Entity` | les entités Doctrine, une par table (attributs `#[ORM\…]`) ; `src/Entity/Trait` : les morceaux communs (identifiant public, société, livraison et objet d'un document) |
| `src/Repository` | les requêtes Doctrine, une classe par entité |
| `src/Service` | le métier, rangé par domaine (`Devis`, `Commandes`, `Facturation`, `Paiement`, `Stock`, `Achats`, `Synchro`, `EspaceClient`…) ; un service n'affiche rien, il calcule et enregistre |
| `src/Form` | les formulaires Symfony (ils portent leur jeton CSRF) |
| `src/Security` | droits (`Droits`, `ModulesRoles`), voteurs, authentificateurs, en-têtes de sécurité, fichiers envoyés, accès en ligne |
| `src/EventSubscriber` | ce qui s'applique à chaque requête (en-têtes, accès en ligne, lecture seule, livreur seul, écrans absents en ligne) ou à chaque enregistrement Doctrine (journal d'activité, coût de revient, fidélité) |
| `src/Twig` | les extensions Twig, dont `NavigationExtension` qui range chaque écran dans un des 7 modules du menu |
| `src/Command` | les commandes `bin/console app:…` : tâches planifiées, démonstration, recette, installation |
| `src/ApiResource`, `src/State` | les ressources et les fournisseurs de données de l'API |
| `templates` | les gabarits Twig, un dossier par écran (`templates/espace` pour l'espace client, `templates/site` pour le site vitrine) |
| `public` | la racine web : `index.php`, styles, scripts, `vendor/`, `uploads/site` et `uploads/tv` (les seuls fichiers envoyés publics) |
| `migrations` | les migrations Doctrine, une par changement de structure, numérotées par date |
| `config` | la configuration Symfony : `packages/security.yaml` (pare-feux, règles d'accès, hiérarchie des rôles), `packages/rate_limiter.yaml` (limites d'essais), `services.yaml` |
| `ressources` | ce que l'application lit sans le servir : captures du guide utilisateur, modèles |
| `tools` | outils de développement (refaire les captures du guide avec Playwright) |
| `outils` | le petit programme Windows « succes-fichier » qui ouvre un fichier du dossier client sur le poste |
| `scripts/windows` | les scripts `.bat` de l'ERP local et leurs installateurs de tâches planifiées |
| `donnees-demo` | l'export SQL de la démonstration |
| `docs` | la documentation (62 pages par sujet) ; `docs/technique` : ce guide |
| `var` | ce qui est écrit par l'application : cache, journaux, `var/uploads` (fichiers privés), sauvegardes. Jamais dans le dépôt |

## Le chemin d'une requête

1. **Routage.** `public/index.php` démarre le noyau. Le routeur trouve la route d'après l'adresse et la méthode (`config/routes.yaml` importe les attributs `#[Route]` de `src/Controller`). La liste complète est dans `routes` du guide.
2. **Pare-feu.** `config/packages/security.yaml` choisit le premier pare-feu dont le motif correspond : `synchro` (`^/api/synchro/`, sans session, chaque requête signée), `api` (`^/api/v1`, jeton `Bearer`), `espace` (`^/espace`, comptes clients par téléphone), sinon `main` (personnel). Le pare-feu charge la personne connectée (`UserAuthenticator`, `EspaceClientAuthenticator`, `JetonApiAuthenticator`) et vérifie le compte (`UserChecker`, `EspaceClientChecker`).
3. **Souscripteurs de requête.** Avant le contrôleur : `AccesEnLigneSubscriber` (appareil autorisé et code en ligne), `ForcePasswordChangeSubscriber`, `ActiveUserSubscriber`, `LectureSeuleSubscriber` (mode « Voir »), `LivreurSeulSubscriber`, `EspaceClientSubscriber`, les `…HorsLigneSubscriber` qui font disparaître en ligne les écrans réservés au local, `ProductionMachineSubscriber` (production depuis un poste de machine).
4. **Règles d'accès.** La première règle `access_control` dont le chemin correspond s'applique (la dernière, `^/`, demande `ROLE_USER`). Puis les `#[IsGranted]` de la classe et de la méthode, et les `denyAccessUnlessGranted()` écrits dans l'action. Les voteurs (`src/Security/*Voter.php`) décident des cas qui dépendent de la fiche (une conversation, un fichier privé, une entrée de la corbeille).
5. **Contrôleur.** L'action lit la requête, vérifie le jeton CSRF d'un envoi (`isCsrfTokenValid`, ou le formulaire Symfony), puis appelle les services.
6. **Services et Doctrine.** Les services font le travail et enregistrent par l'`EntityManager`. Au `flush`, les écouteurs Doctrine s'appliquent : `AuditSubscriber` (journal d'activité), `CoutRevientSubscriber`, `FideliteSubscriber`, `ReservationsCommandeListener` (stock réservé), `JournalSynchro` (file de synchronisation), `RattachementSociete`. Leur liste est dans `securite.ecouteurs_doctrine`.
7. **Réponse.** Le contrôleur rend un gabarit Twig (ou du JSON, un PDF, un fichier). `EntetesSecuriteSubscriber` pose les en-têtes de sécurité sur toutes les réponses.

## Lire le guide

Le fichier `docs/technique/guide.json` est tiré du code par `php bin/console app:doc:technique` (voir `docs/technique/README.md`). Il donne, à jour, les tables (`entites`), les routes et leurs règles d'accès (`routes`), les contrôleurs, les services, les commandes, les droits, les mécanismes de sécurité, la synchronisation et les paramètres, rangés par module (`modules`). Les chapitres comme celui-ci expliquent ce que les listes ne disent pas.
