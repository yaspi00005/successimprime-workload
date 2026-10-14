# Synchronisation ERP local ⇄ site en ligne (jalon J4, maquette 39)

## Principe

- Deux installations du même code : l'**ERP local** de la boutique (base maître, `APP_EN_LIGNE=0`) et le **site en ligne** (sa propre base, `APP_EN_LIGNE=1`).
- La boutique n'a pas d'adresse fixe : c'est **toujours l'agent du local qui appelle le site**, chaque minute. Le site ne contacte jamais le local.
- Chaque donnée garde le même identifiant public (UUID) des deux côtés ; les id numériques diffèrent.
- **Rien de sensible ne quitte le local.** Les champs envoyés sont listés un par un dans `src/Service/Synchro/CatalogueSynchro.php` ; une colonne ajoutée plus tard reste au local tant qu'on ne l'ajoute pas à la liste. Restent au local : coûts de revient, marges, comptabilité, trésorerie, dépenses et budgets (tables `depense` et `budget_depense`, avec les reçus), relevés Orange Money importés (table `ligne_releve_orange_money`), salaires et données du personnel, notes internes sur les clients, remise habituelle, commercial qui suit le client.

## Ce qui passe (étapes 1 et 2)

| Donnée | Sens | Règle |
|---|---|---|
| Entreprises : code, raison sociale, nom commercial, mentions, adresse, téléphone, e-mail, site, RCCM, NIF, numéro marchand Orange Money, couleurs | local → site | Pour le pied de page et le bouton WhatsApp du site vitrine. Restent au local : banque, compte bancaire, numérotation, logos, cachet et signature. |
| Catégories, types d'impression, finitions (sans le coût), formats, supports, produits, configurations de produit et leurs finitions proposées (prix de vente) | local → site | Le local est maître : le site reçoit et remplace. |
| Clients | local → site, et site → local pour les **nouveaux** clients | Une modification faite en ligne d'un client du local n'est pas appliquée : elle est notée **Conflit** dans le journal, la fiche du local est gardée. |
| Commandes et leurs lignes | local → site, et site → local pour les **nouvelles** commandes | Restent au local : note interne, agents, coût de revient, temps, machine, rebuts, notes d'atelier. Une commande créée en ligne n'a pas d'agent ; elle arrive en brouillon « à confirmer », avec la demande écrite par le client sur la ligne, et le rôle Commandes est prévenu. |
| Fichiers envoyés par le client dans son espace (Mes fichiers, Nouvelle commande) | site → local | La fiche (nom, contrôle automatique, empreinte, logo) remonte dès que l'envoi est fini, puis les octets (voir **Fichiers**). |
| Finitions choisies sur une ligne (nom, prix appliqué, quantité, montant) | avec leur ligne | Elles partent dans la ligne et sont remplacées en bloc ; retirer une finition renvoie la ligne. |
| BAT : état (en attente, validé, à corriger), fichiers de la commande (nom, type, taille) et suivi visible par le client (versions envoyées, ses demandes, validation) | local → site | Restent au local : contrôles et anomalies, noms de l'atelier, graphiste, temps, erreurs, points, chemin et notes des fichiers. |
| Produits du client (« Mes produits ») : référence, nom, format, unité de vente, prix négocié, minimum, lot, finitions, fichier et BAT validé | local → site | Restent au local : machine, ligne de commande d'origine. Une ligne de commande en ligne qui cite un produit du client est revérifiée à l'arrivée (même client, BAT validé), sinon elle repasse par la PAO. |
| Bons de livraison : numéro, état, jour prévu, créneau, heures (préparé, chargé, en route, arrivé, livré) et quantités livrées par ligne | local → site | Pour « Livreur en route » et « Commande livrée » dans l'espace client, avec leur notification. Restent au local : livreur, réceptionnaire, adresse, photos, signature, position, encaissement, stock, notes. Un bon annulé n'est pas montré au client. |
| Devis et leurs lignes, factures | local → site seulement | Restent au local : coûts, notes, comptabilisation, majoration, PDF du serveur. |
| Paiements | local → site, et site → local pour les **nouveaux** paiements | Restent au local : compte de trésorerie, mouvement, justificatif, note, encaisseur. |
| Fidélité : résumé du compte de points, mouvements, promo de réveil (`fidelite_compte`, `fidelite_mouvement`, `promo_reveil`) | local → site seulement | Liste blanche : type, points, date, expiration, multiplicateur, numéro de commande, solde et progression. Restent au local : agent, motif, paiement, valeurs propres et qui les a fixées, règles par article. Un mouvement à 0 point ne part pas (`pret`, respecté aussi par l'initialisation). Voir `docs/fidelite.md`. |
| Demandes du site (`demande_devis`, maquette 11) : message ou demande de devis d'un visiteur sans compte (nom, téléphone, e-mail, objet, produit, taille, quantité, finitions, message, fichier joint, accusé SMS) | site → local seulement (sens **ascendant**) | Le local ne renvoie rien : en ligne, personne ne les relit. À l'arrivée, le local crée le prospect « Site » ou rattache le client du même numéro, prépare un devis brouillon au tarif public et prévient le rôle Devis ; la fiche client et le devis redescendent ensuite comme les autres (brouillon, invisible du client). Un produit retiré du catalogue entre-temps ne bloque pas la demande. |
| Réglages publics (geste `reglages`) : Espace client (connexion par code, commande et paiement en ligne), Orange Money oui/non, TVA par défaut et taux, horaires d'ouverture, lien du plan de la page Contact | local → site seulement | Partent à chaque enregistrement ou validation au local, champ par champ (`CatalogueSynchro::REGLAGES_PUBLICS`), et sont contrôlés à l'arrivée comme une saisie. Les autres tuiles et champs (paie, barème, comptes, sauvegardes…) ne quittent pas le local. Premier envoi : `app:synchro --initialiser=reglages`. |
| Changement de réglage fait dans l'admin en ligne (geste `reglage_propose`) | site → local | Arrive comme proposition, jamais appliqué tout seul : la direction est prévenue et valide ou refuse dans Réglages › Paramètres ; une fois validé, la valeur redescend. Tuile inconnue ou valeur hors limites : refusé. |

**Paiement saisi en ligne** : il arrive au local **toujours en attente**, quel que soit son statut en ligne, sans compte et sans encaisseur (« Client, en ligne »). Il apparaît dans Finances › À valider, où le responsable choisit le compte qui a reçu l'argent avant de valider ; sans compte choisi, la validation est refusée. Une fois validé, le statut repart vers le site.

**Décisions du client prises en ligne** : le local les applique, car elles appartiennent au client. Ce sont les seules modifications faites en ligne qu'il reprend (`remontees` dans `CatalogueSynchro`) :

- accepter un devis **envoyé** : le devis passe « accepté » au local et les personnes qui ont le rôle Devis sont prévenues (« à transformer en commande ») ;
- ses préférences : recevoir les SMS, accord pour les offres. Le changement est noté dans le journal de la fiche client (« Espace client »).
- le jeton du lien PDF d'un devis ou d'une facture créé en ligne quand le local n'en avait pas ;
- ses fichiers : en retirer un (pas déjà dans une commande), en mettre un dans une commande, et le contrôle refait à la taille commandée.

Toute autre modification faite en ligne d'une fiche du local est notée **Conflit** et n'est pas appliquée ; le local renvoie sa version au cycle suivant, pour que le site redevienne identique.

**Réponse du client à un BAT** : quand il valide ou demande une modification en ligne, ce geste part vers le local, qui le **rejoue avec ses propres règles** : points du graphiste, alerte à la prépresse avec le texte du client, état du BAT. Le site reprend ensuite l'état du local. Si le BAT n'attendait plus le client au local (l'atelier a déjà avancé), le geste est noté en conflit et une demande de modification est quand même transmise à la prépresse.

**Fichiers** : vers le site, seuls les fichiers préparés par l'atelier pour un BAT partent (pas ceux de production). Du site, le local rapatrie les fichiers envoyés par les clients (60 derniers jours, ceux d'une commande d'abord). Par morceaux de 4 Mo, 64 Mo au plus par cycle dans les deux sens réunis, repris là où ça s'est arrêté après une coupure ; l'empreinte SHA-256 est vérifiée avant de ranger le fichier dans `var/uploads/commandes`. Un fichier n'est renvoyé que s'il change.

**Ordre** : un élément qui cite une donnée arrivant plus loin dans le même lot (fichier mis dans une commande créée après lui) attend qu'elle soit appliquée, puis passe. Quelques liens sont **facultatifs** (`facultatives` dans `CatalogueSynchro`, par exemple le produit du client d'une ligne de commande) : si la donnée citée manque, le lien reste vide au lieu de bloquer l'élément.

**Numéros** : une commande, un devis ou un client créé en ligne reçoit le préfixe **W-** (W-CMD-…) ; il ne prend donc jamais un numéro du local.

Étapes suivantes : modification des coordonnées par le client en ligne, à faire valider au local quand l'espace client le proposera. Les comptes de l'espace client (connexion par téléphone) restent sur le site : un client qui les perd en recrée un par code SMS.

## Fonctionnement

**Fichier de client perdu en ligne** : s'il manque sur le site, il ne bloque plus le cycle. Il est noté une fois dans le journal (« fichier de client introuvable sur le site ») et redemandé toutes les 6 heures ; les fiches et les autres fichiers passent.

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
4. Premier chargement du site : `php bin/console app:synchro --initialiser` sur le local. Pour un seul type, par exemple après la mise à jour qui envoie les finitions des configurations : `php bin/console app:synchro --initialiser=configuration`.
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

Étape 2, même jour :

- Chargement initial complet : 6 560 données (812 commandes, 1 444 lignes, 654 devis, 1 123 lignes de devis, 618 factures, 1 029 paiements), environ 110 par seconde. Nombres identiques des deux côtés ; en ligne, coûts de revient à zéro, aucun compte de trésorerie, encaisseur, note ni majoration.
- Une commande créée en ligne reçoit le numéro W-CMD-… et arrive au local sans agent.
- Un paiement créé en ligne puis passé « validé » en ligne arrive au local **en attente**, sans compte. La validation sans compte est refusée ; avec le compte Orange Money choisi, elle passe, et le site reçoit le statut validé.
- Devis envoyé accepté en ligne : accepté au local, 12 personnes du rôle Devis prévenues. Devis brouillon passé « accepté » en ligne : conflit, gardé brouillon au local.
- Finitions des lignes : 440 + 334 chargées ; un nom changé et une finition retirée au local arrivent en ligne ; un rechargement complet ne recrée aucune finition.
- BAT : 517 BAT, 510 fichiers et leur suivi chargés. Un fichier de BAT de 9 Mo arrive en ligne en 3 morceaux, identique (même empreinte), et n'est pas renvoyé au cycle suivant. Le client demande une modification depuis son espace en ligne : au local le BAT passe « à corriger » et 4 personnes de la prépresse reçoivent son texte. Il valide un autre BAT : au local il passe « en cours », validé.
- Nouvelle commande passée en ligne (bâche 300 × 100, œillets, fichier joint, précision écrite) : arrive au local en brouillon W-CMD-…, avec sa ligne, sa finition, la demande du client et son fichier ; notification « à vérifier, puis à confirmer ». Un fichier client de 10 Mo est rapatrié en 3 morceaux, même empreinte.
- SMS coupés en ligne et ville changée en ligne : SMS repris au local (noté sur la fiche), ville refusée, puis remise en ligne au cycle suivant.
