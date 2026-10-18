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

- **WhatsApp :** décidé avec Yaya le 10/10/2026, l'envoi passe par un seul service.
  - Clé WhatsApp vide dans `.env.local` : chaque bouton ouvre WhatsApp avec le message déjà écrit (lien wa.me), comme la liste « À retirer ».
  - Clé présente : l'application envoie elle-même par l'API Meta, sans changement de code.
  - Les réponses des clients n'entrent dans la messagerie unique qu'avec l'API. Avec les liens, elles arrivent sur le téléphone.
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

## Crédits SMS et e-mail

Demande de Yaya du 16/10/2026 : « Il faut ajouter également une partie dans l'administration pour connaître le nombre de SMS disponibles, sa date d'expiration, l'historique des envois. » puis « Y compris pour le mail aussi. »

**Où :** Clients › Crédits SMS et e-mail (`/communications/credits`), à côté des campagnes WhatsApp et SMS. Les seuils et les limites se règlent dans Réglages › Clients et communication › tuile « Crédits SMS et e-mail » (bouton « Seuils et limites » de l'écran). Fiche du guide « Crédits SMS et e-mail » (groupe Direction).

**Qui :** la Direction, et qui reçoit le droit « Crédits SMS et e-mail » (`ROLE_CREDITS_MESSAGES`, droits détaillés de la personne, ou module « Crédits SMS et e-mail » de Rôles et menus). Sans lui, l'écran, l'historique et l'export répondent « accès refusé », même tapés à la main. Le numéro et l'adresse complets, l'objet des e-mails (un modèle peut y mettre le nom du client) et la recherche demandent en plus le droit Clients : sans lui, « +223 •• •• 42 12 », « a•••@gmail.com ». Les messages au personnel (code de sécurité de l'administration en ligne, rapport de la semaine) ne sont pas des données de clients : leur destinataire en entier, et sa recherche, sont pour la Direction seulement (sinon on lirait l'adresse d'un employé et l'heure de ses connexions). Le prix des packs SMS achetés demande « Trésorerie et soldes ».

### SMS

- **Avec les clés Orange** (`ORANGE_SMS_CLIENT_ID`, `ORANGE_SMS_CLIENT_SECRET` dans `.env.local`) : le solde est lu chez Orange avec le jeton déjà utilisé pour envoyer (`GET https://api.orange.com/sms/admin/v1/contracts` : unités restantes `availableUnits`, fin `expirationDate`, état `ACTIVE` ou `EXPIRED`, par pays ; on garde le Mali, `MLI`). Les achats de packs viennent de `GET /sms/admin/v1/purchaseorders` (date, pack, SMS ajoutés, nouvelle fin, prix). Format lu dans la documentation publique d'Orange (« SMS Africa and Middle East », Getting started, 5.1 et 5.2), pas encore essayé avec les vraies clés.
- La lecture est gardée une heure : ouvrir la page ne rappelle pas Orange. « Actualiser » relit tout de suite (au plus une fois par minute). Une lecture ratée garde les derniers chiffres, avec l'heure de la dernière lecture réussie et l'erreur ; elle est retentée après 5 minutes. Ni le jeton ni les clés ne vont dans le journal ou à l'écran.
- **Sans les clés** : l'écran dit « Clés Orange SMS non configurées » et propose de recopier le solde et la fin du pack lus sur le portail Orange (« Enregistrer le solde »). Les SMS partis ensuite en sont retirés : le chiffre affiché est une estimation. La même saisie reste proposée quand les clés sont posées mais qu'Orange n'a encore jamais pu être lu (clés refusées, pas d'accès aux adresses d'administration).
- Le nombre de SMS d'un message est compté comme chez l'opérateur : 160 caractères en un SMS (153 par partie au-delà) ; un seul caractère hors de l'alphabet GSM (ê, ç, ’, emoji) fait passer à 70 (67 par partie). Le solde lu chez Orange reste la référence.

### E-mail

- Envois de la dernière heure, du jour et du mois, comptés dans l'historique, face aux limites de l'hébergeur saisies dans la tuile (par heure, par jour, par mois ; 0 : pas de limite connue), avec ce qu'il en reste.
- Fin de l'abonnement de la messagerie (hébergeur, par exemple LWS) : saisie sur l'écran, « Enregistrer la date ».
- Sans `MAILER_DSN` réglé (ou `null://`), l'écran le dit : rien ne part.

### Historique des envois

- Une ligne par SMS ou e-mail tenté (table `envoi_message`) : date, canal, type (code de connexion de l'espace client, code de sécurité du personnel, notification au client, compte entreprise, rappel de paiement, commande à retirer, campagne, anniversaire, invitation à un sondage, accusé de demande du site, demande de conception, rapport de la semaine), objet de l'e-mail, destinataire, état (Envoyé, Échec, Non envoyé), erreur, unités (SMS comptés ; 1 par e-mail parti), envoyé depuis (ERP de la boutique ou site en ligne).
- **Jamais le texte du message** : ni code de connexion, ni code PIN, ni lien avec un jeton. L'erreur et l'objet sont nettoyés avant d'être écrits : numéros et adresses masqués, montants remplacés par « … ».
- Écriture : les SMS dans `OrangeSmsService::envoyerSms` (chaque appelant donne le type), les e-mails par un écouteur du Mailer (`JournalEmailsSubscriber`), au moment où le transport envoie ou échoue, une ligne par destinataire. La sorte de l'e-mail passe par un en-tête interne retiré avant l'envoi. La ligne est écrite tout de suite, directement en base ; une panne de l'historique n'empêche jamais un envoi.
- Filtres dans l'adresse (canal, type, état, origine, dates, recherche), pages de 50, « Export CSV » (mêmes filtres, même masquage ; 20 000 lignes au plus, indicatif écrit « 00223 » pour que le tableur n'y voie pas une formule).
- **Site en ligne :** il envoie aussi (codes de connexion, notifications de l'espace client). Ses lignes montent à l'ERP local par la synchronisation (type `envoi_message`, en lecture seule, docs/synchronisation.md) : la direction voit tout l'historique au même endroit, et le solde estimé ou les limites e-mail comptent les envois des deux machines (même contrat Orange, souvent la même boîte e-mail). L'écran du site en ligne ne montre que ses propres envois ; une saisie faite en ligne est proposée au local, comme les autres réglages.
- Les fiches de démonstration (DEMO-) ne reçoivent toujours rien : les appelants les écartent avant l'envoi. En développement et dans les tests, rien ne part (clés vides, `MAILER_DSN=null://null`, client HTTP simulé dans les tests).

### Alertes

- Une notification (la cloche) à la Direction et à qui a le droit « Crédits SMS et e-mail », **une seule fois par situation** : solde SMS sous le seuil (100 au départ), pack SMS ou abonnement e-mail qui finit dans moins de 15 jours (réglable) ou déjà fini. Rien de plus tant que la situation dure ; un nouveau pack (autre date de fin) ou un solde remonté puis redescendu est signalé de nouveau. Jamais de montant. Ce qui a été signalé est gardé dans le réglage `credits_messages_alertes`.
- Vérifiées par « Actualiser », par une saisie, et chaque heure par la tâche planifiée de l'ERP local :

```
0 * * * * cd /chemin/du/projet && php bin/console app:messagerie:credits -q >> var/log/messagerie-credits.log 2>&1
```

  Elle relit le solde chez Orange quand la lecture gardée a plus d'une heure. Sur le site en ligne, elle ne fait rien.

### Fichiers

- `src/Controller/CreditsMessagesController.php`, `templates/credits_messages/`, `public/css/credits-messages.css`.
- `src/Service/Messagerie/` : `CreditsMessages` (état SMS et e-mail, alertes), `SoldeOrange` (lecture Orange et cache), `JournalEnvois` (écriture de l'historique), `SegmentsSms`, `AlertesMessages` ; `src/EventSubscriber/JournalEmailsSubscriber.php` ; `src/Entity/EnvoiMessage.php` (migration `Version20261018093000`) ; `src/Command/CreditsMessagesCommand.php`.
- Recette des accès : `RecetteAcces::verifierCreditsMessages` (refus sans le droit, masquage sans le droit Clients, à l'écran et dans l'export ; destinataire d'un message au personnel masqué et introuvable par la recherche sans être la direction).

## Ordre proposé

1. **CH04 :** la messagerie admin pour le site et l'espace client. Elle sert de base à tout le reste.
2. **CH01 à CH03 :** la bulle sur le site, l'espace client et le mobile, avec l'assistant IA en J5.
3. **K02 puis K01 :** les codes promo et les fenêtres. Ils viennent après le moteur de promotions.
4. **WhatsApp et SMS** dans la même boîte, quand l'API Meta est branchée.
