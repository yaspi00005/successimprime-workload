# Plusieurs sociétés dans la même application

Décision de Yaya le 10/10/2026 : la future boutique de consommables d'impression sera une **société séparée** de l'imprimerie. Elle aura ses propres NIF et RCCM, sa propre comptabilité, et une vue commune pour la direction. Rien ne change dans le fonctionnement de l'imprimerie.

## Principe

Une table `societe` porte le nom, le NIF, le RCCM, l'adresse, le logo, les mentions de facture et les préfixes de numérotation. Au départ, il n'y a qu'une société : Succès Imprim.

Chaque document qui touche à l'argent ou au stock appartient à une société :
- devis, commandes, factures, avoirs, paiements ;
- comptes de trésorerie (caisse, banque, Orange Money), mouvements, dépenses récurrentes, rapprochements ;
- articles de stock, entrées et sorties, achats, fournisseurs liés ;
- journal de caisse.

| Partagé entre les sociétés | Propre à chaque société |
|---|---|
| Clients (un client peut acheter aux deux) | Numérotation : FAC-IMP-…, FAC-BTQ-… |
| Utilisateurs, rôles et droits | En-tête et mentions des factures, NIF, RCCM |
| Catalogue de base (les produits sont rattachés à une ou plusieurs sociétés) | Caisses, comptes bancaires, Orange Money (clés propres dans `.env.local`) |
| Réglages généraux | Stock, achats, dépenses |
| | Résultat, trésorerie, créances clients |

## Accès

- Chaque utilisateur est rattaché à une ou plusieurs sociétés. Une vendeuse de la boutique ne voit que la boutique.
- Quand une personne a plusieurs sociétés, un sélecteur dans l'en-tête choisit la société de travail. Tout ce qu'elle crée porte cette société.
- Le droit « Vue consolidée » (direction) montre les deux sociétés, ensemble ou séparées.

## Entre les deux sociétés

Quand l'imprimerie achète de l'encre ou du papier à la boutique, il y a une vraie facture de la boutique à l'imprimerie. L'imprimerie est enregistrée comme cliente de la boutique, et la boutique comme fournisseur de l'imprimerie.
- Côté boutique, c'est une vente encaissée.
- Côté imprimerie, c'est un achat ou une entrée de stock.
- La vue consolidée retire ces échanges internes pour ne pas compter deux fois le chiffre d'affaires et les charges.

## Comptabilité

- Chaque société a son plan de comptes, ses journaux et ses exports (balance, grand livre, export pour le comptable), sans mélange.
- La vue consolidée présente le chiffre d'affaires, le résultat et la trésorerie par société et au total, sans les échanges internes.

## Étapes

1. **Socle, à faire tôt, sans rien changer à l'écran** : table `societe` avec Succès Imprim, colonne `societe` sur les documents cités, remplie avec Succès Imprim par la migration. Numérotation et en-têtes lus depuis la société.
2. **Avec la comptabilité** : filtres par société partout, sélecteur de société, rattachement des utilisateurs, vue consolidée.
3. **À l'ouverture de la boutique** : créer la société dans les réglages, avec ses caisses, son stock, ses produits et ses vendeurs. Aucun développement de plus.

Les devis ont déjà un champ « émetteur » (DREPA, MDG Success, MDG). Il sera repris dans la table `societe` à l'étape 1, après vérification avec Yaya de ce que chacun représente.
