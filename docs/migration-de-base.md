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
3. **Conversions de données** : la structure dit aussi quelles anciennes mises à jour qui changeaient le *sens* des données manquent (remises en pourcentage…). Elles sont annoncées dans « Conversions de données à faire », avec des nombres de lignes, et rejouées par `--corriger` (voir plus bas).
4. **Rapport** : à l'écran et dans `var/bascule/verification-AAAAMMJJ-HHMMSS.txt`. Il contient des noms de tables et de colonnes et des nombres, jamais une valeur d'une ligne : il peut nous être envoyé.

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
4. **Conversions de données** qui lisent l'ancien sens d'une colonne (remises en pourcentage, articles non vendables), avant tout changement de type.
5. Colonnes obligatoires sans valeur par défaut : ajoutées facultatives, remplies avec les valeurs ci-dessus, rendues obligatoires à l'étape suivante.
6. Tous les autres écarts, dans l'ordre choisi par Doctrine, en mode strict de MariaDB : une valeur qui ne tiendrait pas fait échouer l'instruction au lieu d'être coupée. Attention : un DECIMAL passé en INT est arrondi sans erreur, même en mode strict ; c'est pourquoi un tel changement avec des valeurs est bloquant, sauf pour une remise convertie à l'étape 4 (francs entiers, vérifiés).
7. Conversions de données qui remplissent une colonne créée à l'étape 6 (consommables, type de compte, amortissement).
8. Contrôles : la base doit être identique à la référence, chaque table doit avoir le même nombre de lignes qu'avant, et chaque ligne dont la remise a été convertie est recalculée (voir « Conversions de données »).
9. « Garder le total » : chaque ligne convertie qui ne retombe pas exactement sur son total enregistré est marquée `total_fige` (voir plus bas). La colonne vient d'une migration postérieure à la migration de base (`Version20261015231500`) : la bascule l'ajoute ici, avec la même définition, et la migration ne la recrée pas.
10. L'historique des migrations est remplacé par la seule migration de base.

Ensuite : `php bin/console doctrine:migrations:migrate -n`, puis `php bin/console doctrine:schema:validate` (deux lignes « OK »). L'avertissement « Migration DoctrineMigrations\Version20261015231500 was executed but did not result in any SQL statements » est normal : la bascule a déjà ajouté la colonne `total_fige`.

Si une étape échoue après la sauvegarde, la commande affiche la ligne exacte pour la remettre : `php bin/console app:sauvegarde:restaurer <nom> --base`.

**Version de MariaDB** : la commande compare la version réelle du serveur à `serverVersion` dans `DATABASE_URL`. S'ils ne correspondent pas, elle le dit, et `--corriger` refuse. À la boutique (XAMPP), l'export du 01/08 indique MariaDB 10.4.28 : `serverVersion=10.4.28-MariaDB`.

**Copie de la structure sans données** (fichier `structure-….sql` envoyé par la boutique, `scripts\windows\avant_bascule.bat`) : chargée dans une base d'essai vide, la vérification ne peut pas consulter les données. Elle ajoute alors la liste « Base sans aucune ligne » : chaque écart qui dépend des données, avec la question à vérifier sur la vraie base. C'est la liste à préparer avant le jour J.

