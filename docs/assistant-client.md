# Assistant IA du site et de l'espace client

Demandé par Yaya le 10/10/2026 et validé le même jour. Prévu à l'étape J5, avec l'espace client. Il n'y a pas de maquette : le panneau reprend sobrement les couleurs du site et de l'espace client, à revoir si une maquette arrive.

Construit le 14/10/2026. Le périmètre validé est rappelé ci-dessous, puis ce qui est en place.

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

## Où il apparaît

- Un bouton rond « Assistant » en bas à droite de chaque page du site (`templates/site/_site.html.twig`) et de l'espace client (`templates/espace/app/_app.html.twig`, pages avec la coque seulement). Il se place au-dessus du bouton WhatsApp, de la barre du calculateur et de la barre de total de l'espace.
- Le bouton n'apparaît que si l'assistant est activé (Réglages) et que la clé de l'API est posée sur le serveur (ou la démonstration allumée en développement). Sinon, rien n'est affiché. La clé ne se pose qu'en ligne : sur l'ERP local, l'assistant reste caché.
- Le script `public/js/assistant.js` ne se charge qu'au premier clic : les pages ne s'alourdissent pas. Feuille `public/css/assistant.css`, gabarit `templates/assistant/_widget.html.twig`, extension `App\Twig\AssistantExtension`.
- Le panneau tient sur un téléphone de 390 px (plein écran), se ferme avec Échap, rend le focus au bouton, et annonce les réponses aux lecteurs d'écran (`role="log"`). Les questions suggérées changent entre le site et l'espace.
- La conversation suit le visiteur d'une page à l'autre : un jeton tiré au hasard, gardé dans le navigateur, retrouve l'historique. « Nouvelle conversation » l'oublie.
- Sous chaque réponse : un pouce en haut, un pouce en bas, et les boutons proposés par les outils (voir la commande, payer, demander ce devis, itinéraire, WhatsApp…). Les liens sont vérifiés dans le navigateur : adresses du site, `https://wa.me/` pour WhatsApp, et `https://` seulement pour l'itinéraire.

## Ce qu'il sait faire (outils)

L'assistant ne lit jamais la base. Il appelle des fonctions de l'application (`App\Service\Assistant\OutilsAssistant`), qui renvoient seulement ce que la personne a le droit de voir.

| Outil | Site | Espace | Ce qu'il renvoie |
|---|---|---|---|
| `chercher_produits` | oui | oui | Produits publiés (8 au plus) : prix de départ au tarif public, taille à donner, quantité minimale, finitions |
| `estimer_prix` | oui | oui | Le calcul du calculateur du site, au tarif public, avec le bouton « Demander ce devis » (site) ou « Commander en ligne » (espace) |
| `infos_boutique` | oui | oui | Adresse, téléphone, horaires et ouverture du moment, fichiers acceptés, délais du réglage, suivi d'une commande |
| `pages_du_site` | oui | oui | Texte publié de la FAQ, du guide des fichiers, des engagements, de « Qui sommes-nous ? », des équipements |
| `promotions_en_cours` | oui | oui | Site : promotions ouvertes à tous. Espace : celles de ce client |
| `passer_a_un_agent` | oui | oui | Le bouton WhatsApp avec le résumé déjà écrit et le numéro de la conversation |
| `mes_commandes` | non | oui | Commandes du client connecté (5 dernières ou par numéro) : étape, BAT à valider, livraison prévue ; montants si le compte paie |
| `reste_a_payer` | non | oui | Reste à payer des commandes et factures à régler, avec « Payer la commande » (Orange Money) quand une seule commande reste à payer, sinon « Mes factures » ; refusé si le compte ne paie pas |
| `mes_produits` | non | oui | « Mes produits » du client : nom, format, date du BAT ; prix si le compte paie ; refusé à un compte en lecture |
| `preparer_demande_devis` | non | oui | Enregistre la même demande de devis que le formulaire du site, au nom et au téléphone du compte connecté, après récapitulatif et accord ; 3 par heure et par compte au plus |

