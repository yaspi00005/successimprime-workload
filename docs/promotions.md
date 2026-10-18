# Promotions par segment de clients

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

## En place (14/10/2026)

Tout se gère sur l'ERP de l'imprimerie. Le site en ligne reçoit les promotions pour l'espace client, mais ne les modifie pas.

### Écrans et droits

| Écran | Où | Qui |
|---|---|---|
| Liste des promotions, par état (en cours, à venir, terminées, suspendues) | Ventes › Promotions (`/promotions`) | Droit **Promotions**, ou qui saisit devis ou commandes (lecture) |
| Fiche et suivi d'une promotion | `/promotions/{id}` | Idem. Le journal des modifications : droit Promotions seulement |
| Créer, modifier, suspendre, supprimer | Boutons de la liste et de la fiche | Droit Promotions |
| Exceptions B2B à décider | Ventes › Promotions, bouton « Exceptions B2B » (`/promotions/exceptions`), avec une pastille au menu | Droit Promotions |
| Codes promo d'une promotion : code partagé, lot de codes uniques, export CSV, feuille à imprimer, désactiver | Fiche de la promotion, bouton « Codes promo » (`/promotions/{id}/codes`) | Droit Promotions (voir « Codes promo » plus bas) |
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
- **Conditions** : quantité minimale de la ligne, montant minimal de la ligne avant remise, code simple facultatif, nombre de commandes par client.
- **Codes promo générés et avantage sur la commande** (facultatifs) : voir « Codes promo » plus bas.
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

Chaque matin, sur l'ERP de l'imprimerie uniquement :

- Linux ou Mac : `15 8 * * * cd /chemin/du/projet && php bin/console app:promotions -q >> var/log/promotions.log 2>&1`.
- Windows : une tâche planifiée quotidienne à 8 h 15 qui lance `php bin/console app:promotions -q`, par exemple `schtasks /Create /SC DAILY /ST 08:15 /TN "SuccesImprim promotions" /TR "cmd /c cd /d C:\chemin\du\projet && php bin\console app:promotions -q >> var\log\promotions.log 2>&1"`.
- `--jour=AAAA-MM-JJ` rejoue un autre jour (essai, rattrapage). `--demo` charge les données de démonstration (refusé en production, voir `docs/donnees-demo.md`).

### Espace client

La page **Mes promotions** (lien dans Compte, sauf pour un client au prix B2B) montre au client ses promotions en cours, et elles seules : celles de son segment, de sa liste ou de son anniversaire. Elle donne le nom, les articles, la période, l'avantage et les conditions. Les promotions descendent par la synchronisation (type `promotion`, `docs/synchronisation.md`). Le code, la note interne, les auteurs et les exceptions restent au local. Une promotion à code simple descend éteinte : le code se donne au comptoir. Une promotion réservée aux codes générés descend active (le site vérifie le code tapé), mais ne paraît pas dans « Mes promotions ». Après la mise à jour, lancer une fois `php bin/console app:synchro --initialiser=promotion` sur le local pour envoyer les promotions déjà créées.

L'**Atelier 3D** (`docs/atelier-3d.md`) montre la promotion qui s'appliquerait à l'article (« Promotion … : −29 250 F, déduite par l'atelier en confirmant la commande »), sans la déduire du total affiché : comme toute commande en ligne, elle est appliquée à la confirmation. Un visiteur sans compte et un demandeur d'entreprise ne la voient pas.

### Codes promo (15/10/2026)

Demande de Yaya : « faut prevoir des code promo. qui peut etre apliqué sur commande , le code doit généré avec une limitation et expiration ».

**Créer les codes** (fiche de la promotion › **Codes promo**, droit Promotions, ERP de l'imprimerie seulement) :

- **Un code partagé** : le même pour tous (affiche, SMS, réseaux), tapé par l'équipe (4 à 20 lettres ou chiffres, par exemple `RENTREE25`) ou tiré au hasard. Limité à N utilisations en tout et à M par client (1 par défaut).
- **Un lot de codes uniques** : de 1 à 500 codes d'un coup, chacun pour une seule utilisation. Forme `SI-7K4P-2Q` : 6 caractères tirés avec `random_int` dans un alphabet de 31 signes sans 0, O, 1, I ni L. Chaque code est unique dans la base (index unique, et vérifié contre les codes simples des promotions).
- **Dates** : chaque code a une **expiration** (par défaut le dernier jour de la promotion, jamais après) et un premier jour facultatif (jamais avant la promotion). Le jour d'expiration compte encore. Un code se juge à la date du document, comme la promotion.
- Un lot s'**exporte en CSV** (une seule colonne `code`) et s'**imprime** sur une feuille A4 de coupons à découper pour le comptoir (codes actifs seulement). Un code se **désactive** (et se réactive) : il ne sert plus, les devis et commandes qui le portent le gardent.
- La liste montre, pour chaque code, ses utilisations sur la limite, son état (valable, à venir, épuisé, expiré, désactivé) et les devis et commandes qui le portent, avec le client ; le montant retiré seulement avec le droit « ventes ».
- **Journal d'activité** de la promotion : code partagé créé, lot généré (lot, nombre, dates : jamais les codes d'un lot en clair), code désactivé ou réactivé (ce code-là est nommé, même d'un lot), avec l'auteur.

**La promotion d'un code** : dans le formulaire, la case « Réservée aux codes promo générés » (cochée d'office dès qu'un code existe ou qu'il y a un avantage sur la commande). Elle ne s'applique alors plus toute seule : seulement quand un de ses codes, vérifié, est tapé. Elle ne paraît pas dans « Mes promotions ». Le code simple (ancien champ, sans limite ni expiration propres) et les codes générés ne vont pas ensemble.

