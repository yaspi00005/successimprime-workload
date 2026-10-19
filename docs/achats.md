# Achats : fournisseurs et commandes d'achat

Yaya a envoyé les maquettes le 19/10/2026. Elles sont dans `/mnt/project-files/maquettes/achats-su-1910/` :
- SU01 Fournisseurs ;
- SU02 Fiche fournisseur ;
- SU03 Commande d'achat ;
- SU04 Coûts, marges et bénéfices ;
- SU05 Réception ;
- SU06 Fréquence des commandes et alertes ;
- SU07 Alertes sur téléphone.

Cette première étape construit SU01, SU02 et SU03. Ce sont des écrans neufs : on garde les données (`Fournisseurs`, `Achats`, `AchatDetail`, `ArticleFournisseur`, les entrées et sorties de stock) et les calculs qui marchent, pas l'ancienne présentation.

Les écrans sont dans le menu Atelier, juste après « Articles et stock » : « Fournisseurs » et « Commandes d'achat ». Comme avant, ils sont réservés à la direction (`ROLE_ADMIN`, règle `access_control` sur `/fournisseurs` et `/achats`). Le montant dû aux fournisseurs ne s'affiche qu'avec le droit « Trésorerie et soldes » (`ROLE_TRESORERIE_VOIR`), que la direction a.

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

### Réception (en attendant SU05)

Le bouton « Réceptionner » d'une commande passée garde l'ancien fonctionnement : toute la quantité commandée entre en stock, ligne par ligne, et le coût moyen pondéré de l'article est recalculé. Le prix d'entrée est désormais le coût de revient de la ligne (transport et transit compris), plus seulement le prix d'achat. Le dernier prix payé devient le prix du fournisseur sur la fiche article.

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

## Démonstration

`php bin/console app:demo` complète les fournisseurs des maquettes (`AchatsDemo`) : Sahel Supplies (bâches, Bamako, 2 jours), Dakar Signs (vinyles et micro-perforé, camion depuis Dakar, promis en 7 jours, 8 en moyenne), Encres Pro Bamako (encres solvant, 1 jour), Yinghe Distribution (film, poudre et encres DTF, fret aérien depuis la Chine, 18 jours promis) et Kanté Bois & Alu (structures de roll-up). Un an de commandes avec des retards et un rouleau abîmé, une commande en route depuis la Chine, deux factures fournisseurs à payer, et une consommation qui met le micro-perforé à commander avant quelques jours, avec le vinyle à commander dans les 14 jours. Tout part avec la purge, la commande peut être relancée.

## Et ensuite

- **SU05 Réception** : commandé, reçu, bon état et facturé ligne par ligne ; casse avec photo et réclamation au fournisseur ; frais réels qui remplacent les frais prévus ; coût de revient mis à jour ; contrôle à trois points (bon de commande, bon de livraison, facture) qui bloque le solde tant que l'avoir n'est pas reçu. Les colonnes `quantite_recue` et `quantite_abimee` sont déjà là.
- **SU04 Coûts, marges et bénéfices** : coût de revient complet par produit (achat, transport, encre, gâche, machine), prix de vente moyen, marge et bénéfice du mois, en partant du coût rendu enregistré sur chaque ligne d'achat.
- **SU06 Fréquence des commandes et alertes** : écran de réapprovisionnement avec la courbe de stock et la prévision, et des règles d'alerte réglables (stock bas, hausse de prix, fournisseur en retard, commande oubliée). Les calculs de jours restants et de date de commande sont prêts dans `CalculsAchat`.
- **SU07** : ces alertes sur téléphone.
- L'acompte n'est pas encore une dépense à payer dans les Finances : il s'affiche sur la commande.
