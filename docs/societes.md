# Plusieurs sociétés dans la même application

Décision de Yaya le 10/10/2026 : la future boutique de consommables d'impression sera une **société séparée** de l'imprimerie. Elle aura ses propres NIF et RCCM, sa propre comptabilité, et une vue commune pour la direction. Rien ne change dans le fonctionnement de l'imprimerie.

## Principe

Une table `societe` porte le nom, le NIF, le RCCM et l'adresse. Le logo, les mentions et la numérotation restent sur les entreprises émettrices, qui appartiennent chacune à une société. Au départ, les entreprises émettrices donnent deux sociétés : Madial Group SARL (Succès Imprim) et DREPA TECHNOLOGIE (voir « Étape 1 »).

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

1. **Socle, sans rien changer à l'écran** : fait le 14/10/2026, voir ci-dessous.
2. **Avec la comptabilité** : filtres par société partout, sélecteur de société, rattachement des utilisateurs, vue consolidée.
3. **À l'ouverture de la boutique** : créer la société dans les réglages, avec ses caisses, son stock, ses produits et ses vendeurs. Aucun développement de plus.

## Étape 1 : ce qui est en place

### Les sociétés

La table `societe` porte le code court (unique), la raison sociale, le RCCM, le NIF, l'adresse, « par défaut » et « active ». Le logo, les mentions, les couleurs et la numérotation restent sur l'entreprise émettrice (Réglages › Entreprises) : **la numérotation reste par émetteur**.

Chaque entreprise émettrice appartient à une société. La migration `Version20261014160000` regroupe les entreprises par NIF (sans espaces, en majuscules), ou par raison sociale quand le NIF manque :

| Société | Code | Entreprises | |
|---|---|---|---|
| Madial Group SARL | `IMP` | Succès Imprim (`mdg_success`), Madial Group SARL (`mdg`) : même NIF 081148957D | id 1, par défaut |
| DREPA TECHNOLOGIE | `DREPA` | DREPA TECHNOLOGIE (`drepa`) | id 2 |

- Le premier groupe (première entreprise dans l'ordre des réglages) prend le code `IMP` et devient la société par défaut. Les autres prennent le code de leur première entreprise.
- Sans aucune entreprise, seule « Madial Group SARL » est créée, avec les valeurs de départ. Une base neuve a déjà les trois entreprises, créées par une migration plus ancienne : elle a donc aussi les deux sociétés.
- L'identifiant public d'une société est tiré de son code (MD5 de `societe:CODE`). Le local et le site en ligne, migrés chacun de leur côté, ont donc les mêmes.
- Il n'y a pas encore d'écran pour créer une société : il viendra avec l'étape 3.

### Ce qui a une société

Une colonne `societe_id` obligatoire, valeur par défaut 1 (la société principale), est remplie par la migration avant d'être rendue obligatoire. Avec la valeur par défaut, une ligne insérée en SQL sans connaître les sociétés (démo, import, ancien code) tombe quand même sur une société valide.

| Donnée | Société |
|---|---|
| Devis, factures | Celle de l'entreprise émettrice. |
| Commandes | Celle de sa première facture non annulée, sinon celle de son devis, sinon par défaut. |
| Paiements | Celle de la facture, sinon celle de la commande (paiement Orange Money en ligne compris). |
| Comptes de trésorerie (caisse, banque, Orange Money) | Par défaut. |
| Mouvements de trésorerie | Celle du compte (source, sinon destination), sinon celle du paiement. |
| Dépenses récurrentes, rapprochements bancaires, clôtures de caisse (journal de caisse) | Celle de leur compte. |
| Dépenses | Celle du compte, sinon celle de l'article. |
| Articles de stock, achats, immobilisations | Par défaut. |
| Entrées et sorties de stock | Celle de l'article. |
| Dotations aux amortissements | Celle de l'immobilisation. |

Non couverts à cette étape :
- **Avoirs** : ils n'existent pas encore (une facture s'annule).
- **Écritures comptables** : elles ne sont pas stockées. L'export les tire des factures, mouvements, achats et dotations, qui ont leur société.
- **Lignes** (de devis, de commande, d'achat, de bon de livraison…) : elles suivent leur document.
- **Fournisseurs** : partagés, comme les clients. Leurs achats ont une société.
- **Budgets de dépenses et bulletins de paie** : avec la comptabilité, à l'étape 2.
- **Transactions et relevés Orange Money, réservations de stock, bons de livraison, échéances** : ils suivent leur commande, leur paiement, leur article ou leur facture.

### La règle

Un document qui a un émetteur prend la société de l'émetteur. Un document lié prend celle du document dont il dépend (commande ← facture ou devis, paiement ← facture ou commande, mouvement ← compte ou paiement…). Le reste prend la société par défaut.

- **Nouveaux documents** : le service `SocieteCourante` applique la règle, et l'écouteur Doctrine `RattachementSociete` l'appelle pour tout document créé sans société. Une société déjà donnée est gardée, par exemple celle reçue par la synchronisation.
- **Changement d'émetteur** d'un devis ou d'une facture : le document change de société, avec ses paiements. La commande suit sa facture, sauf si une autre facture non annulée de la commande appartient à une autre société : elle garde alors la sienne.
- **Mouvement de trésorerie** : il suit le compte, pas le paiement. Un paiement d'une facture DREPA encaissé dans une caisse Madial donne un paiement DREPA et un mouvement Madial. C'est une dette entre sociétés, à traiter à l'étape 2. Aujourd'hui, tous les comptes sont à la société par défaut.
- La même règle existe en SQL (`RattachementSql`) pour la migration et la démo. Un test vérifie que chaque donnée qui a une société a sa règle, en PHP et en SQL.

### À l'écran

Seul ajout : le champ « Société » dans Réglages › Entreprises (réservé à l'admin, comme toute la page). Changer la société d'une entreprise ne vaut que pour les prochains documents : les documents déjà émis gardent la leur. Une société désactivée n'est plus proposée.

Aucune liste, aucun total, aucun menu, aucun droit et aucun PDF ne change. Personne d'autre que l'admin ne voit les sociétés.

### Synchronisation

- La société descend du local au site, avant les entreprises : code, raison sociale, par défaut, active. RCCM, NIF et adresse restent au local.
- Entreprises, devis, commandes, factures et paiements portent leur société par son identifiant public. Elle ne se change pas en ligne.
- Une commande ou un paiement créé en ligne (Orange Money) reçoit sa société en ligne, par la même règle, et la remonte avec lui.
- Les comptes, mouvements, stock, achats et immobilisations ne passent pas : leur société reste au local.

### Ce que l'étape 2 ajoutera

- Filtres et totaux par société.
- Sélecteur de société.
- Rattachement des utilisateurs à une ou plusieurs sociétés.
- Vue consolidée.
- Comptes et plan comptable par société.
- Échanges entre sociétés.