**Avantage sur la commande** (section du formulaire) : un pourcentage de la commande (90 % au plus, avec un plafond en F facultatif) ou un montant en F, hors TVA, avec un montant minimal de commande facultatif (hors TVA, après les remises des lignes). Avec un avantage sur la commande, les articles visés sont facultatifs ; avec des articles, la promotion du code les remise aussi, comme une promotion de ligne.

**Comment l'avantage est porté (choix)** : il est **réparti sur les lignes** (`remiseCodePromo` de chaque ligne, hors TVA), au prorata du total de chaque ligne après ses remises, au franc près (plus forts restes : la somme des parts fait exactement l'avantage). La ligne le déduit de son total HT, puis calcule sa TVA. Ainsi :

- le total HT, la TVA (par ligne, avec ou sans TVA) et le TTC restent justes ; les totaux du document sont la somme des lignes ; aucun prix unitaire n'est gonflé ni changé ;
- le devis et la facture montrent « Total HT avant code promo », « Code promo SI-7K4P-2Q − N F », puis le « Total HT » ; la colonne « Remise » de chaque ligne compte sa part ; la fiche de la commande montre le code et ce qu'il a retiré (déjà déduit) ;
- le journal des ventes reprend le HT de la facture, déjà net ; le CA et les statistiques par article sont nets ;
- le **coût de la promotion** (fiche et liste) compte les parts de ses codes ; le taux de remise des agents (Mes statistiques) ne les compte pas : ce n'est pas une remise accordée par l'agent.

La « Remise » globale du document (`Commandes::remise`) n'a pas été reprise pour porter l'avantage du code : elle se saisit à la main. Depuis le 16/10/2026, elle vient après le code promo et les frais de livraison, se retire du HT avant la TVA et fait sa propre ligne « Remise globale » (docs/factures-entreprises.md, « Remise globale »).

**Pas de double remise** : un seul code par document. Une ligne n'a qu'un avantage promotionnel : sa promotion de ligne, ou sa part du code. Les lignes qui ont déjà une promotion (automatique ou celle du code) ne reçoivent pas de part de l'avantage sur la commande. Une remise saisie à la main reste, et le code s'applique après elle. Une ligne ne garde la promotion d'un code que tant que le code est retenu sur le document : retirer le code du champ retire aussi cette promotion des lignes (sinon l'utilisation serait rendue et la remise gardée).

**Utilisations** : un devis en cours (brouillon, envoyé ou accepté, pas encore transformé) ou une commande (ni supprimée, ni annulée en entier) qui porte le code. Un devis réserve donc son utilisation ; transformé, il la passe à sa commande. Une commande supprimée ou annulée, un devis refusé ou expiré, la rendent. Le compte exclut toujours le document en cours : l'enregistrer de nouveau ne compte pas deux fois.

