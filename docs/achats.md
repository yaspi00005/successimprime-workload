# Achats : fournisseurs et commandes d'achat

Yaya a envoyé les maquettes le 19/10/2026. Elles sont dans `/mnt/project-files/maquettes/achats-su-1910/` :
- SU01 Fournisseurs ;
- SU02 Fiche fournisseur ;
- SU03 Commande d'achat ;
- SU04 Coûts, marges et bénéfices ;
- SU05 Réception ;
- SU06 Fréquence des commandes et alertes ;
- SU07 Alertes sur téléphone.

La première étape a construit SU01, SU02 et SU03 ; la deuxième, SU05 (réception). Ce sont des écrans neufs : on garde les données (`Fournisseurs`, `Achats`, `AchatDetail`, `ArticleFournisseur`, les entrées et sorties de stock) et les calculs qui marchent, pas l'ancienne présentation.

Les écrans sont dans le menu Atelier, juste après « Articles et stock » : « Fournisseurs », « Commandes d'achat » et « Réceptions ». Comme avant, ils sont réservés à la direction (`ROLE_ADMIN`, règle `access_control` sur `/fournisseurs` et `/achats`). Le montant dû aux fournisseurs ne s'affiche qu'avec le droit « Trésorerie et soldes » (`ROLE_TRESORERIE_VOIR`), que la direction a.

## SU01 Fournisseurs

Quatre indicateurs en haut :
- **Achats du mois** : les commandes passées ce mois-ci, au total rendu Bamako (marchandise, transport et transit), et leur nombre ;
- **Dû aux fournisseurs** : les factures fournisseurs à payer, avec la prochaine échéance. C'est le même calcul que l'écran Finances › Fournisseurs (`Echeancier::dettes`) ;
- **En route** : les commandes passées et pas encore reçues, avec la plus proche arrivée prévue ;
- **À commander bientôt** : le nombre d'articles en alerte (règle plus bas). La carte ouvre le bon de commande du stock.

Le tableau donne, pour chaque fournisseur :
- son nom, avec une pastille à ses initiales dans sa couleur (choisie sur la fiche, sinon tirée du nom) ;
- ce qu'il fournit et son origine (« Bamako · local », « Dakar, Sénégal ») ;
- le délai moyen réel, mesuré de la commande à l'arrivée sur 12 mois ;
- la part de livraisons à l'heure sur 12 mois, avec une barre verte (90 % et plus), jaune (80 à 89 %) ou rouge (moins de 80 %) ;
- ses achats de l'année ;
- ce qui lui est dû ;
- sa prochaine commande : son article le plus pressé, « sous le minimum » ou « maintenant » en rouge, « avant le 24/10 » en orange dans la semaine, « le 06/11 » en vert plus tard. Un clic ouvre une commande d'achat préparée pour cet article.

Le bouton « Exporter » donne le même tableau en CSV pour Excel. « + Fournisseur » ouvre la fiche à remplir.

## SU02 Fiche fournisseur

- **Identité et conditions** : contact et son numéro, conditions de paiement en clair (« virement, 50 % à la commande »), acompte en %, transport, délai promis, délai réel moyen (en rouge s'il dépasse le promis), et ce qui lui est dû.
- **Performance sur 12 mois** : % à l'heure, retard moyen, % de marchandise abîmée, nombre de commandes.
- **Conseil** : quand le délai réel moyen (arrondi au jour supérieur) dépasse le délai promis, un encadré dit de compter le délai réel dans les alertes. La prochaine commande calculée le fait déjà.
- **Produits achetés et évolution du prix** : pour chaque article lié au fournisseur ou acheté chez lui, le dernier prix par unité d'achat, une petite courbe des prix sur 12 mois (moyenne de chaque mois ; un mois sans achat reprend le prix précédent), la variation entre le premier et le dernier prix de la période, et le coût rendu Bamako par unité de stock (F/m², F/L…) de la dernière commande.
- **Commandes** : numéro, date, montant rendu, date promise, date de réception, et un badge d'écart : « en route · prévu 28/10 », « à l'heure », « +2 j », « +5 j · 1 rouleau abîmé ».
- Boutons : WhatsApp (lien wa.me vers le numéro du contact, indicatif 223 ajouté à un numéro malien à 8 chiffres), « + Commande d'achat », « Désactiver », « Modifier la fiche ».

## SU03 Commande d'achat

### Saisie

- Chaque ligne : l'article (ceux du fournisseur d'abord), la quantité en unité d'achat (rouleaux), la quantité en unité de stock (m²), le prix d'achat d'une unité d'achat (repris de la fiche article pour ce fournisseur), le total, et une suggestion : « 4 rouleaux ≈ 21 semaines » au rythme de consommation des 12 dernières semaines.
- **Frais pour amener la marchandise à Bamako** : une ligne transport et une ligne transit, chacune avec son libellé, sa base (« groupage, 2 colis », « transitaire Kayes ») et son montant. Pour une nouvelle commande, libellés, bases et montants reprennent la dernière commande du fournisseur, le montant ramené aux quantités de la nouvelle.
- Tout se recalcule à la saisie dans le navigateur, et de nouveau sur le serveur à l'enregistrement.

