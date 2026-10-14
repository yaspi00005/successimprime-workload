# Fidélité

Les règles ont été fixées par Yaya le 13/10/2026 : pas de paliers, pas de parrainage. La programmation par article a été ajoutée le même jour.

Le calcul se fait sur l'ERP de la boutique. Le site en ligne reçoit le résumé et le relevé par la synchronisation ; il n'y recalcule rien et refuse les actions.

## Les règles

| Règle | Valeur par défaut (Réglages › Paramètres › Fidélité) |
|---|---|
| Qui gagne | les particuliers seulement ; les clients au prix B2B sont exclus |
| Valeur du point | 1 point = 1 F |
| Grand format (lignes au m²) | 100 points par m², seulement au-delà de 5 m² payés |
| Autres articles | 2 % du montant, seulement au-delà de 25 000 F payés |
| Délai de paiement | ×2 si payé le jour même, ×1 sous 72 h, rien après |
| Validité | 12 mois ; les points les plus anciens sont utilisés d'abord |
| Alerte « expirent bientôt » | 30 jours |
| Clients dormeurs | sans commande depuis 90 jours ; promo de réveil de 10 % valable 15 jours |

Le programme s'arrête avec l'interrupteur « Programme actif » de la tuile. Les points déjà gagnés restent et peuvent toujours être utilisés.

### Le seuil

Le seuil se franchit avec les lignes payées, quel que soit le délai : un paiement tardif ne rapporte rien, mais il fait avancer la progression. Seule la partie au-delà du seuil rapporte.

Par exemple, un client a 3 m² payés. Il paie 4 m² de bâche. Il passe à 7 m², donc 2 m² sont au-delà du seuil de 5 m² : 2 × 100 = 200 points, ×2 s'il a payé le jour même.

### Le multiplicateur

Il se calcule à chaque paiement validé, sur la part de la commande que ce paiement règle (montant du paiement ÷ total des lignes non annulées).

La date de référence est celle de la facture si la commande en a une, sinon celle de la commande. La date retenue pour le paiement est celle du paiement, pas celle de sa validation : un paiement Orange Money fait le jour même, et validé le lendemain par la caisse, compte ×2.

| Paiement | Multiplicateur |
|---|---|
| Le jour de référence, ou avant (acompte) | ×2 |
| Dans les 72 h qui suivent | ×1 |
| Après | 0 : pas de points, mais la progression avance |

### Ce qui rapporte, et ce qui retire

- **Paiement validé** : il rapporte. Un paiement en attente (Orange Money en ligne, chèque) ne rapporte rien tant que la caisse ne l'a pas validé.
- **Paiement annulé ou rejeté** : ses points sont retirés, par une ligne « annulation » au relevé.
- **Commande annulée ou supprimée** : tous ses points sont retirés. Si elle est rétablie, ils sont recomptés.
- **Article annulé seul** : ses points sont retirés. S'il est rétabli, il les retrouve, au même multiplicateur.
- **Paiement en points** : il ne rapporte rien.

Si des points retirés ont déjà été dépensés, le solde ne descend pas sous zéro. La différence est notée comme une « dette », reprise sur les prochains gains.

## Fidélité par article

Écran : **Clients › Fidélité › Par article**, ou l'icône médaille dans la liste des produits. Il est réservé à la direction (ROLE_ADMIN).

Une règle d'article contient :

| Champ | Sens |
|---|---|
| Bonification | Active, ou Désactivée. Désactivée, l'article ne rapporte aucun point, même pour un client aux valeurs propres. Il compte quand même dans la progression. |
| Les points | Par unité (par m² pour un produit au m², par pièce ou lot sinon), ou en % du montant payé |
| Valeur du point | En F CFA (1 par défaut). Le solde en francs du client compte chaque lot à sa valeur. |
| Plage | Seuil à dépasser avant de gagner. Il s'exprime en m² pour un produit au m², en pièces pour une règle « par unité », en F pour une règle en %. Il se compte sur cet article seulement, ou sur toute sa famille (grand format en m², autres articles en F). |
| Début, fin | Dates incluses ; la fin est facultative. On regarde le jour du paiement. |
| Note | Note interne, jamais envoyée au site |

