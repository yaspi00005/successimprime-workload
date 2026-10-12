# Synchronisation ERP local ⇄ site en ligne (jalon J4, maquette 39)

## Principe

- Deux installations du même code : l'**ERP local** de la boutique (base maître, `APP_EN_LIGNE=0`) et le **site en ligne** (sa propre base, `APP_EN_LIGNE=1`).
- La boutique n'a pas d'adresse fixe : c'est **toujours l'agent du local qui appelle le site**, chaque minute. Le site ne contacte jamais le local.
- Chaque donnée garde le même identifiant public (UUID) des deux côtés ; les id numériques diffèrent.
- **Rien de sensible ne quitte le local.** Les champs envoyés sont listés un par un dans `src/Service/Synchro/CatalogueSynchro.php` ; une colonne ajoutée plus tard reste au local tant qu'on ne l'ajoute pas à la liste. Restent au local : coûts de revient, marges, comptabilité, trésorerie, salaires et données du personnel, notes internes sur les clients, remise habituelle, commercial qui suit le client.

## Ce qui passe (étape 1)

| Donnée | Sens | Règle |
|---|---|---|
| Catégories, types d'impression, finitions (sans le coût), formats, supports, produits, configurations de produit | local → site | Le local est maître : le site reçoit et remplace. |
| Clients | local → site, et site → local pour les **nouveaux** clients | Une modification faite en ligne d'un client du local n'est pas appliquée : elle est notée **Conflit** dans le journal, la fiche du local est gardée. |

Étapes suivantes : devis, commandes, factures et paiements vers l'espace client ; paiements saisis en ligne qui arrivent en provisoire dans « À valider » ; modifications de fiche client faites en ligne qui attendent une validation au local ; fichiers.

## Fonctionnement

1. Toute création, modification ou suppression d'une donnée suivie ajoute une ligne à la file `synchro_journal`, **dans la même transaction** que la modification : si l'enregistrement réussit, l'envoi est garanti.
2. Le contenu est relu au moment de l'envoi : une fiche modifiée trois fois avant l'envoi part avec son dernier état.
3. Chaque envoi porte un identifiant unique. L'autre côté note ceux qu'il a appliqués (`synchro_recu`) : **un rejeu après une coupure ne crée jamais de doublon**.
4. L'ordre est respecté. Une donnée qui en référence une autre pas encore arrivée est rejouée au cycle suivant (« Reprise auto »).
5. Un élément qui échoue 10 fois est **bloqué** : il apparaît en haut de l'écran Synchronisation avec l'erreur, et l'administrateur peut l'écarter.
6. Les données reçues ne sont pas renvoyées (pas d'écho).

Un cycle de l'agent : vérifier que le site répond, envoyer la file du local, recevoir les créations faites en ligne, accuser réception.

## Sécurité

- Les échanges passent par `/api/synchro/*` sur le site, en HTTPS. Ces adresses n'existent pas sur l'ERP local, ni sur un site sans clé.
- Chaque requête et chaque réponse est **signée** (HMAC-SHA256) avec la clé commune `SYNC_CLE` et datée. Une requête modifiée, signée avec une autre clé ou rejouée plus de 5 minutes après est refusée.
- Les deux machines doivent être à l'heure (écart de moins de 5 minutes).
- La clé n'est jamais dans le dépôt ni envoyée par message : `.env.local` des deux machines seulement.

## Mise en route

1. Générer une clé, une seule fois : `php bin/console app:synchro --nouvelle-cle`.
2. Sur le **site** (`.env.local`) : `APP_EN_LIGNE=1` et `SYNC_CLE=...`.
3. Sur l'**ERP local** (`.env.local`) : `SYNC_URL=https://succesimprim.com` et la même `SYNC_CLE=...`.
4. Premier chargement du site : `php bin/console app:synchro --initialiser` sur le local.
5. Tâche planifiée chaque minute :
   - Windows : vérifier les chemins de `scripts\windows\synchro.bat`, puis lancer une fois `scripts\windows\installer_tache_synchro.bat` en administrateur. Journal : `var\log\synchro.log`.
   - Linux ou Mac : `* * * * * cd /chemin/du/projet && php bin/console app:synchro -q >> var/log/synchro.log 2>&1`.
   - Ou bien `php bin/console app:synchro --boucle`, qui refait un cycle toutes les 30 s.

Les deux installations doivent avoir le même code et les mêmes migrations. L'écran affiche la version du schéma vue sur le site.

## Écran Réglages › Sécurité › Synchronisation (ROLE_ADMIN)

- Dernière synchro réussie, file d'attente, reçus du jour, erreurs sur 24 h (résolues automatiquement quand un cycle complet réussit).
- Derniers échanges : sens, donnée, statut (OK, Reprise auto, Conflit, Erreur). Le journal complet se filtre par sens et statut ; il est gardé 90 jours.
- État des liaisons : agent local, site (temps de réponse), connexion internet, webhook Orange Money (J5), sauvegarde quotidienne.
- « Synchroniser maintenant » lance un cycle tout de suite.
- En bas du menu : « Synchronisé avec … il y a … », en rouge en cas de panne.

**Alerte** : si aucune synchro ne réussit pendant 15 minutes, chaque administrateur reçoit une notification, une seule fois par panne, avec l'envoi sur son téléphone si les notifications sont activées. L'alerte s'affiche aussi sur le tableau de bord. Une ligne « Liaison rétablie » est notée au retour.

## Testé le 12/10/2026

Deux installations sur la même machine (local et copie « en ligne ») :

- Chargement initial : 287 données. Les nombres de lignes sont identiques des deux côtés ; les notes internes, remises et coûts des finitions sont restés vides en ligne.
- Une modification au local (nom et formats d'un produit) arrive en ligne.
- Un client créé en ligne arrive au local, sans écho.
- Une modification en ligne d'un client du local est notée en conflit et n'est pas appliquée.
- Coupure du site avec 3 changements en attente :
  - l'incident est noté une seule fois ;
  - après 15 minutes, l'alerte est envoyée aux administrateurs une seule fois ;
  - au retour, tout passe dans l'ordre et « Liaison rétablie » est noté.
- Rejeu des 5 derniers envois : aucun doublon.
