# Promotions par segment de clients

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

## Ce qui existe déjà

- Prix B2B par produit (`Produits::prixB2B`, remise dérivée `getRemiseB2B()`), appliqué aux comptes entreprise.
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
