# Ventes hors imprimerie

L'imprimerie vend aussi du matériel aux entreprises : tablettes, ordinateurs, drones… Ces ventes pèsent souvent plus lourd que l'impression. Pour juger l'activité d'imprimerie, elles sont comptées à part dans les statistiques.

## La case « Hors imprimerie »

- Elle est sur le devis et sur la commande, sous l'objet. Elle est décochée par défaut.
- Texte d'aide : « Vente de matériel, informatique… : comptée à part de l'imprimerie dans les statistiques ».
- Elle suit le devis à la commande quand le devis est transformé, et une commande dupliquée la garde.
- La facture n'a pas de case : elle prend celle de sa commande. Une facture sans commande compte pour l'imprimerie.
- Une pastille violette « Hors imprimerie » le rappelle sur la fiche de la commande et du devis, dans la liste des commandes, des devis et des ventes.
- Une commande passée en ligne par un client est toujours de l'imprimerie. Seule l'imprimerie peut la cocher ensuite.

## Pas de prépresse ni d'atelier

Une commande hors imprimerie n'a rien à fabriquer. À l'enregistrement, quel que soit l'écran (`HorsImprimerieListener`) :

- case cochée : chaque ligne encore « à produire », sans contrôle prépresse ni production commencée, passe « non requise », sans prépresse. Ses lignes ne vont donc ni en prépresse ni à l'atelier : elles vont directement à la livraison ;
- une ligne déjà en prépresse, en production, prête, livrée ou annulée n'est jamais touchée ;
- case décochée : les lignes de produit « non requise » redeviennent « à produire », avec la prépresse. Les articles et les lignes libres restent en livraison directe, comme à la saisie.

La file de prépresse ne prend que les lignes qui demandent la prépresse, et l'atelier (tableau de production, postes, aiguillage) laisse de côté les lignes « non requise ».

## Statistiques

Par défaut, les statistiques comptent **l'imprimerie seule**. Une carte « Imprimerie / Hors imprimerie / Total » montre les deux côtés et leur total : CA facturé et sa part, marge brute quand elle est calculée, nombre de commandes.

| Écran | Carte | Filtre « Activité » |
|---|---|---|
| Statistiques et rapports | oui (avec la marge pour qui voit les marges) | oui : CA, marge, catégories, meilleurs clients et agents |
| Statistiques globales | oui | oui : ventes par agent, top produits, ventes d'articles |
| Rapport de la semaine (page et PDF) | oui | non : l'imprimerie |
| Rapports par e-mail | ligne « Hors imprimerie » et total | non |
| Tableau de bord direction | ligne « Hors imprimerie » sous le CA du mois | non |

Le filtre « Activité » vaut « Imprimerie » (par défaut), « Hors imprimerie » ou « Tout ». Les exports Excel et PDF suivent le filtre et reprennent la carte.

- Les objectifs (CA facturé, commandes) et les primes ne comptent que l'imprimerie. La transformation des devis d'un agent aussi.
- Le taux d'encaissement et l'alerte des créances comparent à **tout** le CA facturé, car les paiements et les créances comptent toutes les ventes.
- Les montants gardent leurs droits : sans le droit de voir les ventes, la carte ne montre que le nombre de commandes ; la marge demande le droit des marges.

## Ce qui ne change pas

Factures, paiements, caisse, trésorerie, créances, soldes des clients, comptabilité et écrans Finances comptent toutes les ventes, comme avant.

## Synchronisation et démonstration

- La case passe du local au site avec la commande et le devis (`CatalogueSynchro`).
- `app:demo` charge deux ventes de matériel facturées (tablettes, ordinateurs portables), cochées hors imprimerie.