Si plusieurs règles du même article couvrent le jour du paiement, la plus récente l'emporte.

### Ordre de priorité

Le taux et le seuil sont choisis séparément, dans cet ordre :

1. les valeurs propres au client (fiche client, onglet Fidélité, direction seulement) ;
2. la règle de l'article valable le jour du paiement ;
3. les valeurs générales de la tuile.

Il y a une exception : une règle **désactivée** l'emporte sur tout.

Chaque création, modification ou suppression de règle est inscrite au journal (**Clients › Fidélité › Journal**). L'entrée note qui, quand, et l'ancienne et la nouvelle valeur de chaque champ changé.

## Côté boutique

| Où | Qui | Quoi |
|---|---|---|
| Fiche client, onglet Fidélité | ROLE_COMMANDE | Solde en points et en F, points qui vont expirer, progression vers chaque seuil, relevé, promo de réveil en cours |
| Même onglet | ROLE_ADMIN | Ajouter ou retirer des points (motif obligatoire), valeurs propres au client (motif obligatoire), offrir une promo de réveil ; motifs et agents visibles dans le relevé |
| Commande, bloc Fidélité | ROLE_COMMANDE | Utiliser des points sur le reste à payer, les retirer, voir ce que le paiement du jour rapporterait (×2), appliquer la promo de réveil |
| Clients › Fidélité | ROLE_ADMIN | Par article, Clients dormeurs, Journal |

Les droits sont vérifiés par `FideliteController`, pas seulement par les gabarits. Les clients B2B n'ont ni onglet ni bloc.

### Utiliser des points

Seule la boutique peut le faire, jamais le client lui-même. La commande doit être confirmée, non annulée, et avoir un reste à payer.

Les points utilisés deviennent un **paiement « Points fidélité »**, validé tout de suite et plafonné au reste à payer. On l'enregistre comme un paiement et non comme une remise, car dans l'ERP seuls les paiements réduisent ce qui reste dû. La remise de commande ne le fait pas.

Ce mode de paiement fonctionne comme « Solde client » :

- il n'est jamais proposé dans le formulaire de paiement ;
- il n'a pas de compte de trésorerie ;
- il est exclu des encaissements, de la caisse du jour, de la prévision de trésorerie et de la vue d'ensemble (`Paiements::MODES_SANS_ENCAISSEMENT`).

Une facture comptabilisée de la commande reprend ce paiement.

« Retirer » annule ce paiement : les points sont rendus avec leur date d'expiration d'origine.

**Écart connu** : comme pour le solde client, aucune écriture comptable de remise n'est passée. Le chiffre d'affaires reste le montant facturé.

## Espace client

Écran : **Mon compte › Fidélité** (`/espace/fidelite`, maquette 18, téléphone de droite). Il ne s'affiche pas pour un client B2B, et seuls les payeurs d'un compte le voient.

| Maquette | Remplacé par |
|---|---|
| Palier | Solde en points et en F |
| Barre vers le palier suivant | Progression vers le seuil pas encore atteint (« 3,2 / 5 m² », « 18 000 / 25 000 F ») |
| « Mes récompenses » | « Ma progression » : grand format et autres articles, « Actif » une fois le seuil franchi |
| Parrainage | « Payez le jour même : points ×2 » |

La page montre aussi les points qui vont expirer, la promo de réveil en cours et l'historique (15 lignes).

La page est en lecture seule. Elle ne lit que la fiche du client connecté. Elle ne montre ni agent, ni motif, ni ligne à 0 point.

## Synchronisation (local → en ligne)

Elle va dans un seul sens (`CatalogueSynchro`) :

| Type | Ce qui part | Ce qui reste au local |
|---|---|---|
| `fidelite_compte` | solde, solde en F, cumuls m² et F, seuils et taux appliqués, multiplicateur, délai, points qui expirent, prochaine expiration, client | valeurs propres saisies, motif, auteur |
| `fidelite_mouvement` | type, points, date, expiration, multiplicateur, commande, client | agent, motif, ligne, produit, paiement, montant, règle, lot visé ; les lignes à 0 point ne partent pas |
| `promo_reveil` | taux, début, fin, utilisée le, retirée le, commande, client | auteur, jours sans commande, montant de la remise |