**Base déjà basculée** (la migration de base est dans l'historique) : la commande le dit et ne fait rien. **Base vide** : la créer avec `doctrine:migrations:migrate`.

## Conversions de données

La comparaison des schémas ne voit que des types. Or certaines anciennes mises à jour changeaient le **sens** des données déjà là : sans elles, la nouvelle version lirait mal ces données (une remise de 10 % lue comme 10 F). Audit de tout l'historique de l'ancien dépôt (toutes les branches, y compris les migrations supprimées ensuite, 67 versions de 66 fichiers), comparé à la structure de la boutique du 15/10/2026 :

| Ancienne mise à jour | Ce qu'elle changeait dans les données | Structure du 15/10 | Ce que fait la bascule |
|---|---|---|---|
| 17/08 `Version20260817000000` (amortissement) | récapitulatif du traceur 1,8 m : facturation au m², chiffre d'affaires d'avant le suivi, suivi à partir du 17/08 | faite (colonnes présentes) | rejouée seulement si `machines.revenu_avant_suivi` manque |
| 17/08 `Version20260817120000` (type de compte) | clients B2B passés en compte « entreprise » | faite | rejouée si `clients.type_compte` manque (cas de la copie du 01/08) |
| 19/08 `Version20260819160000` (articles vendables) | défaut de `vendable` passé à 1, **tous les articles existants rendus vendables** (la case n'était jamais cochée) | **manque** : défaut 0 | rejouée : `UPDATE articles SET vendable = 1 WHERE vendable = 0` |
| 20/08 `Version20260820120000` et `130000` (lignes de commande) | `remise` : pourcentage → F par unité facturable, depuis le total enregistré | **manque** : `DECIMAL(5,2)` | rejouée, règle ci-dessous |
| 20/08 `Version20260820140000` (lignes de devis) | même conversion | **manque** : `DECIMAL(7,4)` | rejouée, règle ci-dessous |
| 01/09 `Version20260901180000` (consommables) | `consommable_production` = 1 pour les articles non vendables | manque | sans effet après la reprise du 19/08 (tous vendables, comme dans l'ancien enchaînement) ; rejouée si le 19/08 était déjà fait |

Sans changement de sens, rien à rejouer : remise INT → `DECIMAL(5,2)` (14 et 16-17/08, le pourcentage reste un pourcentage) ; renommage `facturer_a_un_tiers` → `facturer_aun_tiers` (17/08, déjà fait ; sinon colonne en trop remplie, donc bloquant) ; table `parametres_paiement` et sa ligne de taux 1 % (20/08 : la nouvelle version prend ces taux quand la table est vide) ; jeton des conversations (21/08, table créée vide) ; compteur m² entier → décimal (31/08, mêmes valeurs) ; valeurs par défaut des colonnes ajoutées (`devis.emetteur`, `clients.recevoir_sms` = 1, `solde_credit` = 0…), que la bascule donne aussi. La migration de base `Version20261010202721` ne contient aucun INSERT, UPDATE ni DELETE. Les migrations de juillet absentes de tout dépôt ne peuvent pas être auditées.

**Remises : quel sens ?**

- `DECIMAL(5,2)` ou `DECIMAL(7,4)` : ce sont les deux types de la remise en pourcentage dans l'ancienne version (saisie limitée de 0 à 100), jamais un montant. Conversion, sauf si une valeur est hors de 0 à 100 (bloquant).
- Nombre entier (version plus ancienne, comme la copie du 01/08, ou déjà en F par unité) : décidé d'après les lignes. Chaque ligne remisée est recalculée des deux façons. Pourcentage si des lignes ne suivent que le calcul en pourcentage, aucune que le calcul en F par unité, et pas moins que de lignes qui ne suivent aucun des deux ; F par unité dans le cas inverse (rien à convertir) ; sinon **bloquant**, avec les nombres : la bascule ne devine pas.
- Copie de la structure sans données : un `DECIMAL(5,2)`/`(7,4)` est annoncé comme conversion ; un entier part dans « à revérifier sur la vraie base ».

**Remises : la règle de conversion, ligne par ligne** (les totaux enregistrés ne sont jamais modifiés)

1. Brut recalculé comme la nouvelle version : arrondi de prix unitaire × unités facturables (m² × quantité, mètres × quantité, faces × quantité, quantité, ou 1 au forfait) + finitions de la ligne.
2. Montant de la remise : brut − total enregistré quand l'ancien calcul redonne ce total (brut − arrondi de brut × p / 100), ou quand p = 100 ; sinon la formule de l'ancienne mise à jour, depuis le total enregistré : arrondi de total × p / (100 − p).
3. Remise par unité : montant / unités facturables, arrondi au franc. Ligne offerte (montant = brut, p = 100) : arrondi au franc supérieur, le plafond de la nouvelle version redonne exactement 0. Aucune unité facturable (surface vide au m²) : 0.
4. Comme l'ancienne mise à jour, la colonne est d'abord élargie (`DECIMAL(15,4)`, un montant ne tient pas dans `DECIMAL(5,2)`), puis passe en entier avec le reste du schéma.

**Contrôle** : chaque ligne convertie est recalculée avec le nouveau calcul (brut − min(brut, remise × unités)) et comparée à son total enregistré :

- *identique* ;
- *à 1 F près par unité* : l'arrondi de la remise par unité (par exemple 12,5 % de 1 000 flyers à 25 F = 3,125 F par flyer, gardé à 3 F : 125 F d'écart si la ligne est recalculée) ;
- *déjà différente avant la bascule* : l'ancien calcul ne redonnait déjà pas le total enregistré (total changé à la main, prix modifié…). Signalée, pas bloquante : la bascule ne peut pas faire mieux que l'ancienne mise à jour ;
- *au-delà* : sur une ligne qui était cohérente. La vérification le dit d'avance (essai du calcul en mémoire, **bloquant**) ; si cela arrivait quand même pendant `--corriger`, la commande s'arrête et donne la ligne pour remettre la sauvegarde.

### Garder le total

Décision de Yaya (15/10/2026) : une ligne convertie garde son total enregistré, même réenregistrée dans la nouvelle version, tant que rien de ce qui fait son total ne change.

- `--corriger` marque `total_fige` = 1 chaque ligne convertie dont le contrôle n'est pas *identique* (à 1 F près par unité, ou déjà différente avant la bascule). Il l'annonce : « commandes_details : N ligne(s) gardent leur total d'origine tant qu'elles ne sont pas modifiées. »
- Réenregistrer la ligne sans rien changer garde le total lu en base, même si la page en affiche un autre dans son aperçu.
- Changer sa quantité, son prix, sa remise (y compris celle d'une promotion ou d'un code promo), sa TVA, son mode de calcul, ses dimensions ou ses finitions la recalcule ; elle redevient alors une ligne ordinaire (`total_fige` = 0).
- La fiche du devis ou de la commande et la saisie l'indiquent discrètement : « Total d'origine conservé ». Un devis accepté transformé en commande passe la marque à la ligne de la commande.
- Sur le devis et la facture, la remise affichée de la ligne est ce qui a vraiment été retiré (brut − total enregistré).

Les lignes *identiques* ne sont pas marquées : recalculées, elles redonnent leur total.

Dans le code : `ConversionsDonnees::figerTotaux` (bascule), `TotalFigeTrait` (lignes), migration `Version20261015231500`.

**Répétition du 15/10/2026** sur la structure du 15/10 avec des données d'essai fictives (2 clients « Client essai », 2 commandes, 2 devis, 16 lignes : 0 %, 10 %, 12,5 %, 33,33 %, 100 %, 14,2857 %, au m², au mètre, recto verso, au forfait, avec finitions, totaux calculés comme l'ancienne version, plus une ligne au total changé à la main), puis `--corriger`, `migrate`, `schema:validate` (OK), et contrôle par les entités de l'application :

| Essai | Résultat |
|---|---|
| Structure du 15/10 + données d'essai | lignes de commande : 3 identiques, 4 à 1 F près par unité (écarts de 1, 2, 12 et 125 F), 1 déjà différente ; lignes de devis : 3 identiques, 2 à 1 F près (8 et 1 000 F sur 2 000 flyers à 12,5 %) ; 2 articles rendus vendables ; tous les totaux et toutes les lignes identiques avant et après |
| Même chose + une ligne au m² sans dimensions, remisée | bloquant à la vérification (« 1 au-delà ») ; `--corriger` refuse, base non modifiée |
| Remise en nombre entier en pourcentage (version plus ancienne) | décidé « pourcentage » d'après les lignes, converti, mêmes contrôles |
| Remise en nombre entier, une ligne en F par unité au milieu | bloquant, avec les nombres de lignes de chaque calcul |
| Structure plus ancienne (`type_compte`, amortissement absents, `vendable` déjà par défaut à 1) | 1 client B2B → « entreprise », 2 articles → « consommable en production », 1 traceur → récapitulatif d'amortissement |
| Structure seule (sans lignes) | 3 conversions annoncées, 2 questions « à revérifier » ; `--corriger`, `migrate`, `schema:validate` OK |
| « Garder le total » (même structure, mêmes données fictives, base supprimée ensuite) | `--corriger` : 5 lignes de commande (4 à 1 F près, 1 déjà différente) et 2 lignes de devis marquées `total_fige` ; `migrate` (la migration `Version20261015231500` ne recrée pas la colonne), `schema:validate` OK ; totaux des lignes et des documents identiques avant et après ; contrôle par les entités : les 16 lignes réenregistrées sans changement gardent leur total, une ligne marquée passée de 1 000 à 1 200 exemplaires est recalculée (26 400 F) et n'est plus marquée |

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
