# « Mes produits » : produits du client et commande en m² ou en pièces

Proposition de Yaya le 10/10/2026, avec 5 maquettes (aperçu dans `/mnt/project-files/maquettes/mes-produits/`). Ce document reprend son fonctionnement et le relie au modèle de données.

## Principe

Le client retrouve dans son espace ses produits déjà validés (bâche de façade, roll-up, vinyle de vitrine…). Il indique une quantité en m² ou en pièces, et cette quantité devient une vraie commande dans l'ERP. Le prix vient toujours de la fiche produit : le client n'a aucun calcul à faire.

## 1. Le produit du client (admin)

Créé à la main, ou depuis une commande livrée avec « Enregistrer comme produit du client ». Il fixe :

- le support, le format et les finitions ;
- le fichier et le BAT validé, qui ne changent plus ;
- l'unité de vente : à la pièce, au m², les deux au choix, ou par lot ;
- le prix négocié, la quantité minimum, la machine ;
- la règle d'arrondi quand la quantité est saisie en m² ;
- une référence lisible (`PC-AWA-01`).

L'écran admin affiche aussi son coût de revient (calcul automatique) et le nombre de fois où il a été commandé. Le produit apparaît dans l'espace client par la synchronisation.

## 2. La quantité saisie par le client

| Type de produit | Saisie | Calcul automatique |
|---|---|---|
| Format fixe (bâche 400 × 150) | En pièces | Pièces × surface d'une pièce → m² |
| Format fixe | En m² | m² ÷ surface d'une pièce, arrondi au nombre supérieur ; le client voit l'arrondi et l'accepte (ex. 20 m² → 4 bâches = 24 m², ou 3 bâches = 18 m²) |
| Longueur libre (vinyle, bâche au mètre) | En m² | m² ÷ largeur du rouleau → mètres à imprimer (ex. 6 m² sur 1,37 m → 4,4 m) |
| Petit format (étiquettes, cartes) | Par lot (100, 500) | Nombre de feuilles et poses par feuille |
| Textile | En pièces | Avec la répartition des tailles |

## 3. La commande réelle

Quand le client valide sa sélection :

- une vraie commande est créée, une ligne par produit, avec la référence du produit ;
- chaque ligne garde **deux quantités** : celle demandée par le client (« 20 m² ») et celle à produire (« 4 bâches = 24 m² ») ;
- la ligne part directement en production avec le BAT déjà validé, sans passer par la PAO ;
- la matière est réservée en stock (m² de bâche, mètres de vinyle, film DTF) ;
- l'acompte est demandé par Orange Money Mali ; payé en ligne, il reste provisoire jusqu'à la validation en local.

## 4. Le suivi

Pour chaque ligne : quantité produite, quantité livrée et reste à livrer. Les livraisons partielles sont possibles. Le client voit les mêmes chiffres dans son espace, et la facture porte sur la quantité produite qu'il a acceptée.

## 5. Modifier un produit

Changer le visuel ou la taille crée un nouveau produit, qui exige un nouveau BAT. L'ancien reste disponible tel quel.

## Dans le code

- Nouvelle entité `ProduitClient` (client, référence, produit du catalogue, format, finitions, fichier et contrôle prépresse figés, unité de vente, prix négocié, minimum, machine, règle d'arrondi, produit remplacé).
- `CommandesDetails` gagne le lien vers le produit du client et la quantité demandée avec son unité. La quantité à produire reste `quantite` ; `getQuantiteProduite()` et `getQuantiteLivree()` existent déjà.
- Les règles de conversion (pièces, m², longueur, lots) sont un service pur, testé, partagé par l'admin, le site et le mobile.
- Une ligne venant d'un produit du client a son BAT déjà validé : `isPrePresseNecessaire()` doit le savoir.
- Côté admin, construit avec la saisie de commande (maquette C01). Côté client, avec l'espace client (J5).
