# Promotions par segment de clients

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

## En place (14/10/2026)

Tout se gère sur l'ERP de la boutique. Le site en ligne reçoit les promotions pour l'espace client, mais ne les modifie pas.

### Écrans et droits

| Écran | Où | Qui |
|---|---|---|
| Liste des promotions, par état (en cours, à venir, terminées, suspendues) | Ventes › Promotions (`/promotions`) | Droit **Promotions**, ou qui saisit devis ou commandes (lecture) |
| Fiche et suivi d'une promotion | `/promotions/{id}` | Idem. Le journal des modifications : droit Promotions seulement |
| Créer, modifier, suspendre, supprimer | Boutons de la liste et de la fiche | Droit Promotions |
| Exceptions B2B à décider | Ventes › Promotions, bouton « Exceptions B2B » (`/promotions/exceptions`), avec une pastille au menu | Droit Promotions |
| Anniversaires à venir et modèles par palier | Clients › Anniversaires (`/promotions/anniversaires`) | Droit Promotions |
| Réglages (alertes, anniversaires) | Paramètres › Promotions (famille Ventes) | Droit Paramètres |
| Mes promotions | Espace client › Compte (`/espace/promotions`) | Le client connecté, pour ses promotions seulement |

- **Droit Promotions** (`ROLE_PROMOTIONS`, Réglages › Rôles et droits, groupe Fonctions) : créer, suspendre, accepter les exceptions B2B, gérer les anniversaires. L'administrateur l'a par la hiérarchie de `security.yaml`.
- **Montants** : ils ne sont calculés que pour qui a le droit de les voir (`DroitsStatistiques`, comme les statistiques). Le CA et les remises demandent le droit « ventes » (`ROLE_STATS_VENTES` ou `ROLE_STATS_GLOBAL`). La marge demande le droit « marges » (`ROLE_COUT_MARGE`). Sans ces droits, la liste et la fiche ne donnent que les nombres de lignes, de commandes et de clients. Les montants ne sont pas seulement cachés dans la page : ils ne sont pas calculés.
- La caisse voit la promotion sur la ligne qu'elle saisit (libellé et remise), comme le reste du prix.

### Une promotion

- **Articles** : un ou plusieurs produits, et/ou une catégorie entière.
- **Avantage** : pourcentage (90 % au plus), montant en francs par unité, ou prix fixe par unité.
- **Période** : du premier au dernier jour compris. Une nouvelle promotion commence aujourd'hui au plus tôt.
- **Segment** : tous les clients, particuliers, entreprises et institutions, nouveaux clients, actifs (achat sur 12 mois), inactifs depuis 90 jours, fidélité (seuil franchi), gros clients (500 000 F et plus sur 12 mois), ou liste choisie à la main. Les segments calculés sont ceux des campagnes (`SegmentsClients`).
- **Conditions** : quantité minimale de la ligne, montant minimal de la ligne avant remise, code promo facultatif, nombre de commandes par client.
- Une promotion déjà portée par des lignes ne se supprime pas : elle se suspend et reste dans l'historique. Chaque création, modification, suspension ou suppression est inscrite au journal d'activité.

### À la saisie d'un devis ou d'une commande

- Pendant la saisie, le formulaire demande au serveur la promotion de chaque ligne (`/promotions/saisie/apercu`). Il l'affiche sous la ligne avec un bouton **Retirer** et tient compte de la remise dans le total. Le champ « Code promo » du récapitulatif débloque les promotions à code.
- **À l'enregistrement, le serveur recalcule tout** (`PromotionsSaisie`). Le navigateur n'envoie que le code tapé et, pour chaque ligne, le choix de retirer la promotion. La remise de la promotion est gardée à part (`remisePromotion`, `montantPromotion`) avec le lien vers la promotion et son libellé du jour. La ligne les garde après la fin de la promotion, pour l'historique et le suivi.
- **Une seule promotion par ligne, sans cumul.** On retient la plus avantageuse pour le client. Elle ne donne que ce qui dépasse l'avantage déjà sur la ligne (remise à la main, promo de réveil). La promo de réveil (`docs/fidelite.md`) laisse la ligne qui porte déjà une promotion.
- **La promotion se juge au jour du document** (date du devis ou de la commande), pas au jour de la modification.
- Une commande facturée ne bouge plus. Un devis converti en commande transmet ses promotions et ses exceptions à la commande.
- **Clients au prix B2B** (grille revendeur, `Clients::typeClient = 'B2B'`, et non le type de compte) : aucune promotion. Une entreprise au prix public est un client comme les autres. Le formulaire signale qu'une promotion serait possible. Après l'enregistrement, l'agent clique **Demander l'exception** sur la ligne, depuis la fiche du devis ou de la commande. La ligne reste au prix B2B. Les détenteurs du droit Promotions sont prévenus (notification et pastille). Le responsable accepte ou refuse avec un motif, et l'agent est prévenu. Une fois l'exception acceptée, la promotion est calculée **à partir du prix public** et remplace le prix B2B, sans cumul. Demande et décision sont inscrites au journal d'activité.

