# Stock, articles et options produit

Yaya a envoyé les maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/stock-parametres/` :
- S01 à S09 pour le stock et les articles ;
- R01 et R02 pour les options des produits et les règles d'affichage.

Comme pour l'admin, ce sont des écrans neufs. On garde les données et les calculs qui marchent, pas l'ancienne présentation.

## Ce qui existe déjà

| Besoin | Déjà dans l'application |
|---|---|
| Articles, stock, seuil minimum, prix d'achat, fournisseur | `Articles`, avec une catégorie en texte libre, une seule unité et un seul fournisseur |
| Nomenclature : ce qu'un produit consomme | `ProduitArticleStock` (coefficient et mode de calcul par produit) |
| Réservation quand la commande est confirmée | `StockService::reserverPourCommande`, `StockReservation` |
| Sortie quand l'impression est terminée | `StockService::consommerPourDetail`, `StockSorties` (avec la commande d'origine) |
| Entrées, achats fournisseurs | `StockEntrees`, `Achats`, `AchatDetail` |
| Étiquettes QR | `Etiquette`, `LotEtiquette`, utilisées aujourd'hui pour les commandes, pas pour les rouleaux |
| Options d'un produit | `ProduitConfiguration` : support, type d'impression, format et finitions. La structure est figée, sans règles. |

## Ce qu'il faut ajouter, maquette par maquette

| Maquette | À ajouter |
|---|---|
| **S01 Catalogue** | Familles (Supports, Encres, DTF, Textile, Finition, Papier), réglables. Valeur du stock au coût moyen pondéré. Articles sous le seuil. Articles dormants (sans sortie depuis 90 jours). Niveau et état : OK, Bientôt, Commander. Export Excel. |
| **S02 Fiche article** | Trois unités, achat, stock et vente, avec la conversion (1 rouleau de 50 m × 3,20 m = 160 m²). Seuils minimum et maximum. Réservé, et disponible réel. Emplacement. Machine compatible. Plusieurs fournisseurs par article, avec prix, délai et dernier achat. Coût moyen pondéré recalculé à chaque réception. Courbe de consommation et couverture en jours. |
| **S03 Nomenclature** | L'écran sur la nomenclature existante : quantités par unité vendue (matière, encre, structure, housse, main-d'œuvre, temps machine), coût de revient et marge, en lien avec `CalculateurCoutRevient`. |
| **S04 Mouvements** | Un seul journal : entrées, sorties de production, ajustements, transferts entre emplacements, retours. Chacun avec sa commande d'origine, son emplacement et son auteur. La sortie automatique compte les m² imprimés plus la chute réelle. |
| **S05 Réception** | La quantité reçue comparée à la quantité commandée, ligne par ligne. Chaque rouleau reçoit un numéro et une étiquette QR. Le coût moyen est recalculé. Une relance part pour ce qui manque. |
| **S06 Inventaire** | Stock théorique et quantité comptée, écart, valeur de l'écart et motif. À la validation, chaque écart devient un ajustement de stock et une écriture comptable. |
| **S07 Réapprovisionnement** | Quantité proposée selon la consommation moyenne, le délai du fournisseur et le seuil maximum. Les lignes sont regroupées par fournisseur, et les bons de commande se créent en un clic. |
| **S08 Analyse** | Valeur par famille, rotation, taux de chute, articles dormants. Consommation prévue par les nomenclatures comparée à la consommation réelle. |
| **S09 Mobile magasinier** | Scan du QR d'un rouleau, puis sortie, entrée ou comptage. Inventaire au téléphone. |
| **R01 Options produit** | Chaque produit a ses options : liste, cases à cocher, oui ou non, nombre, dimensions, texte, fichier, couleur. Chaque valeur a son effet sur le prix (forfait, par m², par mètre linéaire, par pièce) et son affichage (visible, masquée, admin seulement). Les options se rangent par glisser-déposer. Un aperçu en direct montre le formulaire vu du site, de l'espace client ou de l'admin, et se met à jour à chaque changement. |
| **R02 Règles « si… alors… »** | Conditions sur le produit, une option, la quantité, la largeur comparée à la laize, le client, le stock ou le fichier. Actions : afficher ou masquer une option ou une valeur, imposer des valeurs, alerte avec surcoût, remise, délai allongé, alerte à l'admin, blocage de la commande. Chaque règle dit où elle s'applique : site, espace client, admin, écran TV. Un bouton « Tester un scénario ». |

## Un seul moteur pour les options et les prix

Les options et les règles sont lues par un seul service côté serveur. Il donne :
- le formulaire à afficher ;
- le prix, le délai et les alertes.

Le calculateur du site, l'espace client, la saisie de commande admin (C01) et l'Atelier 3D l'appellent tous. La page ne calcule jamais un prix seule. Les grilles B2B et les promotions (`docs/promotions.md`) passent par ce même moteur.

La migration convertit les configurations actuelles (`ProduitConfiguration` et ses finitions) en options. Les prix ne changent pas.

## Ordre proposé

1. **S01 et S02** : familles, unités et conversion, seuils minimum et maximum, emplacement, plusieurs fournisseurs, coût moyen pondéré. C'est la base de tout le reste.
2. **S04 et S03** : le journal des mouvements, la sortie automatique avec la chute, l'écran de nomenclature et le coût de revient.
3. **R01 et R02** : options et règles, avec le moteur unique. Il faut l'avoir avant la saisie de commande C01 et le calculateur du site.
4. **S05 et S06** : réception avec rouleaux numérotés, puis inventaire.
5. **S07, S08 et S09** : réapprovisionnement, analyse et mobile magasinier.
