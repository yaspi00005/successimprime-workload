# Migration de base (octobre 2026)

Les anciennes migrations ne permettaient plus de recreer la base : la production avait execute
une cinquantaine de migrations de juillet 2026 qui ne sont plus dans le depot. Elles sont
remplacees par une migration unique, `Version20261010202721`, qui cree le schema complet.

- **Nouvelle base** (recette, CI, poste de developpement, future base en ligne) :
  `php bin/console doctrine:migrations:migrate` cree tout.
- **Base existante** (production) : la migration de base ne doit **pas** s'executer, les tables
  existent deja. On la marque comme faite avec la procedure ci-dessous.

## Procedure en production (une seule fois)

1. Sauvegarder la base (`scripts\windows\backup_db.bat`).
2. Deployer le code qui contient la migration de base.
3. Verifier que la base correspond au code :

   ```
   php bin/console doctrine:schema:update --dump-sql
   ```

   Le resultat doit etre « Nothing to update ». Sinon, **s'arreter** et transmettre la liste
   des requetes affichees : il faut d'abord aligner la base.
4. Remplacer l'historique des migrations par la migration de base :

   ```
   php bin/console dbal:run-sql "DELETE FROM doctrine_migration_versions"
   php bin/console doctrine:migrations:version "DoctrineMigrations\Version20261010202721" --add --no-interaction
   ```

5. Executer les migrations posterieures a la base (par exemple les identifiants UUID) :

   ```
   php bin/console doctrine:migrations:migrate
   ```