### Suivi d'une promotion (fiche)

| Indicateur | Calcul | Alerte (réglable) |
|---|---|---|
| CA avec la promotion | Lignes **facturées** qui la portent : factures émises, hors proformas (`docs/indicateurs.md`) | — |
| Coût | Remises de la promotion, rapportées au CA facturé de toute l'entreprise sur la période (indicateur 2) | au-delà de 5 % |
| Clients touchés | Clients distincts des commandes qui la portent | — |
| Effet | Commandes HT des clients du segment pendant la promotion, comparées à la même durée juste avant, sur les mêmes clients | — |
| Marge restante | Marge brute des lignes facturées dont le coût de revient est connu (enregistré, sinon calculé) | sous 35 % |

### Anniversaires des clients

- **Date de départ** : création de la fiche, ou première commande si elle est plus ancienne. Les clients au prix B2B ne sont pas fêtés.
- **Modèles par palier** (1 an, 2 ans…) : articles, avantage, validité en jours, texte du SMS. Le palier le plus haut atteint s'applique. Le texte ne doit contenir aucun montant : sinon rien ne part.
- **Anniversaires à venir** : les 21 prochains jours par défaut (réglable dans Paramètres, et modifiable dans la page). Pour chaque anniversaire : le nombre d'années, le CA sur 12 mois (avec le droit « ventes ») et la promotion prévue. L'admin peut changer le modèle, exclure, valider, ou **tout valider** d'un clic. Par défaut, il faut valider avant le jour J (réglage « Valider les anniversaires avant le jour J »).
- **Jour J** (`app:promotions`) : création de la promotion du client seul (segment « liste », origine anniversaire), puis SMS par Orange. Quand les SMS ne sont pas branchés, un lien WhatsApp est préparé sur la page, comme pour les rappels. L'envoi est tracé dans la campagne du mois « Anniversaires clients ». Aucun message ne part vers une fiche de démonstration (`DEMO-`), ni vers un client qui refuse les messages (réglage du consentement). Les jours manqués sont rattrapés jusqu'à 7 jours.
- **Rappel** 7 jours (réglable, 0 = pas de rappel) avant la fin, si la promotion n'a pas servi.
- **Idempotent** : relancée le même jour, la tâche ne refait rien (un anniversaire par client et par nombre d'années, marqué traité).

### Tâche planifiée

Chaque matin, sur l'ERP de la boutique uniquement :

- Linux ou Mac : `15 8 * * * cd /chemin/du/projet && php bin/console app:promotions -q >> var/log/promotions.log 2>&1`.
- Windows : une tâche planifiée quotidienne à 8 h 15 qui lance `php bin/console app:promotions -q`, par exemple `schtasks /Create /SC DAILY /ST 08:15 /TN "SuccesImprim promotions" /TR "cmd /c cd /d C:\chemin\du\projet && php bin\console app:promotions -q >> var\log\promotions.log 2>&1"`.
- `--jour=AAAA-MM-JJ` rejoue un autre jour (essai, rattrapage). `--demo` charge les données de démonstration (refusé en production, voir `docs/donnees-demo.md`).

### Espace client

La page **Mes promotions** (lien dans Compte, sauf pour un client au prix B2B) montre au client ses promotions en cours, et elles seules : celles de son segment, de sa liste ou de son anniversaire. Elle donne le nom, les articles, la période, l'avantage et les conditions. Les promotions descendent par la synchronisation (type `promotion`, `docs/synchronisation.md`). Le code, la note interne, les auteurs et les exceptions restent au local. Une promotion à code descend éteinte : le code se donne au comptoir. Après la mise à jour, lancer une fois `php bin/console app:synchro --initialiser=promotion` sur le local pour envoyer les promotions déjà créées.

### Reste à faire

- **Commande passée en ligne** : la promotion n'est pas posée à l'envoi par le client. Elle s'applique quand l'atelier ouvre et enregistre la commande pour en confirmer le prix.
- **Nombre d'utilisations par client** : il se compte sur les commandes du local. Une commande passée en ligne et pas encore enregistrée à la boutique ne compte pas encore.
- **Annonce par campagne** : pas de bouton « annoncer cette promotion » ; la campagne WhatsApp ou SMS se prépare à la main sur le même segment.
- Propositions pas encore faites : le message plus personnel pour les gros clients (appel de la direction, cadeau à la livraison). L'anniversaire de la première commande n'a pas de fête à part : la date de départ la prend déjà quand elle est plus ancienne que la fiche.
- Clients dormeurs : rien de nouveau, la promo de réveil existante (`docs/fidelite.md`) fait ce travail ; la promotion par segment « inactifs depuis 90 jours » le complète sans le dupliquer.

---

Le périmètre d'origine suit.

## Ce qui existait déjà

- Prix B2B par produit (`Produits::prixB2B`, remise dérivée `getRemiseB2B()`), appliqué aux clients revendeurs au prix B2B (`Clients::typeClient = 'B2B'`), qu'ils soient entreprise ou non.
- Remise manuelle en francs par ligne de commande (`CommandesDetails::remise`).
- Campagnes WhatsApp et SMS (`Campagne`), sans lien avec un prix.

Il manque une promotion qui s'applique toute seule, sur une période, à certains articles et à certains clients.

## Une promotion

| Champ | Exemple |
|---|---|
| Nom | « Rentrée : −15 % sur les roll-up » |
| Articles visés | Un ou plusieurs produits, ou une catégorie entière |
| Avantage | Pourcentage, montant en francs par unité, ou prix fixe |
| Période | Du 01/11 au 15/11 |
| Segment | Voir ci-dessous ; « tous les clients » possible |
| Conditions | Quantité ou montant minimum, code promo facultatif, nombre d'utilisations par client |

## Segments de clients

Calculés à partir des données existantes, sans saisie manuelle :

- type de compte : particulier ou entreprise ;
- nouveaux clients (aucune commande avant) ;
- clients actifs (achat sur 12 mois) et inactifs (rien depuis 90 jours) ;
- palier de fidélité (maquette 18) ;
- gros clients (CA sur 12 mois au-dessus d'un montant) ;
- liste choisie à la main, pour un cas particulier.

## Règles d'application

- À la saisie d'un devis ou d'une commande (maquette C01), la promotion valable pour ce client et cet article s'applique toute seule et s'affiche sur la ligne. L'agent peut la retirer.
- Une seule promotion par ligne. Les clients au prix B2B sont exclus des promotions (décision de Yaya, 10/10/2026).
- Exception sur demande : l'agent peut demander d'appliquer la promotion à un client B2B sur une ligne. La ligne reste au prix B2B tant qu'un responsable n'a pas accepté, et la demande est gardée au journal d'activité.
- La ligne garde la promotion appliquée, pour l'historique et les rapports, même après la fin de la promotion.
- Une promotion peut être annoncée par une campagne WhatsApp ou SMS au même segment, et affichée dans l'espace client (J5).

## Suivi

| Indicateur | Calcul | Alerte |
|---|---|---|
| CA réalisé avec la promotion | Lignes facturées portant la promotion | — |
| Coût de la promotion | Somme des remises accordées | Remises totales > 5 % du CA (indicateur 2) |
| Clients touchés | Clients distincts ayant profité de la promotion | — |
| Effet | CA du segment pendant la promotion comparé à la même période avant | — |
| Marge restante | Marge brute des lignes en promotion, avec le coût de revient calculé | Marge < 35 % |

## Anniversaire du client

Demande de Yaya le 10/10/2026 : fêter chaque année de fidélité (1 an, 2 ans, 3 ans…) avec une promotion préparée à l'avance et un SMS le jour J.

- **Date de départ** : la date de création du client (`Clients::createdAt`), ou la date de sa première commande si elle est plus ancienne (clients repris de l'ancien système).
- **Modèles par palier** : une promotion modèle par nombre d'années (ex. 1 an : −10 % sur les cartes de visite ; 2 ans : −15 % sur les bâches ; 5 ans : une finition offerte), avec les articles choisis, la durée de validité (ex. 30 jours à partir du jour J) et le texte du SMS.
- **Page « Anniversaires à venir »** (Clients) : la liste des clients qui fêtent un anniversaire dans les 2 ou 3 semaines (délai réglable), avec leur nombre d'années, le CA sur 12 mois et la promotion prévue. L'admin peut changer la promotion d'un client, l'exclure, ou tout valider d'un clic.
- **Jour J** : une tâche planifiée applique la promotion au client et envoie le SMS par Orange (service `OrangeSmsService` déjà présent), ou WhatsApp si le client le préfère. L'envoi est tracé dans la campagne.
- **À la saisie** : la promotion d'anniversaire s'applique toute seule sur les articles choisis, comme les autres promotions, jusqu'à sa date de fin. Les clients B2B restent exclus sauf exception validée.
- **Suivi** : SMS envoyés, promotions utilisées, CA généré pendant la période d'anniversaire.

Propositions en plus :

- un rappel automatique 7 jours avant la fin de validité si la promotion n'a pas été utilisée ;
- un message plus personnel pour les gros clients (appel de la direction ou petit cadeau à la livraison) au lieu du seul SMS ;
- pour les entreprises, fêter aussi l'anniversaire de leur première commande, qui parle souvent plus que la date de création de la fiche.

## Clients dormeurs

Demande de Yaya le 10/10/2026 : une promotion programmée pour les clients qui n'ont rien imprimé depuis plusieurs mois.

- **Délai réglable** : 3, 6 ou 12 mois sans commande (90 jours par défaut, comme l'indicateur « clients inactifs »).
- **Liste** : les clients qui viennent de passer le délai, avec leur dernière commande, ce qu'ils commandaient et leur CA passé. L'admin peut en exclure.
- **Promotion de relance** préparée sur les articles choisis, avec une durée de validité, envoyée par SMS ou WhatsApp et appliquée toute seule à la prochaine commande.
- **Suivi** : clients revenus, délai de retour, CA rapporté.