### Colonne de droite

- **Total rendu Bamako** : marchandise, transport, transit, et l'acompte à verser à la commande (le % du fournisseur appliqué à la marchandise).
- **Coût de revient au m²** de chaque article : prix d'achat ramené au m², plus sa part de transport et de transit.
- **Livraison prévue** : la date promise (commande + délai promis du fournisseur), plus son retard habituel (retard moyen arrondi au jour supérieur). Avant l'envoi, la date est calculée comme si la commande partait aujourd'hui.
- **Validation** : qui doit valider, selon le seuil.

### Répartition des frais

Les frais sont répartis sur chaque m² acheté : par exemple 114 700 F de transport pour 441 m² font 260 F/m². Quand les lignes n'ont pas la même unité de stock (des mètres de film et des kilos de poudre), les frais sont répartis au prorata du montant de chaque ligne. Les parts sont arrondies au franc, et leur somme fait exactement les frais.

Le coût de revient d'une ligne vaut (montant de la ligne + sa part de transport + sa part de transit) ÷ sa quantité en unité de stock. Il est enregistré sur la ligne (`AchatDetail::coutRevientUnitaire`) avec la quantité en unité de stock (`quantiteStock`).

### Créée depuis une alerte

`/achats/new?article=ID` prépare la commande chez le fournisseur habituel de l'article, avec la quantité qui ramène le stock à son maximum (même calcul que le bon de commande du stock). Un bandeau le rappelle. Les autres articles du même fournisseur en alerte, ou à commander dans les 14 jours, sont ajoutés pour partager le transport. Le bandeau donne l'économie estimée : le transport moyen d'un envoi de ce fournisseur sur 12 mois, ce qu'aurait coûté un second envoi (à défaut d'historique, la part de transport des articles ajoutés).

On arrive à cet écran par la prochaine commande de la liste des fournisseurs, par le bouton « Commander » de la fiche article, et par le bouton « Commande d'achat » de chaque fournisseur sur le bon de commande du stock.

### Statuts et validation

| Statut | Valeur en base | Ce qui se passe |
|---|---|---|
| Brouillon | `brouillon` | Modifiable. « Brouillon » enregistre, « Envoyer » fait partir. |
| À valider | `a_valider` | Le total rendu dépasse le seuil : la direction valide (la commande part) ou la renvoie en brouillon. Une pastille dans le menu compte ces commandes. |
| Commandée | `commandee` | Partie chez le fournisseur : date de commande et date promise enregistrées. |
| En route | `en_route` | Le fournisseur a expédié. |
| Reçue | `recu` | Réceptionnée : le stock est mis à jour. |
| Annulée | `annule` | Gardée dans l'historique. |

Les valeurs `recu` et `annule` sont celles que lisent déjà les Finances et la comptabilité, elles ne changent pas. Les bons créés avant ces écrans ont le statut `demande` : il se lit « Commandée ».

Au-dessus du seuil (500 000 F par défaut, total rendu Bamako strictement supérieur), l'envoi met la commande « À valider » ; la direction (`ROLE_ADMIN`, constante `AchatsController::ROLE_VALIDATION`) la valide, avec son nom et l'heure. En dessous, elle est commandée tout de suite.

Les commandes sont numérotées CA-AAAA-NNNN dans l'année (CA-2026-0042), dès le premier enregistrement.

### Réception

Le bouton « Réceptionner » d'une commande passée ouvre l'écran de réception (SU05, plus bas).

## SU05 Réception

Menu Atelier › « Réceptions » (`/achats/receptions`) : d'abord les commandes à recevoir (en route d'abord, puis par date promise, avec le retard sur la date promise), puis les réceptions des 60 derniers jours avec leur écart (« à l'heure », « +2 j · 1 rouleau abîmé »), leurs réclamations et, avec le droit trésorerie, « paiement bloqué ». On y arrive aussi par « Réceptionner » sur la commande d'achat et dans la liste des commandes en cours.

### Commandé, livré, facturé

Pour chaque ligne : l'article, le commandé, et trois champs à remplir, préremplis avec le commandé :
- **Reçu** : ce qui est arrivé ;
- **Bon état** : ce qui est utilisable (jamais plus que le reçu). Quand il est sous le reçu, un petit champ demande la nature de la casse (« écrasé ») ;
- **Facturé** : ce que compte la facture du fournisseur.

Le badge d'écart dit « 1 rouleau écrasé » (rouge), « manque 1 » (rouge), « en trop 1 » (jaune) ou « conforme » (vert). Casse et manque se cumulent : « 1 rouleau écrasé · manque 1 ».

### Casse et réclamation au fournisseur

Une réclamation est créée pour chaque ligne où **la facture compte plus que le bon état** (abîmé ou manquant mais facturé) : « 1 rouleau à remplacer ou à déduire (159 600 F) », au prix d'achat de la ligne. Si le fournisseur a déjà retiré l'abîmé de sa facture, il n'y a rien à réclamer.

- **Photo** : « Ajouter une photo » (JPEG, PNG ou WebP, 10 Mo au plus, type lu dans le contenu). Elle est rangée hors du dossier public (`var/uploads/reclamations`, `FichiersPrives`) et servie par l'écran après contrôle des droits. Après la validation, le même bouton ajoute ou remplace la photo de la réclamation ouverte.
- **Message** : préparé pour le contact du fournisseur (« Bonjour M. Ndiaye, nous avons reçu la commande CA-2026-0041 le 28/10 : 1 rouleau de vinyle blanc écrasé. Merci de le remplacer ou de le déduire de votre facture FDS-26-1290 (159 600 F). La photo suit. »). « Envoyer sur WhatsApp » ouvre wa.me avec le message (pas d'API Meta) et note l'heure du premier envoi : l'écran dit alors « Message envoyé à M. Ndiaye avec la photo le 28/10 ». La photo se joint à la main dans WhatsApp.
- **Statuts** : ouverte, puis **remplacée** (la marchandise de remplacement entre en stock au prix d'achat de la ligne : le transport est déjà porté par les unités en bon état), **déduite** (l'avoir est reçu : le montant est retiré de la facture à payer) ou **close** (fermée sans suite).

### Frais réels et coût de revient

Transport et transit : le prévu de la commande, le réel saisi (vide : le prévu) et l'écart. **Les frais réels remplacent les frais prévus** sur la commande (`fraisTransport`, `fraisTransit`), donc dans le total rendu et les statistiques des fournisseurs ; les prévus sont gardés (`fraisTransportPrevu`, `fraisTransitPrevu`).

Le coût de revient est recalculé, et c'est lui qui entre en stock :
1. les frais réels se répartissent entre les lignes sur ce qui a été **livré** (m² reçus, abîmés compris : le camion les a portés), comme à la commande (au prorata du montant si les unités diffèrent) ;
2. chaque ligne porte sa part sur ses **seules unités en bon état**, avec leur prix d'achat : coût = (prix × bon état + part de transport + part de transit) ÷ m² en bon état.

Les unités abîmées ne portent donc aucun coût en stock : leur marchandise est remplacée ou déduite par le fournisseur, et leur part de transport, payée quand même, est portée par les unités utilisables de la même ligne. La valeur entrée en stock égale ce que l'imprimerie paie, et la casse du vinyle ne renchérit pas le micro-perforé.

Avec les chiffres de la maquette (1 rouleau de vinyle écrasé, transport 121 000 F au lieu de 114 700 F) : vinyle blanc 2 450 → 2 586 F/m², micro-perforé 3 650 → 3 664 F/m². La maquette affiche 2 464 F/m², ce qui revient à répartir les frais sur tous les m² reçus, abîmés compris : ce n'est pas la règle retenue, puisqu'un rouleau abîmé n'entre pas en stock. L'écran l'écrit ainsi : « Coût de revient mis à jour · vinyle blanc brillant : 2 450 → 2 586 F/m² ».

Le coût prévu reste sur la ligne (`coutRevientPrevu`), le réel remplace `coutRevientUnitaire`.

### Contrôle à 3 points

- **Bon de commande** : le numéro CA, toujours coché ;
- **Bon de livraison** : le numéro et la date du BL du fournisseur ;
- **Facture fournisseur** : le numéro, le montant (avec le droit « Trésorerie et soldes » seulement) et la date.

BL et facture se saisissent à la réception, ou après coup sur la même page (« Enregistrer BL et facture »).

Quand la facture compte plus que le bon état, l'encadré jaune le dit : « La facture compte 4 rouleaux de vinyle blanc alors qu'un est abîmé : paiement du solde bloqué jusqu'à l'avoir du fournisseur. »

### Paiement bloqué

- **La facture devient une dette** : dès que son montant est saisi, une dépense « à payer » (Finances › Dépenses, catégorie matières) est créée et reliée à la commande (`depense.achat_id`). Son montant est le **solde** : la facture moins l'acompte versé à la commande (% du fournisseur sur la marchandise) et moins les réclamations déduites. Elle compte dans « Dû aux fournisseurs » (SU01) et dans Finances › Fournisseurs (`Echeancier::dettes`). Une dette déjà payée ne bouge plus.
- **Règle** : le solde est bloqué tant qu'une réclamation de la commande est ouverte (`CalculsReception::paiementBloque`), avec la raison enregistrée sur la commande (`paiementBloque`, `paiementBloqueMotif`).
- **Dans les Finances** : la dépense montre « Paiement du solde bloqué » avec la raison, sans bouton de paiement, et le service refuse de la payer (`Depenses::payer`). Finances › Fournisseurs affiche « X F bloqués » sur la ligne du fournisseur.
- **Déblocage** : automatiquement quand la dernière réclamation ouverte est remplacée, déduite ou close ; ou forcé sur la réception par qui paie les fournisseurs (`ROLE_TRESORERIE_SAISIR`), avec une note obligatoire, gardée avec son auteur et son heure. Après un déblocage forcé, la commande ne se rebloque plus.

### Entrée en stock

Par article : « + 228 m² » et « 57,8 → 285,8 m² » (stock physique avant et après). Seules les unités **en bon état** entrent, converties en unité de stock par l'article (rouleaux × m² par rouleau, `Articles::convertirAchatEnStock`), au coût de revient réel ; le coût moyen pondéré de l'article est recalculé (`StockService::appliquerCoutMoyen`). Le dernier prix payé devient le prix du fournisseur sur la fiche article.

**Jamais deux fois** : la validation verrouille la commande en base (`SELECT … FOR UPDATE`) et refuse une commande déjà reçue ; chaque ligne note son entrée (`stockEntreLe`, `stockAvant`, `quantiteEntree`) et une ligne déjà entrée est sautée. La migration a marqué entrées les lignes des commandes reçues avant SU05.

### Livraison et performance

La date d'arrivée se saisit (aujourd'hui par défaut, jamais dans le futur). La ligne dit : « Livraison : 28/10, 2 jours après la date promise. Compté dans la performance de Dakar Signs. » Elle devient la date de réception de la commande, qui nourrit le délai réel, la ponctualité, le retard moyen et le % abîmé du fournisseur (SU01, SU02).

### Réception partielle

Une commande se réceptionne **une seule fois**. Si le reçu est sous le commandé, la réception se valide quand même : ce qui est arrivé entre en stock, le manque reste noté sur la ligne (« manque 1 », `quantiteRecue` < `quantite`) et la commande est **close** au statut « Reçue ». Si le manquant est facturé, il fait l'objet d'une réclamation comme la casse. Le reste, s'il faut encore l'attendre, se commande à nouveau. Une réception où rien n'est arrivé est refusée : la commande reste en route.

### Valider la réception

« Valider la réception » (avec confirmation) fait tout en une fois : statut « Reçue », date, réceptionnée par, quantités, frais réels, coût de revient, entrée en stock, réclamations, dette fournisseur et blocage. Avant la validation, l'écran recalcule tout à la saisie (`public/js/achats-reception.js`) ; le serveur refait les calculs.

### Droits

Comme les achats : la direction (`ROLE_ADMIN`, `access_control` sur `/achats`). Le montant de la facture, les montants des réclamations, le solde à payer et le paiement bloqué demandent en plus « Trésorerie et soldes » (`ROLE_TRESORERIE_VOIR`) ; forcer le paiement demande le droit de payer (`ROLE_TRESORERIE_SAISIR`).

### Calculs

`App\Service\Achats\CalculsReception` (statique, testé sans base : `tests/Unit/Service/Achats/CalculsReceptionTest.php`) : badge d'écart, quantité à réclamer, coût réel, règle du blocage et sa raison, solde, textes de la livraison et du message, lien wa.me. L'écran et la validation sont dans `App\Service\Achats\Receptions`, les routes dans `ReceptionsController`.

## Règles de réapprovisionnement

Pour chaque article actif qui a un fournisseur (le principal de la fiche article) :
- consommation par semaine : les sorties de stock des 12 dernières semaines ÷ 12 ;
- jours restants : le disponible (en stock moins réservé) ÷ la consommation par jour ;
- délai à compter : le délai réel moyen du fournisseur arrondi au jour supérieur, sinon le délai noté sur l'article pour ce fournisseur, sinon le délai promis, sinon 7 jours ;
- date de commande : aujourd'hui + jours restants − (délai + marge de sécurité), jamais avant aujourd'hui ;
- l'article est **à commander bientôt** quand son disponible est au minimum (ou à zéro), ou quand les jours restants ne couvrent plus le délai plus la marge.

Les calculs sont dans `App\Service\Achats\CalculsAchat`, statiques et testés sans base (`tests/Unit/Service/Achats/CalculsAchatTest.php`). Les chiffres viennent de `App\Service\Achats\SuiviAchats`.

## Réglages

Réglages › Paramètres, tuile « Achats et fournisseurs » :
- commande à faire valider au-delà de : 500 000 F ;
- marge de sécurité : 5 jours ;
- regrouper les articles à commander dans les : 14 jours.

## Données

Migration `Version20261020030000` :
- **fournisseurs** : contact (nom, téléphone avec indicatif), ville, pays, ce qu'il fournit, conditions de paiement, acompte %, transport, délai promis, couleur ;
- **achats** : date de commande, date promise, frais de transport et de transit (montant, libellé, base), acompte %, préparée par, validée par et le, article de l'alerte d'origine. Les anciens bons prennent leur date de demande comme date de commande ;
- **achat_detail** : quantité en unité de stock, coût de revient unitaire, quantité reçue (vide tant que la réception détaillée n'existe pas) et quantité abîmée.

Migration `Version20261020050000` (réception, SU05) :
- **achat_detail** : quantité facturée, nature de la casse, coût de revient prévu, stock avant, quantité entrée et heure de l'entrée en stock. Le bon état n'est pas une colonne : reçu − abîmé (`AchatDetail::getQuantiteBonEtat`) ;
- **achats** : numéro et date du BL, numéro, montant et date de la facture du fournisseur, frais prévus (les réels remplacent `frais_transport` et `frais_transit`), paiement bloqué et sa raison, déblocage forcé (note, par, le), réceptionnée par ;
- **reclamation_fournisseur** : commande, ligne, article, quantité, montant, photo, statut (ouverte, remplacée, déduite, close), message, envoyé le, créée par et le, réglée par et le ;
- **depense** : la commande d'achat dont la dépense est la facture (`achat_id`).

## Démonstration

`php bin/console app:demo` complète les fournisseurs des maquettes (`AchatsDemo`) : Sahel Supplies (bâches, Bamako, 2 jours), Dakar Signs (vinyles et micro-perforé, camion depuis Dakar, promis en 7 jours, 8 en moyenne), Encres Pro Bamako (encres solvant, 1 jour), Yinghe Distribution (film, poudre et encres DTF, fret aérien depuis la Chine, 18 jours promis) et Kanté Bois & Alu (structures de roll-up). Un an de commandes avec des retards et un rouleau abîmé, une commande en route depuis la Chine, deux factures fournisseurs à payer, et une consommation qui met le micro-perforé à commander avant quelques jours, avec le vinyle à commander dans les 14 jours. Pour la réception (SU05) : la commande **CA-AAAA-0041** de Dakar Signs (4 rouleaux de vinyle blanc, 2 de micro-perforé, transport et transit prévus 114 700 F et 39 700 F) est en route, promise il y a deux jours, prête à recevoir ; la dernière réception de Dakar Signs a un rouleau écrasé, une réclamation ouverte avec son message envoyé, sa facture à payer (solde après l'acompte de 50 %) et son paiement bloqué. Tout part avec la purge (les réclamations et les dettes reliées aux commandes fictives aussi, même saisies en essayant l'écran), la commande peut être relancée.

## Et ensuite

- **SU04 Coûts, marges et bénéfices** : coût de revient complet par produit (achat, transport, encre, gâche, machine), prix de vente moyen, marge et bénéfice du mois, en partant du coût rendu enregistré sur chaque ligne d'achat.
- **SU06 Fréquence des commandes et alertes** : écran de réapprovisionnement avec la courbe de stock et la prévision, et des règles d'alerte réglables (stock bas, hausse de prix, fournisseur en retard, commande oubliée). Les calculs de jours restants et de date de commande sont prêts dans `CalculsAchat`.
- **SU07** : ces alertes sur téléphone.
- L'acompte n'est pas encore une dépense à payer dans les Finances : il s'affiche sur la commande, et la réception le déduit de la facture pour faire le solde.
- Les frais réels de transport et de transit ne deviennent pas des dépenses à payer au transporteur : ils servent au coût de revient.
- Une commande partiellement reçue est close ; une seconde réception sur la même commande n'existe pas.