**Sur le site**, l'assistant ne crée pas la demande de devis lui-même : il calcule le prix public et propose « Demander ce devis », qui ouvre le formulaire du site déjà rempli. Le visiteur y tape son nom et son téléphone et l'envoie. Ainsi, rien n'est enregistré au nom d'un inconnu sans qu'il l'ait vu.

## Droits

La règle de Yaya : toute information sensible tient compte des droits et des rôles. Elle est appliquée côté serveur, dans les outils, pas dans les consignes du modèle.

- **Client** : il vient toujours de la session (`ContexteAssistant`), jamais d'un paramètre. Aucun outil n'accepte de numéro de client, de compte, de téléphone ou d'identifiant (test `OutilsAssistantTest`).
- **Montants** : seulement pour un compte qui paie (droit « payeur ») ou un particulier. Un compte « demandeur » d'une entreprise voit l'étape et la livraison, pas les montants ; `reste_a_payer` lui est refusé. C'est plus strict que les pages de l'espace, où un valideur voit aussi les prix (`CompteClient::montantsVisibles`) : l'assistant n'en dit pas plus que le droit le plus prudent.
- **Lecture · suivi** : suivi des commandes seulement ; ni montants, ni « Mes produits », ni demande de devis.
- **Site public** : catalogue et prix publics seulement. Un client revendeur connecté obtient le tarif public avec une note : son prix est celui de Nouvelle commande et du devis. Jamais de montants d'un client, de noms de clients, de coûts, de marges, de prix B2B ni de notes internes.
- **Argent** : aucune action. Remise, annulation, remboursement ou litige passent à un conseiller.

## Contre les messages piégés

- Le texte du visiteur et les résultats des outils sont des données. Les consignes (`ConsignesAssistant`) le disent, et la question est nettoyée : balises `<contexte>` retirées, caractères de contrôle supprimés, 800 caractères au plus.
- Le contexte du tour (date, page, compte connecté et ses droits) est écrit par le serveur, jamais par le visiteur.
- Même si le modèle se laissait convaincre, il ne pourrait rien obtenir de plus : chaque outil vérifie le compte et les droits lui-même.
- Les liens affichés ne viennent que des outils ; le texte des réponses est affiché comme du texte, jamais comme du HTML.

## Passage à un conseiller

Quand la personne le demande, qu'elle est mécontente, que les outils ne donnent pas la réponse ou que cela touche à l'argent, l'assistant propose « Écrire à un conseiller sur WhatsApp ». Le lien ouvre WhatsApp avec un message déjà écrit : le résumé de la demande et le numéro de la conversation (ex. AS-0012), que l'atelier retrouve dans Clients › Assistant du site. Le numéro est celui du réglage, ou à défaut le téléphone de l'entité Succès Imprim. Rien n'est envoyé tant que la personne n'appuie pas sur « Envoyer » dans WhatsApp.

Si le modèle refuse de répondre (arrêt « refusal »), la personne reçoit ce même passage au conseiller, et l'échange refusé n'est pas gardé dans l'historique envoyé à l'API.

## Côté technique

