# Messages des clients (maquettes MS01 à MS05)

Une seule messagerie entre les clients et l'atelier. Elle remplace l'ancien fil unique par client (EC01) : chaque demande est une **conversation**, liée à une commande, à un devis, ou « question générale ». L'atelier répond dans un délai fixé par la Direction ; un retard est imputé à la personne responsable et lui retire des points de prime.

## Côté client (espace en ligne)

`/espace/messages` (menu Messages, pastille rouge = réponses pas encore lues).

- **Liste** : onglets Ouvertes / Clôturées, recherche, une ligne par conversation avec sa vignette, le dernier message (petite photo ou initiales de l'agent), l'état (« Réponse attendue », « En impression », « Clôture proposée », « Résolue ») et la pastille non lu. Sur ordinateur, la première conversation s'ouvre à côté ; au téléphone, la liste d'abord (MS05).
- **Conversation** : en tête le titre (« CMD-D00769 · T-shirt imprimé… »), l'état de la commande, les agents qui ont écrit et « Voir la commande ». Dans le fil : séparateur du jour (« Jeudi 15 octobre · commande créée, acompte de 11 500 F reçu »), bulles des agents avec photo ou initiales, nom, métier, « répondu en 12 min », BAT joint, événements (« BAT validé à 10:55 · impression lancée », « Mariam a ajouté Seydou Diarra (graphiste PAO) à la conversation »), « Lu ✓✓ ».
- **Écrire** : texte seulement (1 000 caractères), trombone vers Fichiers et BAT. Sous la saisie : « Réponse en général sous 30 min, du lundi au samedi de 8 h à 18 h. Ici, on ne parle que de la commande … ». 10 messages par heure et par personne.
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
- **Saisie** : réponses rapides (texte modifiable avant l'envoi), bouton « Note interne », BAT et facture de la commande à joindre.
- **Actions** : Je la prends (non assignée), Modifier la commande, Transférer (le collègue devient responsable), + Collègue (le client voit l'événement), Clôturer la demande (proposée au client).
- **Colonne de droite** : fiche du client (type de compte, téléphone, nombre de commandes, points de fidélité), commande ou devis lié (étape, montant, payé, facture, agent), délai de réponse.
- Sur le site en ligne, ces pages se lisent seulement : on répond depuis l'ERP de la boutique.

**Qui voit quoi** (`src/Security/MessagerieVoter.php`) : la Direction voit tout. Les autres : celles qui leur sont assignées, celles où un collègue les a ajoutés, celles des commandes et devis dont ils sont l'agent, et les non assignées de leur rôle (caisse et commercial ; paiement et facture pour qui encaisse, facture, ou la trésorière). La PAO ne voit que celles où on l'a ajoutée.

## Règles de réponse et supervision (Direction)

Menu Clients : **Supervision des messages**, **Toutes les conversations**, **Règles de réponse** (Direction seulement).

- **Règles** (`/clients/messages/regles`, aussi tuile « Règles de réponse aux messages » des Paramètres) : 1re réponse 30 min ouvrées, suivantes 1 h, escalade à la Direction après 2 h, −5 points par réponse en retard, clôture automatique après 72 h sans réponse du client, réouverture 7 jours, responsable des paiements (trésorière choisie, sinon la caisse en poste).
- **Heures comptées** : celles des Paramètres › Horaires (lun – sam 8 h – 18 h, pause et jours fériés compris). Le chronomètre s'arrête hors de ces heures : un message envoyé samedi à 19 h compte à partir de lundi 8 h.
- **Responsable** : l'agent assigné à la commande ou au devis ; paiement / facture → la trésorière, sinon la caisse en poste ; une question générale reste « non assignée » jusqu'à ce qu'une personne de caisse réponde (elle la prend). Seuls la caisse, le commercial et la trésorière sont **imputables** : si un graphiste répond, le délai compte pour le responsable.
- **Supervision** (`/clients/messages/supervision`, MS04) : choix de la semaine, conversations, 1re réponse médiane (cible), réponses en retard, satisfaction (étoiles à la clôture) ; tableau par personne responsable (conversations, 1re réponse médiane avec la cible en trait noir, retards, retard cumulé, points) ; « En retard en ce moment » avec Relancer (notification à la personne, ou à la caisse si non assignée) ; export CSV.

## Points et primes

Chaque réponse en retard imputée retire les points de la règle (5 par défaut, enregistrés sur la réponse). Le critère de prime **« Réponses aux messages des clients »** note le mois : 100 moins les points retirés (`DelaisReponse::bilan`). Il est dans les barèmes par défaut de la caisse (10 %) et du comptable (10 %) ; la Direction peut l'ajouter aux autres rôles dans le barème. Voir `docs/immobilisations-primes.md`.

## Tâche planifiée

`php bin/console app:messagerie:delais`, sur l'ERP de la boutique, lancée avec la synchronisation chaque minute (`scripts/windows/synchro.bat`) : range les anciens messages dans leur conversation, prévient la Direction d'une demande qui attend au-delà de l'escalade, clôture les demandes restées sans réponse du client. Sous Linux : `* * * * * php bin/console app:messagerie:delais`.

## Synchronisation

- `conversation_client` (deux sens) : objet, sujet, état, dates de clôture, avis, lu. Le client ouvre, clôture, rouvre et note en ligne (remontées permises) ; responsable, collègues, chronomètre, escalade et relance restent au local.
- `message_client` : en plus du texte, la conversation, « répondu en X min », l'agent (signature), le BAT et la facture joints. La cible, le retard, la pénalité et la personne imputée restent au local.
- `agent_messagerie` (local → site) : nom, métier et une vignette de 96 px de la photo de l'employé qui a répondu (créée à sa première réponse). Sans photo : les initiales.
- `note_conversation` ne quitte jamais le local.

À la mise en service : `php bin/console doctrine:migrations:migrate`, puis sur l'ERP `php bin/console app:messagerie:delais` (range les anciens messages) et `php bin/console app:synchro --initialiser=agent_messagerie`, puis `--initialiser=conversation_client` et `--initialiser=message_client`.

## Pour les autres écrans (service réutilisable)

`App\Service\Messagerie\ResumeConversation::pour($commandeOuDevis, $sansMontants)` rend `null` (pas de conversation) ou : `conversation`, `statut` (« Ouverte », « Réponse attendue », « Clôturée »), `dernier` (texte, auteur, deLAtelier, le, photo, initiales), `delaiMinutes` et `delaiTexte` (« Répondu en 12 min »), `enAttente`, `attenteMinutes`, `enRetard`, `messages` (nombre), `urlAdmin`, `urlClient`. `urlClient($document)` donne la conversation, ou une nouvelle demande déjà réglée sur ce document.

Dans Twig : `conversation_resume(commande)` / `conversation_resume(devis, true)`, `conversation_url_client(document)`, filtres `|conversation_titre`, `|duree_messagerie` (72 → « 1 h 12 »), `|sans_montants`.

## Code

`src/Entity/ConversationClient.php`, `MessageClient.php`, `AgentMessagerie.php`, `NoteConversation.php` ; `src/Service/Messagerie/` (`Conversations`, `ReglesReponse`, `DelaisReponse`, `FilConversation`, `AgentsMessagerie`, `ResumeConversation`) ; `src/Controller/EspaceMessagesController.php` (client), `MessagesClientsController.php` (atelier) ; `src/Security/MessagerieVoter.php` ; `src/Twig/MessagerieExtension.php` ; commandes `app:messagerie:delais`, `app:messagerie:demo` ; migration `Version20261018173000`. Captures : `/mnt/project-files/captures/messagerie-commande/`.
