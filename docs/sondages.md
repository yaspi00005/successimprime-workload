# Sondages et satisfaction client

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

## Trois types de sondage

| Type | Quand | Qui répond |
|---|---|---|
| Global | Lancé par l'admin (ex. une fois par trimestre) | Tous les clients, ou un segment (actifs, inactifs, entreprises) |
| Par commande ou par article | Envoyé automatiquement à la livraison, lien WhatsApp ou SMS, et proposé dans l'espace client | Le client de la commande, avec une note par ligne (article) et une note globale |
| Libre | Créé à la demande, publié par un lien ou un QR code (boutique, réseaux sociaux) | N'importe qui, anonyme possible |

## Questions possibles

Note de 1 à 5 étoiles, recommandation de 0 à 10 (NPS), choix unique, choix multiple, oui ou non, texte libre (« Votre proposition pour nous améliorer »).

Le sondage de commande a un modèle fixe court : note globale, note par article (qualité, respect du délai, accueil), et une proposition libre. Les sondages global et libre se construisent avec ces types de questions.

## Analyse

| Indicateur | Calcul | Alerte |
|---|---|---|
| Satisfaction moyenne | Moyenne des notes globales sur 5 | < 4,2 (même seuil que l'indicateur 9) |
| Clients satisfaits | Notes de 4 et 5 ÷ réponses | — |
| NPS | % de 9–10 moins % de 0–6 | Négatif |
| Taux de réponse | Réponses ÷ sondages envoyés | — |
| Satisfaction par produit, agent, machine et période | Moyenne des notes par article | Produit sous 3,5 |
| Propositions clients | Textes libres classés par thème : délai, prix, qualité d'impression, accueil, livraison, autre | — |

- Une note de 1 ou 2 crée une alerte de suivi : l'agent rappelle le client, et peut ouvrir une réclamation.
- Le classement des propositions par thème se fait d'abord par mots-clés ; chaque proposition peut être reclassée à la main et marquée « retenue », « à étudier » ou « écartée ».
- Les résultats suivent les règles communes de `docs/indicateurs.md` : comparaison au mois précédent et à N−1, filtres, clic vers la liste puis la réponse d'origine.

## Où ça apparaît

- Admin : section Clients, onglet « Sondages et avis » (liste, création, résultats).
- Espace client et site public (J5) : réponse aux sondages, lien depuis la fiche commande livrée.
- Tableau de bord direction : satisfaction moyenne et propositions récentes.
- Écran TV : note moyenne seulement (jamais de nom ni de texte client).
