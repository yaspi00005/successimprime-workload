# Comment auditer avec ce guide

## Les points d'audit

La liste `audit` du guide donne un point par chose à vérifier. Chaque point a la même forme :

| Champ | Contenu |
|---|---|
| `id` | identifiant stable, tiré du nom de la chose (voir plus bas) : il ne change pas d'une génération à l'autre tant que la chose existe |
| `type` | `controleur`, `entite`, `route`, `securite` ou `commande` |
| `module` | le module du menu (`ventes`, `atelier`, `finances`…) ou `securite`, `synchronisation`, `site_public`, `espace_client`, `commun` |
| `titre` | ce qu'on audite, en une ligne |
| `quoi_verifier` | la question à se poser, écrite d'après ce que le code déclare |
| `fichier` | le fichier à ouvrir |

Les identifiants :

- `controleur:App\Controller\…` : un par contrôleur ;
- `entite:App\Entity\…` : un par entité (une table) ;
- `route:<nom de la route>` : un par route qui modifie des données ou qui montre des données sensibles (montants, salaires, données personnelles, fichiers) ;
- `securite:<clé>` : un par mécanisme de sécurité (`securite:config:session`, `securite:limiteur:…`, `securite:classe:App\Security\…`…), plus `securite:routes-sans-csrf` pour la liste des routes qui modifient sans jeton ;
- `commande:<nom>` : un par commande planifiée.

Les nombres de points par type sont dans `stats.audit_par_type`.

## Une méthode de travail

1. **Choisir un module.** Prendre un module dans `modules` (par exemple `finances`) : il donne ses entités, ses contrôleurs, ses services, ses routes et ses commandes. Les droits du module sont dans `droits.modules_roles`.
2. **Lire le parcours.** Le chapitre « Parcours métier étape par étape » dit, pour chaque étape, quelle route, quelle méthode et quels services sont en jeu, et quelles tables sont écrites.
3. **Prendre les points du module.** Filtrer `audit` sur `module`. Commencer par les points `route` (ce qui écrit et ce qui montre), puis les `controleur`, puis les `entite`.
4. **Pour chaque point `route`** :
   - ouvrir la méthode du contrôleur (`routes[].controleur`) ;
   - vérifier que le droit est le bon pour ce que la page fait (`routes[].acces` : règle `access_control`, `is_granted`, `refus_dans_le_code`, `verifications_dans_le_code`) ;
   - si `modifie_donnees` est vrai, vérifier le jeton (`csrf`, `csrf_detail`) ;
   - si `sensibles` n'est pas vide, vérifier qu'une personne sans le droit ne voit rien en changeant le numéro dans l'adresse (la fiche est cherchée dans les fiches de la personne ou du client connecté, jamais `find($id)` seul) ;
   - pour une route `public: true`, vérifier sa limite (`securite.limiteurs[].utilise_par`).
5. **Pour chaque point `entite`** : comparer les colonnes (`champs` : type, longueur, obligatoire) avec la base réelle, vérifier l'effet des suppressions (`relations[].colonnes_jointure[].onDelete`, `cascade`, `orphanRemoval`), et si la table est synchronisée (`synchronisee`), que rien de sensible ne part (chapitre « Deux installations »).
6. **Pour chaque point `commande`** : vérifier que la tâche existe vraiment sur la machine (`planification[].ou`), qu'elle refuse de tourner du mauvais côté si elle ne le concerne pas, et ce qu'elle écrit dans son journal.
7. **Pour chaque point `securite`** : suivre la liste de contrôle du chapitre « Sécurité ».
8. **Noter le résultat** à côté de l'`id` (tableur, ticket) : comme les identifiants ne changent pas, on peut régénérer le guide et reprendre là où on s'était arrêté.

## Retrouver une chose dans le guide

| Je cherche | Où regarder |
|---|---|
| ce que fait une adresse | `routes[]` filtré par `chemin` |
| qui peut ouvrir une page | `routes[].acces`, puis `droits.catalogue` pour le libellé du droit |
| les tables qu'un écran écrit | chapitre « Parcours métier », puis `controleurs[].entites_chargees` et `controleurs[].services_actions` |
| ce qu'un service fait | `services[]` : `resume`, `methodes_publiques` |
| quand tourne une tâche | `commandes[].planification` |
| ce qui part vers le site en ligne | `synchro.types`, `entites[].synchronisee` |
| un réglage de l'application | `parametres` (familles et tuiles de Réglages) |
| les routes qui modifient sans jeton | `securite.routes_modifiant_sans_csrf` |
| les en-têtes de sécurité | `securite.en_tetes` |

## Les limites du guide

- Le guide **lit le code**, il ne l'exécute pas. Le jeton CSRF, les écritures et les données sensibles sont repérés par des motifs dans le texte des méthodes : un faux positif ou un oubli reste possible. Chaque point dit quoi relire.
- Il **ne lit pas la base** : les rôles réels et leurs droits se voient dans Réglages › Rôles et droits ; le guide ne donne que les rôles posés par les migrations (`droits.roles_par_defaut`).
- Les **résumés** viennent des docblocks. Une classe sans docblock a un `resume` vide : c'est aussi un point à signaler.
- Les **rythmes** des tâches viennent des scripts et des docs du dépôt, pas de la machine. Vérifier sur la machine.
- Le guide est une photo du code au moment de la génération (`genere_le` : date, commit, branche). Il inclut aussi ce qui n'est pas encore validé dans git dans le dossier de travail. Le régénérer après chaque mise à jour : `php bin/console app:doc:technique` (docs/technique/README.md).
