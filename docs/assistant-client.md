# Assistant IA du site et de l'espace client

Demandé par Yaya le 10/10/2026 et validé le même jour. Prévu à l'étape J5, avec l'espace client. Il n'y a pas encore de maquette ; ce document fixe le périmètre.

## Ce qu'il fait

| Où | Ce qu'il sait | Ce qu'il peut faire |
|---|---|---|
| Site public (visiteur) | Le catalogue et la grille tarifaire publiés, les délais habituels, les horaires, l'adresse et le plan, les formats et fichiers acceptés, les promotions en cours ouvertes à tous | Répondre ; préparer une demande de devis à partir de la conversation (nom, téléphone, produit, dimensions, quantité, date souhaitée) |
| Espace client (client connecté) | Tout ce qui précède, plus les commandes, factures, soldes et « Mes produits » **de ce client seulement** | Dire où en est une commande, ce qui reste à payer et la date de livraison ; proposer de recommander un produit du client ; renvoyer vers le paiement Orange Money |

## Règles

- Il n'invente jamais un prix, un délai ou un statut. Il ne donne que ce que l'application lui fournit au moment de la question. Sinon, il le dit et propose un agent.
- **Passage à un humain** : à la demande du client, ou quand il n'est pas sûr. Le client reçoit un lien WhatsApp vers la boutique, avec le résumé de la conversation déjà écrit.
- **Confidentialité** : il ne voit que les données du client connecté. Les données internes sont exclues :
  - coûts et marges ;
  - autres clients ;
  - chiffres de l'entreprise ;
  - employés.

  C'est le même principe que les droits par rôle : voir [la gestion des droits](../src/Security/Droits.php).
- Il ne fait aucune action d'argent seul : pas de remise, pas d'annulation, pas de remboursement. Ces demandes vont à un agent.
- Il répond en français.

## Côté technique

- Il s'appuie sur un modèle d'IA (Claude, d'Anthropic) par API. Les réponses s'appuient sur des fonctions de l'application, par exemple chercher un produit, lire une commande du client ou créer une demande de devis, et jamais sur un accès direct à la base.
- La clé d'API se met seulement dans `.env.local` sur le serveur, comme les clés Orange Money. Elle n'est jamais dans le dépôt.
- Le coût est payé à l'usage. Une limite par jour, réglable dans l'admin, coupe l'assistant et affiche « contactez-nous sur WhatsApp » une fois atteinte.

## Dans l'admin

- **Conversations** : une liste filtrable avec la note du client (pouce en haut ou en bas) et les passages à un agent.
- **Analyse** : les questions les plus posées et les questions sans réponse, pour compléter le catalogue ou les réponses. Les suggestions des clients alimentent aussi l'analyse des [sondages](sondages.md).
- **Réglages** : activer ou couper l'assistant, le message d'accueil, la limite par jour, le numéro WhatsApp de passage à un agent.
