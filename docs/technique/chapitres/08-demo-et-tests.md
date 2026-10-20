# Données de démonstration et tests

## La démonstration

Tout est dans docs/donnees-demo.md. L'essentiel pour un développeur ou un auditeur :

- **Charger ou enlever** : `php bin/console app:demo` (relançable : l'ancienne démo est d'abord enlevée) et `php bin/console app:demo --purger`. La commande refuse de tourner en production. Le fichier `donnees-demo/demo.sql` s'importe aussi dans phpMyAdmin ; il est produit par `donnees-demo/generer_demo.py`, dont le tirage est toujours le même.
- **Contenu** : une année d'activité fictive dans toutes les tables : 12 employés avec leur compte `demo.…` (un par rôle : `demo.kadiatou` pour la Direction, `demo.aminata` pour la caisse, `demo.seydou` pour le graphisme, `demo.oumar` pour la production, `demo.bakary` pour la livraison…), le catalogue, 200 clients `DEMO-0001` à `DEMO-0200`, environ 770 commandes sur 12 mois, la prépresse et la production des 120 derniers jours, le personnel (pointages, congés, paie), le patrimoine, les primes, la fidélité, les promotions.
- **Repères** : toutes les lignes de démo ont un identifiant à partir de 900001, et la purge enlève tout ce qui est à partir de ce numéro, y compris ce qui a été saisi après le chargement. La démo est faite pour une base d'essai. Les dates sont relatives au jour du chargement.
- **Aucun message ne part vers un vrai abonné** : les téléphones des clients sont fictifs (60 xx xx xx), la case « recevoir les SMS » est décochée, et les fiches `DEMO-` ne reçoivent jamais de code (ni e-mail ni SMS).
- **Espace client** : les comptes clients de démonstration se connectent par mot de passe ; le numéro et le mot de passe de démonstration sont donnés dans docs/donnees-demo.md (« Espace client de démonstration »), avec une personne par sorte de droits dans l'entreprise Salon Espoir (`DEMO-0002`).
- **Tables non touchées** : `reglage`, `bareme_prime`, `role_utilisateur`, `entreprise`, `societe` et les tables techniques.
- D'autres commandes complètent la démo d'un module : `app:devis:demo`, `app:atelier:demo`, `app:prepresse:demo`, `app:dossiers-clients:demo`, `app:messagerie:demo`, `app:messagerie-interne:demo`, `app:poste-machiniste:demo`, `app:solde-client:demo`, `app:sondages:demo`, `app:fiche-client:demo`, et l'option `--demo` de `app:primes`, `app:fidelite`, `app:promotions` et `app:immobilisations:dotations`.

## Les tests unitaires

- Dossier `tests/Unit`, rangé comme `src/` (`Command`, `Controller`, `Entity`, `EventSubscriber`, `Form`, `Routing`, `Security`, `Service`, `State`, `Twig`). Le nombre de fichiers de test est dans `stats.tests_unitaires`.
- Lancer : `php vendor/bin/phpunit tests/Unit` ; une partie : `php vendor/bin/phpunit tests/Unit/Security`.
- Ce sont des tests sans base de données ni serveur web : ils vérifient les calculs, les règles et les droits (`ModulesRolesTest`, `ReglesAccesTest`, `LivreurSeulTest`, `DroitsEspaceTest`…), et parcourent le code pour refuser des erreurs connues : `SansCdnTest` (aucun fichier d'un autre site), `EntetesSecuriteTest`, `ConfirmationSuppressionsTest` (toute suppression confirmée, jamais par un lien), `AnciennesPagesThemeTest`.
- Le générateur de ce guide a ses propres tests dans `tests/Unit/Service/Documentation` (rangement par module, repérage du jeton CSRF, rythmes des tâches, recherche de secrets).

## La recette

- **Recette par rôle** (docs/recette-par-role.md) : la liste à cocher, un rôle après l'autre, avec les comptes de démonstration (visiteur, client, Direction, caisse, graphiste, production, chef d'atelier, livreur, personnel, administration en ligne).
- **Vérification automatique des accès** : `php bin/console app:recette:acces` ouvre chaque page avec chaque compte `demo.*` et compare ce qu'elle obtient aux droits. Elle signale une page ouverte sans son droit, une entrée du menu qui répond « accès refusé », une erreur 500, des montants visibles sans le droit. Sur le site (`APP_EN_LIGNE=1 APP_DEBUG=0`), elle essaie l'espace client avec chaque sorte de compte, et les en-têtes de sécurité. Le bilan va dans `var/recette/` (un rapport `.md` et la matrice compte × page en `.csv`). Elle n'enregistre rien (les pages sont demandées dans une transaction annulée à la fin, sans SMS, e-mail ni notification) et se lance sur une base d'essai qui a la démonstration.
- **Avant la mise en production** : `app:bascule:verifier` et `app:verifier-installation` (docs/mise-en-production.md, docs/deploiement.md).

## Ce que le guide ne remplace pas

Le guide est tiré du code et des fichiers de configuration, sans lire la base de données ni ouvrir de page. Il dit ce que le code déclare (routes, droits, jetons). Il ne prouve pas que la page répond comme prévu : c'est le rôle de `app:recette:acces` et de la recette par rôle.
