# Guide technique

Ce dossier contient le guide technique de l'application, pour apprendre l'architecture, former quelqu'un ou auditer le code point par point.

- `guide.json` : tout ce qui se tire du code (tables, routes et leurs droits, contrôleurs, services, commandes et leurs horaires, droits, sécurité, synchronisation, réglages), rangé par module, avec les chapitres et la liste des points d'audit ;
- `chapitres/*.md` : les chapitres écrits, en français, repris tels quels dans `guide.json` (`chapitres`).

## Régénérer le guide

Dans le dossier du projet :

```
php bin/console app:doc:technique
```

La commande ne demande pas de serveur web. Elle lit le code, les fichiers de configuration, les docs et les métadonnées Doctrine des entités. Elle ne lit aucune donnée de la base et n'écrit rien en base. Elle prend environ une seconde.

Options :

- `--sortie=<fichier>` : écrire ailleurs que `docs/technique/guide.json` (chemin relatif au projet, ou absolu) ;
- `--copie=<dossier ou fichier>` : copier aussi le fichier écrit à cet endroit.

À la fin, elle affiche la taille, le commit, et les nombres (entités, routes, contrôleurs, services, commandes, migrations, tests, chapitres, points d'audit). Elle prévient si le fichier dépasse 8 Mo.

Si la commande n'est pas trouvée après une mise à jour du code, vider le cache : `php bin/console cache:clear`.

### Aucun secret

Avant d'écrire, la commande cherche dans le JSON tout ce qui ressemble à un secret (`App\Service\Documentation\SecretsGuide`) : clé privée, clé d'API, jeton, mot de passe dans une adresse, longue suite hexadécimale, et les valeurs des variables d'environnement sensibles de la machine (`APP_SECRET`, `SYNC_CLE`, `DATABASE_URL`, `MAILER_DSN`…). S'il en trouve, le guide n'est pas écrit et la commande dit où. La valeur par défaut d'une option de commande qui ressemble à une clé est remplacée par « (masqué) », celle d'un réglage secret est omise, et le secret de « rester connecté » n'est pas repris.

## Modifier un chapitre

Les chapitres sont dans `chapitres/`, un fichier par chapitre, rangés par leur numéro (`01-vue-d-ensemble.md`…). L'identifiant d'un chapitre est son nom sans le numéro (`vue-d-ensemble`), et son titre est la première ligne `# …`. Un chapitre ne décrit que ce qui existe dans le code et ne contredit pas les pages de `docs/`. Après une modification, régénérer le guide.

## Structure de `guide.json`

| Clé | Contenu |
|---|---|
| `genere_le` | `date`, `commit`, `branche` de la génération |
| `stats` | les nombres : entités, routes (dont qui modifient, publiques, sans jeton signalées), contrôleurs, services, commandes (dont planifiées), migrations, tests unitaires, droits, voteurs, types synchronisés, tuiles de réglages, chapitres, points d'audit (et `audit_par_type`) |
| `modules` | un par module : `cle`, `libelle`, `sorte` (`menu` pour les 7 modules du menu, `technique` pour les autres), et les listes de ses `entites`, `routes`, `controleurs`, `services`, `commandes` |
| `chapitres` | `{id, titre, markdown}` |
| `entites` | `classe`, `table`, `module`, `role` (résumé du docblock), `fichier`, `champs` (`nom`, `colonne`, `type`, `nullable`, `longueur`, `precision`, `echelle`, `unique`, `identifiant`, `defaut_base`, `defaut_php`), `relations` (`nom`, `type`, `cible`, `mappedBy`, `inversedBy`, `proprietaire`, `cascade`, `orphanRemoval`, `colonnes_jointure` avec `onDelete`, `table_jointure`), `index`, `contraintes_uniques`, `rappels_cycle_de_vie`, `ecouteurs`, `synchronisee` |
| `routes` | `nom`, `chemin`, `methodes`, `controleur`, `module`, `acces` (`pare_feu`, `access_control`, `note`, `is_granted`, `refus_dans_le_code`, `verifications_dans_le_code`), `public`, `modifie_donnees`, `csrf`, `csrf_detail`, `sans_csrf`, `sensibles` |
| `controleurs` | `classe`, `module`, `resume`, `fichier`, `is_granted_classe`, `routes`, `services_constructeur`, `services_actions`, `entites_chargees`, `csrf` |
| `services` | `classe`, `sorte`, `module`, `resume`, `fichier`, `methodes_publiques` (`nom`, `statique`, `resume`) |
| `commandes` | `nom`, `description`, `classe`, `fichier`, `resume`, `module`, `options`, `arguments`, `planification` (`frequence`, `ou`, `source`), `planifiee` |
| `droits` | `catalogue`, `groupes`, `modules_roles`, `montants_masquables`, `role_hierarchy`, `roles_par_defaut`, `voteurs` |
| `securite` | `mecanismes`, `pare_feux`, `access_control`, `en_tetes`, `cookie_session`, `mots_de_passe`, `csrf_configuration`, `limiteurs`, `csrf_par_controleur`, `routes_modifiant_sans_csrf`, `separation_en_ligne_local`, `ecouteurs_doctrine` |
| `synchro` | `source`, `sens`, `types` (champs envoyés par type et sens), `reglages_publics`, `gestes` |
| `parametres` | `source`, `familles`, `tuiles` (écran Réglages) |
| `audit` | `{id, type, module, titre, quoi_verifier, fichier}` |

### Identifiants stables des points d'audit

`controleur:<classe>`, `entite:<classe>`, `route:<nom de la route>`, `securite:<clé du mécanisme>`, `commande:<nom>`. Ils ne dépendent que du nom de la chose : un audit noté à côté d'un identifiant se reprend après une nouvelle génération (chapitre « Comment auditer avec ce guide »).

## Le code du générateur

- `src/Command/DocTechniqueCommand.php` : la commande ;
- `src/Service/Documentation/GuideTechnique.php` : assemble le guide ;
- `ModulesTechniques` (rangement par module), `AnalyseCode` (lecture des docblocks et du corps des méthodes : jeton CSRF, écritures, refus d'accès, données sensibles), `ReglesAcces` (règle `access_control` et pare-feu d'une adresse), `TachesPlanifiees` (rythmes des tâches), `AuditTechnique` (points d'audit), `SecretsGuide` (recherche de secrets) : des classes sans état, testées dans `tests/Unit/Service/Documentation`.