**Vérification au serveur**, à chaque enregistrement d'un devis ou d'une commande (`CodesPromo::verifier`, `CalculCodePromo::refus`) : le code existe ; il est actif et sa promotion n'est pas suspendue ; la date du document est entre son premier jour et son expiration ; la limite en tout et la limite par client ne sont pas atteintes ; le client n'a pas déjà profité de la promotion autant de fois que ses « Commandes par client » (toutes ses lignes et tous ses codes ensemble : deux coupons d'un même lot ne servent pas au même client si la promotion dit « 1 commande par client ») ; le client n'est pas au prix B2B ; il est dans le segment de la promotion ; le minimum de commande est atteint ; le code apporte quelque chose. Refusé, le formulaire revient avec la raison et rien n'est enregistré. Pendant la saisie, la raison s'affiche aussi sous le champ. Deux caisses qui prennent la dernière utilisation au même instant : un verrou MariaDB par code (`GET_LOCK`, `VerrousCodesPromo`) fait passer l'une après l'autre. Un code déjà retenu sur le document reste, même désactivé depuis.

**Messages** : l'équipe lit la raison (sauf pour un code inconnu). Le client lit « Ce code n'est pas valable pour cette commande. », et la raison seulement quand elle tient à sa propre commande : code expiré, déjà utilisé par lui (ce code, ou cette offre autant de fois que permis), minimum non atteint, aucun article qui en profite. Un revendeur au prix B2B lit « Les prix revendeur ne se cumulent pas avec un code promo. » pour **tout** code tapé, avant même que le site le cherche : la réponse ne dit pas si le code existe. Jamais si le code existe ni combien il en reste. Aucun montant.

**Espace client** (Nouvelle commande) : un champ « Code promo (facultatif) », absent pour un revendeur au prix B2B (refusé aussi au serveur).

- Le site vérifie avec ce que la synchronisation lui donne. Il ne garde **pas le code**, seulement son **empreinte** : HMAC-SHA256 du code avec la clé de synchronisation `SYNC_CLE` (la même des deux côtés, jamais en base). Une copie de la base du site ne permet donc pas de retrouver les codes, même ceux d'un lot de 6 caractères. Il a aussi les dates, les limites et les utilisations comptées au local (`utilisations`), auxquelles il ajoute les commandes en ligne pas encore confirmées qui ont tapé le même code. Sans clé de synchronisation, le local ne calcule pas d'empreinte et le code ne part pas au site (une empreinte sans clé se retrouverait trop facilement) ; la tâche du matin la pose une fois la clé réglée.
- Refusé : la commande n'est pas envoyée et le client lit le message. **Les codes refusés sont comptés** (`EssaisCodePromo`) : au plus 8 par heure par compte et 30 par heure par connexion (`code_promo_client`, `code_promo_ip`), puis plus d'essai jusqu'à la fin de la fenêtre.
- Valable : la commande part « à confirmer » avec le code tapé (`codeSaisi`) et l'avantage indicatif (promotion des articles visés, part de l'avantage sur la commande). Elle ne retient pas le code.
- **À la confirmation** (l'atelier ouvre et enregistre la commande), l'imprimerie revérifie : **le local fait foi**. Code toujours valable : il est retenu et l'avantage recalculé. Plus valable (la limite a été atteinte entre-temps, par exemple) : la commande s'enregistre quand même, sans l'avantage, et la fiche dit pourquoi (bandeau « Code promo … non retenu », `motifCodePromo`, qui reste au local).
- Le client voit l'avantage dans sa commande s'il voit les montants (`CompteClient::montantsVisibles` : compte particulier, payeur ou valideur d'une entreprise). Un demandeur lit « à confirmer » ou « appliqué », sans montant. Un code non retenu s'affiche « non retenu », sans la raison interne. Aucune notification ni aucun SMS ne part avec un montant.

**Synchronisation** : type `code_promo` (local → site) : empreinte, type, dates, limites, utilisations, actif, création, promotion. Restent au local : le code en clair, le lot, qui l'a créé ou désactivé. Sur les devis et commandes : `codeSaisi`, `remiseCodePromo` et le lien vers le code retenu (une commande créée en ligne arrive toujours sans code retenu, `imposeAuLocal` : seule l'imprimerie le retient) ; sur les lignes, `remiseCodePromo` ; sur la promotion, l'avantage sur la commande et la marque « codes générés ». Les promotions et les codes partent avant les commandes et les devis. Après la mise à jour du local : `php bin/console app:synchro --initialiser=promotion` puis `--initialiser=code_promo`. Après un changement de `SYNC_CLE`, lancer `php bin/console app:promotions` (ou attendre le lendemain matin) : les empreintes sont refaites et repartent au site ; d'ici là, les codes tapés en ligne sont refusés.

**Tâche du matin** (`app:promotions`) : recompte les utilisations des codes encore valables (une commande supprimée ou annulée rend la sienne) pour la liste et pour le site, et refait l'empreinte si la clé de synchronisation a changé.

### Reste à faire

- **Codes promo** : pas d'exception B2B pour l'avantage sur la commande (un revendeur au prix B2B est toujours refusé) ; une commande restaurée après suppression (ou un devis rouvert après refus) reprend son code sans nouvelle vérification de la limite ; en ligne, la limite « Commandes par client » de la promotion ne compte que les commandes confirmées avec un de ses codes (l'imprimerie revérifie tout à la confirmation) ; la commande de l'Atelier 3D n'a pas de champ code promo ; en ligne, l'avantage se voit après l'envoi (pas d'aperçu pendant la saisie).
- **Commande passée en ligne** : la promotion n'est pas posée à l'envoi par le client. Elle s'applique quand l'atelier ouvre et enregistre la commande pour en confirmer le prix.
- **Nombre d'utilisations par client** : il se compte sur les commandes du local. Une commande passée en ligne et pas encore enregistrée à l'imprimerie ne compte pas encore.
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