- Il s'appuie sur Claude, d'Anthropic, par le SDK PHP officiel (`anthropic-ai/sdk`). Modèle par défaut : Claude Opus 5.5 (`claude-opus-5-5`), effort « faible ». Le modèle se change dans les Réglages ; `ASSISTANT_MODELE` sur le serveur l'emporte.
- Boucle d'outils dans `App\Service\Assistant\Assistant` : 6 allers-retours au plus par question, 4 096 jetons de réponse au plus, outils stricts, choix des outils laissé au modèle.
- **Repli du serveur** : avec Opus 5.5, Opus 5 et Sonnet 5.5, la requête demande le repli automatique d'Anthropic (en-tête `server-side-fallback-2026-07-01`, `fallbacks: default`). Si le modèle est saturé ou refuse, un autre modèle répond. Chaque tentative est comptée à son tarif.
- **Historique** : la conversation est renvoyée à l'API telle qu'elle a été reçue, sans rien réécrire. Les consignes et les outils ne changent jamais d'une requête à l'autre ; la date et la page passent dans le message du tour. Ainsi, le cache de l'API sert à chaque question : la partie déjà lue coûte vingt fois moins cher avec Opus 5.5.
- **Limites** : 30 questions par conversation (au-delà, « Nouvelle conversation ») ; par heure, 120 questions par adresse, 20 par conversation, 40 par compte de l'espace (`config/packages/rate_limiter.yaml`).
- **Clé** : `ANTHROPIC_API_KEY`, dans `.env.local` du serveur en ligne seulement, jamais dans le dépôt ni dans les Réglages. Sans clé, l'assistant est caché. Voir [le déploiement](deploiement.md).
- **Démonstration** : `ASSISTANT_DEMO=1` remplace l'API par des réponses écrites d'avance (`RepondeurDemo`), qui appellent les vrais outils. Elle ne joue qu'avec `APP_ENV=dev` ; en production, elle est ignorée et `app:verifier-installation` la signale en erreur.
- Adresses : `/assistant/message|note|historique` (site, JSON, en-tête `X-Assistant`) et `/espace/assistant/message|note|historique` (espace, session et jeton CSRF).

## Coût et plafond

- La dépense de chaque réponse se calcule depuis les jetons comptés par l'API (entrée, sortie, lecture et écriture du cache), au tarif des Réglages, puis en francs CFA au taux du dollar des Réglages (`CoutAssistant`).
- Le **plafond par jour** (5 000 F par défaut) est vérifié avant chaque question. Atteint, l'assistant ne fait plus d'appel et répond « écrivez-nous sur WhatsApp » jusqu'à minuit. 0 l'arrête.
- Fixer aussi une limite mensuelle dans la console d'Anthropic : elle protège même si le serveur est mal réglé.

## Dans l'admin

- **Réglages › Assistant du site** : activer ou couper, message d'accueil, plafond par jour, WhatsApp des conseillers, délais à annoncer, modèle, effort, taux du dollar, tarifs. Ces réglages descendent au site par la synchronisation.
- **Clients › Assistant du site** (`/assistant-ia`) :
  - **Conversations** : liste par période, filtrée par lieu (site ou espace), par avis, par passage au conseiller, sans réponse, ou par recherche. Chaque conversation se lit en entier, avec l'avis, les outils appelés et la dépense.
  - **Analyse** : nombre de conversations et de questions, part de réponses utiles, passages au conseiller, dépense de la période et du jour face au plafond, questions les plus posées, sujets, et la liste « À reprendre » : sans réponse, passées à un conseiller ou jugées pas utiles.
- Nouveau droit **« Assistant du site »** (`ROLE_ASSISTANT`), donné au rôle administrateur. Seul ce droit voit les conversations, l'analyse et la tuile de réglages. Les textes des conversations peuvent contenir les montants qu'un client payeur a demandés : c'est pourquoi ce droit est séparé.
- Les suggestions des clients n'alimentent pas encore l'analyse des [sondages](sondages.md) : à faire.

## Synchronisation

- Les réglages de la tuile descendent du local au site (liste blanche `REGLAGES_PUBLICS`). La clé de l'API n'est pas un réglage.
- Les conversations et leurs messages montent du site au local, et ne redescendent pas. Ils montent sans le jeton du visiteur, sans le compte connecté, sans les échanges exacts avec l'API, sans les boutons proposés et sans adresse IP : seulement le texte, l'issue, l'avis, les outils appelés, les jetons et la dépense. Voir [la synchronisation](synchronisation.md).

## À décider

- Aucune purge des conversations n'est prévue : fixer une durée de conservation.
- Les tarifs de la tuile sont ceux du modèle choisi : les changer avec le modèle (la note de la tuile les rappelle).
- La page notée est celle où la conversation a commencé.
