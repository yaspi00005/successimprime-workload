# Sondages et satisfaction client

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

## Trois types de sondage

| Type | Quand | Qui répond |
|---|---|---|
| Global | Lancé par l'admin (ex. une fois par trimestre) | Tous les clients, ou un segment (actifs, inactifs, entreprises) |
| Par commande ou par article | Envoyé automatiquement à la livraison, lien WhatsApp ou SMS, et proposé dans l'espace client | Le client de la commande, avec une note par ligne (article) et une note globale |
| Libre | Créé à la demande, publié par un lien ou un QR code (affiche à l'imprimerie, réseaux sociaux) | N'importe qui, anonyme possible |

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

## Construit le 14/10/2026

**Atelier** (Clients › Sondages et avis, rôle Clients ; créer, lancer, clore et la note par agent : direction) :

| Écran | Adresse | Contenu |
|---|---|---|
| Vue d'ensemble | /clients/sondages | Satisfaction moyenne, clients satisfaits, NPS, taux de réponse, comparés à la période d'avant et à N−1 ; note par mois sur 12 mois ; mauvaises notes à rappeler ; liste des sondages ; dernières propositions. Filtres : période, type d'avis. |
| Commandes livrées | /clients/sondages/commandes | Les mêmes chiffres pour l'avis après livraison ; note par produit (alerte sous 3,5), par machine, par agent (direction) ; avis pas encore reçus avec relance WhatsApp ; réponses filtrées par note. |
| Sondage | /clients/sondages/{id} | Lancer, clore, modifier (sans réponse), supprimer (sans réponse) ; lien, QR code et affiche à imprimer d'un sondage libre ; résultats question par question. |
| Nouveau sondage | /clients/sondages/nouveau | Titre, introduction, clients visés (global) ou anonymat (libre), questions à ajouter, déplacer et retirer. Questions figées dès la première réponse. |
| Propositions | /clients/sondages/propositions | Classement par thème (mots-clés, puis à la main) et statut retenue, à étudier ou écartée ; les 60 plus récentes. |
| À rappeler | /clients/sondages/alertes | Notes de 1 ou 2 : rappeler, noter la suite, ou ouvrir une réclamation que le client voit dans son espace. |

**Client** : carte « Votre avis compte » sur l'accueil de l'espace, « Mes avis » dans le compte, lien depuis une commande livrée ; formulaire de commande (note globale, note par article, délai, accueil, recommandation, proposition) et formulaire des sondages globaux.

**Sans compte** : /avis/c/{jeton} (lien de livraison, 60 jours) et /avis/s/{jeton} (lien ou QR code d'un sondage libre), avec jeton CSRF, champ piège et 10 réponses par heure et par connexion.

**Ailleurs** : tableau de bord direction (note du mois, à rappeler, 3 propositions, rôle Clients) ; écran TV (note des 12 derniers mois à partir de 5 avis, seulement quand la note Google n'est pas réglée ; jamais de nom ni de texte).

**Envoi à la livraison** : au local, quand la dernière ligne d'une commande passe livrée, une invitation est créée et le SMS part si le compte SMS et l'adresse du site sont réglés et que le client accepte les SMS ; jamais pour un client DEMO-. L'atelier a aussi un bouton WhatsApp. `php bin/console app:sondages:inviter` rattrape les commandes livrées sans invitation (`--sms` pour envoyer aussi le SMS). Aucun montant dans les messages.

**Synchronisation** : sondages et invitations descendent ; les réponses remontent avec leurs notes par article. Le thème, le statut des propositions et le suivi des mauvaises notes restent au local.

**Données d'essai** : `php bin/console app:sondages:demo` (clients DEMO- seulement ; `--purger` les enlève).

Reste à faire : l'export Excel des réponses.
