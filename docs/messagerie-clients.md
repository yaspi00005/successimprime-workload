# Messages des clients (maquettes MS01 à MS05)

Une seule messagerie entre les clients et l'atelier. Elle remplace l'ancien fil unique par client (EC01) : chaque demande est une **conversation**, liée à une commande, à un devis, ou « question générale ». L'atelier répond dans un délai fixé par la Direction ; un retard est imputé à la personne responsable et lui retire des points de prime.

## Côté client (espace en ligne)

`/espace/messages` (menu Messages, pastille rouge = réponses pas encore lues).

- **Liste** : onglets Ouvertes / Clôturées, recherche, une ligne par conversation avec sa vignette, le dernier message (petite photo ou initiales de l'agent), l'état (« Réponse attendue », « En impression », « Clôture proposée », « Résolue ») et la pastille non lu. Sur ordinateur, la première conversation s'ouvre à côté ; au téléphone, la liste d'abord (MS05).
- **Conversation** : en tête le titre (« CMD-D00769 · T-shirt imprimé… »), l'état de la commande, les agents qui ont écrit et « Voir la commande ». Dans le fil : séparateur du jour (« Jeudi 15 octobre · commande créée, acompte de 11 500 F reçu »), bulles des agents avec photo ou initiales, nom, métier, « répondu en 12 min », BAT joint, événements (« BAT validé à 10:55 · impression lancée », « Mariam a ajouté Seydou Diarra (graphiste PAO) à la conversation »), « Lu ✓✓ ».
- **Écrire** : texte (1 000 caractères) ou note vocale (3 min, voir « Notes vocales »), trombone vers Fichiers et BAT. Sous la saisie : « Réponse en général sous 30 min, du lundi au samedi de 8 h à 18 h. Ici, on ne parle que de la commande … ». 10 messages par heure et par personne.
- **+ Nouvelle demande** : au sujet d'une commande, d'un devis ou question générale (objet), et « c'est à propos de » (commande, paiement / facture, autre ; sinon deviné). Une commande qui a déjà une conversation ouverte la reprend.
- **Clôture (MS02)** : l'agent propose ; le client répond « Oui, c'est résolu » ou « J'ai encore une question » (écrire refuse aussi la clôture). Clôturée : lecture seule, étoiles (1 à 5), « Rouvrir » pendant 7 jours. Après, écrire au sujet de la même commande ouvre une **nouvelle demande liée** à l'ancienne.
- **Droits** : chaque compte ne voit que les conversations de son client. Un compte « Lecture · suivi » n'a pas Messages (comme avant). Un demandeur (sans droit payeur ni valideur) voit tout le fil mais les montants y sont masqués (« … »), dans les messages comme dans le séparateur du jour.
- **Page d'une commande** : la carte « Conversation de la commande » montre le dernier message, l'état et « Répondu en X min », un champ pour écrire et « Ouvrir la conversation ».
- « Une question sur une facture ? » › Message ouvre une nouvelle demande pré-remplie (sujet paiement, commande de la facture).

## Côté atelier (ERP local) — Clients › Messages

`/clients/messages` (MS03), pour la caisse, le commercial, la trésorière, la PAO et la Direction. Les livreurs seuls n'y ont pas accès.

- **Onglets** : Mes conversations (assignées à moi, où l'on m'a ajouté, ou de mes commandes et devis), En retard, Non assignées, Clôturées. Celle qui attend le plus passe devant.
- **Bandeau** : « En retard de 36 min — le client attend depuis 1 h 36 ouvrées (cible : 1 h). Imputé à Mariam Touré · −5 pts à la réponse », ou le temps qui reste.
- **Fil** : comme le client le voit, plus les **notes internes** (cadre jaune, invisibles du client, `@Mariam` prévient la personne citée). Le délai et le retard de chaque réponse sont écrits à côté.
- **Saisie** : réponses rapides (texte modifiable avant l'envoi), bouton « Note interne », « Note vocale » (au client ou interne), BAT et facture de la commande à joindre.
- **Actions** : Je la prends (non assignée), Modifier la commande, Transférer (le collègue devient responsable), + Collègue (le client voit l'événement), Clôturer la demande (proposée au client).
- **Colonne de droite** : fiche du client (type de compte, téléphone, nombre de commandes, points de fidélité), commande ou devis lié (étape, montant, payé, facture, agent), délai de réponse.
- Sur le site en ligne, ces pages se lisent seulement : on répond depuis l'ERP de l'imprimerie.

**Qui voit quoi** (`src/Security/MessagerieVoter.php`) : la Direction voit tout. Les autres : celles qui leur sont assignées, celles où un collègue les a ajoutés, celles des commandes et devis dont ils sont l'agent, et les non assignées de leur rôle (caisse et commercial ; paiement et facture pour qui encaisse, facture, ou la trésorière). La PAO ne voit que celles où on l'a ajoutée.

**Depuis une commande ou un devis** : la fiche commande (DT02) montre la carte « Conversation » (dernier message, délai, responsable, montants masqués sans le droit des paiements) ; « Message client » ou « Écrire au client » ouvre la conversation de la commande, créée au besoin et assignée à qui l'ouvre (`app_messages_clients_demarrer`). Le message saisi à l'envoi d'un devis et la demande de modification du client vont dans la conversation du devis.

## Notes vocales

Demande de Yaya (18/10/2026) : « C'est possible ajouter une note vocal pour les commandes ?? ». Une note vocale s'enregistre depuis le navigateur de l'ordinateur ou du téléphone et se range dans la conversation de la commande.

- **Où** : côté imprimerie, bouton « Note vocale » dans la conversation (sous la saisie, à côté de « Note interne ») et dans la carte « Conversation » de la fiche commande ; côté client, à côté du trombone dans sa conversation (icône micro au téléphone).
- **Enregistrer** : toucher le micro rouge pour démarrer, le toucher encore pour arrêter, ou le **maintenir appuyé** pendant qu'on parle (relâcher arrête). Chronomètre « 0:42 / 3:00 », arrêt tout seul à **3 minutes**. Ensuite : **réécoute** (lecteur et durée), puis « Envoyer la note », « Recommencer » ou « Annuler » (rien n'est envoyé).
- **Pour qui (imprimerie)** : « Envoyer au client » (une réponse comme une autre : elle arrête le chronomètre, compte pour le délai et prévient le client) ou « 🔒 Note interne (équipe seulement) » (cadre jaune, jamais vue du client, jamais synchronisée). Dans la conversation, le choix suit le bouton « Note interne » de la saisie ; sur la fiche commande, la note interne est cochée d'abord. Depuis la fiche, la conversation de la commande est ouverte au besoin, comme « Écrire au client ».
- **Client** : sa note part comme un message (même limite de 10 messages par heure, la demande clôturée depuis moins de 7 jours se rouvre) ; l'équipe en est prévenue comme d'un message écrit. Pas pour un compte « Lecture · suivi » (Messages lui est fermé).
- **Dans le fil** : un lecteur audio et la durée (« 🎤 0:42 ») ; dans la liste et les notifications, le texte « Note vocale · 0:42 ». La fiche commande montre les 3 dernières notes vocales (messages et notes internes) avec leur lecteur. Une note pas encore arrivée par la synchronisation s'affiche « en cours de réception ».
- **Contrôle du fichier (serveur)** : formats de l'enregistreur des navigateurs seulement, **type lu dans le contenu** (WebM de Chrome, Edge et Android ; Ogg de Firefox ; MP4 audio de Safari et de l'iPhone), jamais d'après le nom ou ce qu'annonce le navigateur ; une piste vidéo est refusée ; **10 Mo** au plus ; **durée lue dans le fichier** (1 s à 3 min, quelques secondes de marge pour l'arrêt) : dernière page Ogg, derniers blocs d'un WebM (Chrome n'écrit pas la durée), en-tête ou fragments d'un MP4. Nom de stockage choisi par le serveur (`<uuid>.webm|ogg|m4a`). L'enregistreur vise 32 kbit/s : 3 minutes font environ 0,7 Mo.
- **Stockage et écoute** : fichiers privés sous `var/uploads/vocales`, jamais sous `public/`. Ils sortent seulement par deux routes qui vérifient les droits de la conversation, pas seulement la connexion :
  - imprimerie : `GET /clients/messages/{conversation}/vocale/{message|note}/{id}` (`app_messages_clients_vocale_ecouter`) : `MESSAGERIE_VOIR` sur la conversation (MessagerieVoter : Direction, responsable, collègues ajoutés, agent de la commande, non assignées de son rôle ; la PAO seulement ajoutée ; jamais le livreur), et la note doit appartenir à cette conversation ;
  - client : `GET /espace/messages/{conversation}/vocale/{message}` (`app_espace_message_vocale_ecouter`) : sa conversation seulement, un message de cette conversation (jamais une note interne, jamais la note d'un autre client : « introuvable »).
  Réponse avec le type lu dans le contenu, `nosniff`, politique « sandbox », cache privé, lecture par morceaux (Safari).
- **Envoi** : `POST /clients/messages/{conversation}/vocale` (champ `mode` : `reponse` ou `note`), `POST /clients/messages/vocale/commande/{id}` (fiche commande, droit Commandes) et `POST /espace/messages/{conversation}/vocale` ; champ `audio`, jeton CSRF, réponse JSON. Sur le site en ligne, l'imprimerie n'envoie rien (lecture seule), comme pour les réponses écrites.
- **Micro** : l'en-tête `Permissions-Policy` permet le micro au site lui-même (`microphone=(self)`, docs/securite.md). Le navigateur ne donne le micro qu'à une page sécurisée (https, ou 127.0.0.1 en essai) ; sinon le bouton l'explique.
- **Synchronisation** : la fiche de la note voyage avec le message (`message_client` : nom de stockage, type, taille, durée, empreinte) ; le fichier suit par la liaison des fichiers (`FichiersSynchro`, comme les fichiers des clients et des BAT) : la note de l'atelier au client part au site, celle du client arrive au local (60 derniers jours), par morceaux, empreinte SHA-256 vérifiée, puis **revérifiée à l'arrivée au local** (type, taille, durée) ; refusée ou introuvable, elle est notée au journal et redemandée toutes les 6 heures. Les notes vocales internes ne quittent jamais le local. Avant cette fonction, les messages ne portaient aucun fichier (seulement des liens vers un BAT ou une facture) : rien d'autre n'a changé dans leur synchronisation.
- **Démonstration** : `app:messagerie:demo` ajoute une note vocale d'Oumar Guindo et une note interne vocale d'Aminata sur les t-shirts, et une réponse vocale de Mariam à Amadou Sow (`donnees-demo/note-vocale-demo.ogg`).

### Quand l'envoi échoue (19/10/2026)

L'enregistreur affiche la vraie cause au lieu de « vérifiez la connexion » (`NotesVocalesErreursSubscriber`) :
- base pas à jour : « lancez php bin/console doctrine:migrations:migrate -n » (personnel seulement) ;
- page ou session expirée : recharger la page ou se reconnecter ;
- pas le droit d'écrire dans la conversation, ou conversation introuvable ;
- autre erreur du serveur : notée dans `var/log`, avec le message pour le personnel. Le client ne voit jamais le détail technique.

« Vérifiez la connexion » ne reste que si la requête n'a pas pu partir.

## Règles de réponse et supervision (Direction)

Menu Clients : **Supervision des messages**, **Toutes les conversations**, **Règles de réponse** (Direction seulement).

- **Règles** (`/clients/messages/regles`, aussi tuile « Règles de réponse aux messages » des Paramètres) : 1re réponse 30 min ouvrées, suivantes 1 h, escalade à la Direction après 2 h, −5 points par réponse en retard, clôture automatique après 72 h sans réponse du client, réouverture 7 jours, responsable des paiements (trésorière choisie, sinon la caisse en poste).
- **Heures comptées** : celles des Paramètres › Horaires (lun – sam 8 h – 18 h, pause et jours fériés compris). Le chronomètre s'arrête hors de ces heures : un message envoyé samedi à 19 h compte à partir de lundi 8 h.
- **Responsable** : l'agent assigné à la commande ou au devis ; paiement / facture → la trésorière, sinon la caisse en poste ; une question générale reste « non assignée » jusqu'à ce qu'une personne de caisse réponde (elle la prend). Seuls la caisse, le commercial et la trésorière sont **imputables** : si un graphiste répond, le délai compte pour le responsable.
- **Supervision** (`/clients/messages/supervision`, MS04) : choix de la semaine, conversations, 1re réponse médiane (cible), réponses en retard, satisfaction (étoiles à la clôture) ; tableau par personne responsable (conversations, 1re réponse médiane avec la cible en trait noir, retards, retard cumulé, points) ; « En retard en ce moment » avec Relancer (notification à la personne, ou à la caisse si non assignée) ; export CSV.

## Points et primes

Chaque réponse en retard imputée retire les points de la règle (5 par défaut, enregistrés sur la réponse). Le critère de prime **« Réponses aux messages des clients »** note le mois : 100 moins les points retirés (`DelaisReponse::bilan`). Il est dans les barèmes par défaut de la caisse (10 %) et du comptable (10 %) ; la Direction peut l'ajouter aux autres rôles dans le barème. Voir `docs/immobilisations-primes.md`.

## Tâche planifiée

`php bin/console app:messagerie:delais`, sur l'ERP de l'imprimerie, lancée avec la synchronisation chaque minute (`scripts/windows/synchro.bat`) : range les anciens messages dans leur conversation, prévient la Direction d'une demande qui attend au-delà de l'escalade, clôture les demandes restées sans réponse du client. Sous Linux : `* * * * * php bin/console app:messagerie:delais`.

## Synchronisation

- `conversation_client` (deux sens) : objet, sujet, état, dates de clôture, avis, lu. Le client ouvre, clôture, rouvre et note en ligne (remontées permises) ; responsable, collègues, chronomètre, escalade et relance restent au local.
- `message_client` : en plus du texte, la conversation, « répondu en X min », l'agent (signature), le BAT et la facture joints. La cible, le retard, la pénalité et la personne imputée restent au local.
- `agent_messagerie` (local → site) : nom, métier et une vignette de 96 px de la photo de l'employé qui a répondu (créée à sa première réponse). Sans photo : les initiales.
- Note vocale jointe à un message : sa fiche part avec le message, le fichier suit (voir « Notes vocales »).
- `note_conversation` ne quitte jamais le local, notes vocales internes comprises.

À la mise en service : `php bin/console doctrine:migrations:migrate`, puis sur l'ERP `php bin/console app:messagerie:delais` (range les anciens messages) et `php bin/console app:synchro --initialiser=agent_messagerie`, puis `--initialiser=conversation_client` et `--initialiser=message_client`.

## Pour les autres écrans (service réutilisable)

`App\Service\Messagerie\ResumeConversation::pour($commandeOuDevis, $sansMontants)` rend `null` (pas de conversation) ou : `conversation`, `statut` (« Ouverte », « Réponse attendue », « Clôturée »), `dernier` (texte, auteur, deLAtelier, le, photo, initiales), `delaiMinutes` et `delaiTexte` (« Répondu en 12 min »), `enAttente`, `attenteMinutes`, `enRetard`, `messages` (nombre), `urlAdmin`, `urlClient`. `urlClient($document)` donne la conversation, ou une nouvelle demande déjà réglée sur ce document.

Dans Twig : `conversation_resume(commande)` / `conversation_resume(devis, true)`, `conversation_url_client(document)`, filtres `|conversation_titre`, `|duree_messagerie` (72 → « 1 h 12 »), `|sans_montants`.

## Code

`src/Entity/ConversationClient.php`, `MessageClient.php`, `AgentMessagerie.php`, `NoteConversation.php` ; `src/Service/Messagerie/` (`Conversations`, `ReglesReponse`, `DelaisReponse`, `FilConversation`, `AgentsMessagerie`, `ResumeConversation`) ; `src/Controller/EspaceMessagesController.php` (client), `MessagesClientsController.php` (atelier) ; `src/Security/MessagerieVoter.php` ; `src/Twig/MessagerieExtension.php` ; commandes `app:messagerie:delais`, `app:messagerie:demo` ; migration `Version20261018173000`. Notes vocales : `src/Entity/Trait/NoteVocaleTrait.php`, `src/Service/Messagerie/NotesVocales.php` et `AnalyseAudio.php`, `public/js/note-vocale.js`, `public/css/note-vocale.css`, gabarits `messages_clients/_note_vocale.html.twig` et `_lecteur_vocal.html.twig`, migration `Version20261018233000`, tests `tests/Unit/Service/Messagerie/NotesVocalesTest.php`. Captures : `/mnt/project-files/captures/note-vocale/`. Captures : `/mnt/project-files/captures/messagerie-commande/`.