Le multiplicateur s'ajoute à la liste demandée (type, points, date, expiration, commande) pour que l'historique du client dise « Payé le jour même ×2 ». C'est une information sur ses propres paiements.

Au premier déploiement : `php bin/console app:synchro --initialiser=fidelite_compte`, puis `--initialiser=fidelite_mouvement` et `--initialiser=promo_reveil`.

## Clients dormeurs et promo de réveil

C'est une version minimale. **Clients › Fidélité › Clients dormeurs** liste les particuliers sans commande depuis N jours (90 par défaut, réglable sur l'écran), avec :

- la date de leur dernière commande ;
- leur nombre de commandes ;
- leur promo en cours.

La direction offre une promo : un pourcentage (1 à 50 %), valable N jours ou aujourd'hui seulement. Elle est inscrite au journal.

Le client la voit dans son espace (rubrique Fidélité). À la boutique, le bloc Fidélité de sa commande propose « Appliquer ». La remise est ajoutée à chaque article (prix unitaire × taux, comme une remise saisie à la main), puis les totaux sont recalculés. C'est refusé si la commande est déjà facturée. La promo est alors marquée utilisée.

Un lien WhatsApp (wa.me) ouvre un message prêt, sans montant ni taux, et jamais pour une fiche de démonstration (code `DEMO-`). Rien n'est envoyé automatiquement.

**Reste à faire** :

- envoi groupé par campagne ;
- retirer une promo appliquée ;
- promo automatique (sans geste de la direction) ;
- mesure du retour des dormeurs.

## Tâches planifiées et commandes

```
php bin/console app:fidelite                       # chaque nuit : expirations, résumés, rattrapage des 3 derniers jours
php bin/console app:fidelite --rattraper=30        # après un incident (journalisé) : recompte les paiements des 30 derniers jours
php bin/console app:fidelite --recalculer --depuis=2026-10-13 [--client=CODE]   # refait les gains depuis une date
php bin/console app:fidelite --demo                # relevés de démonstration (refusé en production, rejouable)
```

Le programme commence au déploiement. Les paiements validés avant ne rapportent rien, sauf si l'on lance `--recalculer --depuis=…`.

`--recalculer` ne touche ni aux ajustements, ni aux points utilisés, ni aux points rendus.

## Démonstration

`--demo` rejoue l'année de démonstration pour les particuliers `DEMO-` et `CL-DEMO`, puis ajoute une seule fois des gestes de la boutique :

- trois règles d'article sur les produits « (démo) » : roll-up à 500 points par pièce dès le 3e, cartes de visite désactivées, vinyle à 150 points par m² avec un point à 2 F ;
- des valeurs propres pour un gros client ;
- des points offerts, dont un lot ancien qui expire bientôt pour CL-DEMO1 (le client de l'espace de démonstration) ;
- un retrait ;
- des points utilisés sur une commande ;
- deux promos de réveil.

## Code

- `src/Service/Fidelite/CalculFidelite.php` contient le calcul seul, sans base de données, testé par `tests/Unit/Service/Fidelite/CalculFideliteTest.php`. Il couvre :
  - les familles et le franchissement des seuils ;
  - les paiements partiels ;
  - les multiplicateurs ;
  - les exclusions ;
  - l'ordre de priorité client > article > général, et la règle désactivée ;
  - la fenêtre de dates ;
  - le premier entré, premier sorti, l'expiration et la dette ;
  - le plafond d'utilisation.
- `src/Service/Fidelite/Fidelite.php` tient le relevé : gains, retraits, utilisation, ajustements, expiration, résumé.
- `src/EventSubscriber/FideliteSubscriber.php` écoute la validation et l'annulation des paiements, ainsi que l'annulation et le rétablissement des commandes et des articles. Il écrit le relevé après l'enregistrement. Un incident n'y bloque jamais l'encaissement : il est journalisé et se rattrape par la commande.
- `src/Service/Fidelite/ReveilClients.php` gère les clients dormeurs et la promo de réveil.
- Les entités sont `MouvementFidelite` (le relevé), `CompteFidelite` (résumé et valeurs propres), `RegleFidelite` et `PromoReveil`. La migration est `Version20261013223645`.
