# Migration de base et bascule de la base de la boutique (octobre 2026)

Les anciennes migrations ne permettaient plus de recréer la base : la boutique avait exécuté des migrations qui ne sont plus dans le dépôt. Elles sont remplacées par une migration unique, `Version20261010202721`, qui crée le schéma complet. Les migrations suivantes (identifiants publics, coût de revient, stock…) partent de ce schéma.

- **Nouvelle base** (recette, CI, poste de développement, site en ligne) : `php bin/console doctrine:migrations:migrate -n` crée tout.
- **Base de la boutique** (l'ERP local en service) : elle a déjà ses tables et ses données. On la met au niveau de la migration de base avec `app:bascule:verifier`, puis les migrations suivantes se lancent normalement. Le déroulé du jour est dans [docs/mise-en-production.md, « Jour J »](mise-en-production.md#jour-j).

## Pourquoi l'ancienne procédure ne marche pas

Répétée le 15/10/2026 sur une copie de la base de la boutique du 01/08/2026, avec le code actuel :

| Étape de l'ancienne procédure | Ce qui se passe |
|---|---|
| 3. `doctrine:schema:update --dump-sql` doit dire « Nothing to update » | 543 instructions (129 tables à créer, 353 modifications, 59 index à créer, 2 à retirer). Cette commande compare la base au code le plus récent, pas à la migration de base : avec les 70 migrations venues après, elle ne peut jamais répondre « Nothing to update ». |
| « Aligner la base d'abord » avec `doctrine:schema:update --force` | S'arrête au milieu (un lien vers `societe` refusé) et laisse 168 tables dans un état à moitié fait. |
| 4. Vider l'historique et marquer la migration de base | Fonctionne, mais sans rien vérifier : la base n'a pas le schéma de la migration de base. |
| 5. `doctrine:migrations:migrate` | La première migration suivante (`Version20261010202901`) échoue sur « la table `controle_pre_presse` n'existe pas », après avoir déjà ajouté `public_id` à 3 tables. La base reste à moitié migrée et une relance échoue aussi. |

La base du 01/08 diffère de la migration de base bien au-delà de ce qui manque : 39 tables contre 68 attendues, 134 colonnes manquantes, 14 colonnes en trop (dont 10 dans `devis`) et 28 colonnes d'un autre type ou d'une autre taille (par exemple `factures.etat` entier → texte, `factures.numero` entier → texte, `articles.stock_min` texte → décimal, `paiements.mode` 100 → 30 caractères).

L'historique de l'ancien dépôt ne dit pas non plus où en est la boutique : rejouées sur la copie du 01/08, les 8 migrations encore présentes à la fin de l'ancien dépôt échouent sur 2, et les 66 migrations passées par son historique échouent sur 17, dès la première. La base de la boutique est donc d'une version inconnue : seule la comparaison de sa structure dit ce qu'il faut faire.

Deux autres constats :

- L'export phpMyAdmin ne se recharge pas tel quel : 6 paiements pointent vers une commande absente et le lien refuse l'import. Il faut le recharger avec `SET FOREIGN_KEY_CHECKS=0` en tête. Ces paiements sont signalés par l'outil, jamais modifiés.
- `app:sauvegarde` et `app:sauvegarde:restaurer` échouaient sur une base pas encore basculée (pas de table des réglages). Elles prennent maintenant les réglages par défaut, ce qui permet la sauvegarde avant bascule et le retour en arrière.

## L'outil : `app:bascule:verifier`

```
php bin/console app:bascule:verifier                          # vérifie, ne modifie rien
php bin/console app:bascule:verifier --corriger               # sauvegarde, corrige, marque la migration de base
php bin/console app:bascule:verifier --corriger --garder-orphelins
```

1. **Référence** : la migration de base, et elle seule, est jouée dans une base provisoire (`<base>_ref_bascule`, même jeu de caractères que la base de la boutique), lue, puis supprimée.
2. **Comparaison** : Doctrine compare la base de la boutique à cette référence. Chaque écart est classé d'après les données (nombres de lignes seulement), en trois niveaux.
3. **Rapport** : à l'écran et dans `var/bascule/verification-AAAAMMJJ-HHMMSS.txt`. Il contient des noms de tables et de colonnes et des nombres, jamais une valeur d'une ligne : il peut nous être envoyé.

| Niveau | Exemples | Que fait `--corriger` |
|---|---|---|
| **Ajout sans risque** | table, colonne facultative, index, lien qui manque ; colonne obligatoire avec une valeur par défaut ; index unique sans valeur en double ; lien dont toutes les lignes pointent vers une ligne existante | les crée |
| **Sans perte** (vérifié sur les données) | colonne élargie (texte plus long, entier plus grand, décimal plus large) ; même type, seul l'obligatoire, la valeur par défaut ou l'interclassement change ; colonne ou table en trop **vide** ; index ou lien en trop ou renommé ; texte raccourci quand la plus longue valeur tient | les applique |
| **Bloquant** | colonne ou table en trop **avec des valeurs** ; colonne qui devient obligatoire alors que des lignes sont vides ; changement de type qui pourrait changer une valeur ; texte raccourci qui couperait une valeur ; index unique avec des doublons ; lien vers une ligne absente ; colonne obligatoire sans valeur connue pour les lignes existantes | rien : la commande s'arrête, la base n'est pas modifiée |

Une colonne renommée n'est jamais devinée : elle apparaît comme une colonne en trop et une colonne manquante. Si l'ancienne contient des valeurs, c'est bloquant.

**Valeurs données aux lignes existantes** quand une colonne obligatoire sans valeur par défaut manque (le sens qu'avaient ces lignes avant que la colonne existe) :

| Colonne | Valeur |
|---|---|
| `paiements.statut` | `valide` : avant le statut, tous les paiements enregistrés comptaient |
| `commande_detail_fichier.origine` | `client` |
| `commande_detail_fichier.etat` | `original` |
| `public_id` (toutes les tables) | un identifiant tiré au hasard, différent pour chaque ligne, comme la migration des identifiants publics. Les identifiants restés à zéro ou vides (colonne ajoutée par une ancienne migration sans être remplie) en reçoivent un aussi : aucun n'a pu servir. |

Une colonne ajoutée avec une valeur par défaut prend cette valeur sur les lignes existantes (par exemple `commandes_details.statut_production` = `a_produire`), comme l'aurait fait la migration d'origine.

**Lignes qui pointent vers une ligne absente** (par exemple les 6 paiements de la copie du 01/08) :

- si le lien existe déjà dans la base, elles sont signalées (« À signaler ») et laissées telles quelles ;
- si le lien est à créer, c'est bloquant. Après en avoir parlé avec nous, `--garder-orphelins` crée le lien sans toucher ces lignes : MariaDB ne contrôle pas les lignes déjà là, seulement les nouvelles. Ces lignes ne s'ouvrent pas dans l'ERP (erreur sur la page du paiement) tant qu'elles ne sont pas corrigées à la main.

**Déroulé de `--corriger`** :

1. Nouvelle vérification ; s'il reste un écart bloquant, rien n'est fait.
2. Confirmation : taper le nom de la base (ou `--confirmer=<base>` sans question).
3. **Sauvegarde de la base** (`app:sauvegarde`, déclencheur « avant-bascule »). Si elle échoue, rien n'est modifié.
4. Colonnes obligatoires sans valeur par défaut : ajoutées facultatives, remplies avec les valeurs ci-dessus, rendues obligatoires à l'étape suivante.
5. Tous les autres écarts, dans l'ordre choisi par Doctrine, en mode strict de MariaDB : une valeur qui ne tiendrait pas fait échouer l'instruction au lieu d'être coupée.
6. Contrôles : la base doit être identique à la référence, et chaque table doit avoir le même nombre de lignes qu'avant.
7. L'historique des migrations est remplacé par la seule migration de base.

Ensuite : `php bin/console doctrine:migrations:migrate -n`, puis `php bin/console doctrine:schema:validate` (deux lignes « OK »).

Si une étape échoue après la sauvegarde, la commande affiche la ligne exacte pour la remettre : `php bin/console app:sauvegarde:restaurer <nom> --base`.

**Version de MariaDB** : la commande compare la version réelle du serveur à `serverVersion` dans `DATABASE_URL`. S'ils ne correspondent pas, elle le dit, et `--corriger` refuse. À la boutique (XAMPP), l'export du 01/08 indique MariaDB 10.4.28 : `serverVersion=10.4.28-MariaDB`.

**Copie de la structure sans données** (fichier `structure-….sql` envoyé par la boutique, `scripts\windows\avant_bascule.bat`) : chargée dans une base d'essai vide, la vérification ne peut pas consulter les données. Elle ajoute alors la liste « Base sans aucune ligne » : chaque écart qui dépend des données, avec la question à vérifier sur la vraie base. C'est la liste à préparer avant le jour J.

**Base déjà basculée** (la migration de base est dans l'historique) : la commande le dit et ne fait rien. **Base vide** : la créer avec `doctrine:migrations:migrate`.

## Revenir en arrière

Avant `--corriger`, rien n'est modifié. Après :

```
php bin/console app:sauvegarde:restaurer                       # liste : repérer la sauvegarde « avant-bascule »
php bin/console app:sauvegarde:restaurer <nom> --base
```

La base est vidée puis remise exactement comme avant (y compris les tables créées entre-temps, qui disparaissent). Remettre ensuite l'ancienne version du code. **Ne pas lancer `doctrine:migrations:migrate`** après ce retour : l'ancienne version n'en a pas besoin. La sauvegarde complète faite par `scripts\windows\avant_bascule.bat` est une seconde copie, indépendante de l'application.

## Répétitions du 15/10/2026

Sur la copie de la base de la boutique du 01/08/2026 (MariaDB 10.11, code de la branche), puis base supprimée. Comptes de lignes seulement.

| Essai | Vérification | `--corriger` | Après `migrate` |
|---|---|---|---|
| A. Copie du 01/08 telle quelle | 0 bloquant (205 ajouts, 48 sans perte) | 169 instructions sur 45 tables | 70 migrations, `schema:validate` OK, 71 exécutées et 0 nouvelle |
| B. Copie + les 8 migrations de la fin de l'ancien dépôt | 0 bloquant (196 ajouts, 54 sans perte) | 164 instructions sur 44 tables | idem |
| C. Copie + les 66 migrations de l'historique de l'ancien dépôt (celles qui échouent sautées) | 1 bloquant : un lien recréé sur `paiements` avec les 6 paiements orphelins | avec `--garder-orphelins` : 94 instructions sur 28 tables | idem |
| Structure seule de C (`mysqldump --no-data`) | 0 bloquant, liste « Base sans aucune ligne » à vérifier | — | — |
| Retour en arrière (copie A corrigée, puis restaurée) | — | — | 39 tables, contenu identique à l'original (`CHECKSUM TABLE`) |
| Arrêt simulé après la sauvegarde | — | message avec la ligne de restauration | restauration : contenu identique à l'original |

Dans tous les essais : clients 3, commandes 3, devis 0, factures 0, paiements 10, articles 0, avant comme après. Sur A, l'application démarre avec un compte administrateur d'essai : listes des clients (3), des commandes (3) et des paiements (10), fiches ouvertes, aucune erreur ; seules les pages des 6 paiements orphelins répondent par une erreur.

Non vérifié ici : MariaDB 10.4 (celle de XAMPP à la boutique). Les 71 migrations n'utilisent aucune instruction réservée aux versions plus récentes (`RENAME INDEX`, `RENAME COLUMN`…). Le premier passage sur 10.4 est la vérification sans `--corriger` sur l'ordinateur de la boutique (J-7 de [docs/mise-en-production.md](mise-en-production.md)), qui ne modifie rien ; le mieux est de répéter aussi la bascule complète sur un XAMPP identique, avec la copie de la structure.
