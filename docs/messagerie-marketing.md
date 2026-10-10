# Assistant de discussion, messagerie et marketing

Yaya a envoyé 6 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/chat-marketing/` :
- C01 à C04 pour la discussion. Dans ce document, elles s'appellent **CH01 à CH04**, pour ne pas les confondre avec C01, la saisie de commande admin.
- K01 et K02 pour le marketing.

Ce document complète `docs/assistant-client.md` (l'assistant IA) et `docs/promotions.md` (les promotions).

## Discussion (CH01 à CH04)

| Maquette | Contenu |
|---|---|
| **CH01 Site** | Une bulle en bas à droite ouvre l'assistant. Il calcule un prix dans la conversation, suit une commande et aide à préparer un fichier. Ensuite, il passe la main à un conseiller, au besoin sur WhatsApp. |
| **CH02 Espace client** | Les messages sont rattachés à une commande. Le client peut valider un BAT directement dans la conversation. |
| **CH03 Mobile** | Une bulle avec un court message d'accueil, puis l'assistant en plein écran. |
| **CH04 Messagerie admin** | Une seule boîte pour le site, l'espace client, WhatsApp et le SMS. Filtres : Tous, À moi, Non assignés. Le conseiller voit qui traite chaque conversation et peut la reprendre (« Prendre la conversation »). À droite, ce que le visiteur a consulté et simulé. On peut créer un devis ou un prospect depuis la conversation, et utiliser des réponses types. |

### Ce qui existe déjà

- `Conversation` et `Message` : la discussion interne entre utilisateurs, avec pièces jointes.
- Les clients de l'espace client, les commandes et les BAT.

### Ce qu'il faut ajouter

- **Origine de la conversation :** site, espace client, WhatsApp ou SMS. Le visiteur anonyme est identifié par un jeton de navigateur. Il devient un prospect ou un client quand il donne son numéro.
- **Lien avec la commande** pour l'espace client.
- **Attribution :** assistant, puis conseiller. L'état de la conversation est ouverte, en attente ou résolue. Le temps d'attente est affiché.
- **Prix :** l'assistant les calcule avec le moteur unique des options et des prix (`docs/stock-et-options.md`), jamais seul.

### Ce qui dépend d'ailleurs

- **WhatsApp entrant et sortant :** il faut l'API Meta, prévue en J5 (voir la mémoire du projet, WhatsApp). D'ici là, la messagerie ouvre WhatsApp à la main, comme la liste « À retirer ».
- **SMS entrant :** il faut vérifier si l'offre SMS Orange actuelle reçoit les réponses. Sinon, la messagerie gère seulement le SMS sortant.
- **Assistant IA :** la clé va dans `.env.local` et le coût est suivi (`docs/assistant-client.md`).

### Droits

- Un conseiller voit les conversations qui lui sont assignées et les non assignées.
- L'admin voit tout.
- Les montants d'un client restent soumis aux mêmes droits qu'ailleurs.

## Marketing (K01, K02)

| Maquette | Contenu |
|---|---|
| **K01 Site** | Un bandeau promo avec code. Une offre de bienvenue (−10 %) qui recueille le numéro, avec le consentement du client. |
| **K02 Admin** | Codes promo avec leurs résultats (utilisations, chiffre d'affaires). Fenêtres programmées : nouveaux visiteurs, sortie de page, rappel de panier. |

### Règles affichées dans K02, appliquées par l'application

- Une seule fenêtre par visite.
- Consentement obligatoire : une case non cochée par défaut, et la date du consentement est enregistrée.
- Pas de faux compteurs ni de faux « plus que 2 en stock ». Un compteur affiché vient toujours d'une donnée réelle.
- Désinscription possible à chaque message (« STOP »).

Les codes promo passent par le moteur de promotions (`docs/promotions.md`). Les exclusions s'appliquent donc : par exemple, les clients B2B sont exclus sauf exception validée.

## Ordre proposé

1. **CH04 :** la messagerie admin pour le site et l'espace client. Elle sert de base à tout le reste.
2. **CH01 à CH03 :** la bulle sur le site, l'espace client et le mobile, avec l'assistant IA en J5.
3. **K02 puis K01 :** les codes promo et les fenêtres. Ils viennent après le moteur de promotions.
4. **WhatsApp et SMS** dans la même boîte, quand l'API Meta est branchée.
