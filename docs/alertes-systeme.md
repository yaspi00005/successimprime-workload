# Alertes système

Demandé par Yaya le 18/10/2026 : « prévoir les alertes système sur le site et espace client à envoyer par mail et par l'app pour une prise en charge rapide ».

Quand quelque chose casse sur le site public, l'espace client ou l'ERP, une **alerte système** naît, part par **e-mail** et dans **l'application** (cloche et notification du téléphone, PWA), et se suit dans **Réglages › Alertes système** jusqu'à sa résolution.

## Ce qui fait une alerte

| Sorte | Quand | Qui la signale |
|---|---|---|
| Erreur du site (500) | une page du site public, de l'espace client ou de l'admin plante (statut 500 ou plus, hors accès refusé et connexion) | `AlertesExceptionSubscriber` (titre « Erreur 500 · espace client · route · classe ») |
| Paiement Orange Money | paiement non ouvert, rappel d'Orange refusé (jeton faux), argent reçu sans commande : tout avertissement ou erreur du journal qui commence par « Orange Money » | `AlertesMonologHandler` |
| Synchronisation | **en ligne** : l'ERP de l'imprimerie ne s'est pas manifesté depuis 30 minutes ; **au local** : la synchronisation est en panne (même règle que l'alerte de l'écran Synchronisation, `Synchro::alerte`) | `VerificationsSysteme` (app:alertes) |
| Envoi d'e-mail ou de SMS | un e-mail n'est pas parti (Mailer), « SMS Orange » en erreur au journal | `AlertesEnvoiEmailSubscriber`, `AlertesMonologHandler` |
| Sauvegarde | au local : dernière sauvegarde ratée, aucune sauvegarde, sauvegarde en retard, copie sur le second disque en échec (docs/sauvegardes.md) | `VerificationsSysteme` |
| Espace disque | moins de place libre que le seuil réglé (2 Go par défaut) sur le disque du site ou des sauvegardes ; critique sous le quart du seuil | `VerificationsSysteme` |
| File de messages | messages en échec dans `messenger_messages`, ou messages en attente depuis plus de 15 minutes | `VerificationsSysteme` |
| Tâche planifiée | une commande lancée par le planificateur (pas tapée dans un terminal) s'arrête sur une erreur | `AlertesMonologHandler` |
| Autre erreur notée | toute autre erreur écrite au journal du serveur (avertissement si `error`, critique au-delà) | `AlertesMonologHandler` |
| Alerte d'essai | bouton « Envoyer une alerte d'essai » des réglages, ou `app:alertes --essai` | |

Les canaux `request`, `deprecation`, `php`, `event`, `security`, `mailer`, `cache`, `translation` du journal sont ignorés (déjà signalés ailleurs ou sans intérêt), ainsi que les messages des alertes elles-mêmes (« Alertes système : … »).

Les vérifications (synchronisation, sauvegarde, disque, file) **se résolvent toutes seules** quand la situation revient à la normale (« Revenue à la normale toute seule » avec le texte de la vérification).

## Regroupement et rythme des envois

- **Une même panne = une alerte** : même sorte et même empreinte (sha1 de ce qui la reconnaît : classe, fichier et ligne de l'erreur, route, message sans ses nombres ni identifiants). Tant qu'elle n'est pas résolue, son **compteur** monte (« 7 fois, du … au … ») au lieu d'en créer une autre. Résolue puis revenue : une nouvelle alerte s'ouvre.
- **Au plus un e-mail par sorte toutes les 15 minutes** (délai réglable de 1 à 1 440 minutes). Ce qui arrive pendant le délai part dans l'e-mail suivant, qui liste toutes les alertes nouvelles de la sorte. Une alerte résolue pendant le délai ne part plus.
- L'envoi se fait à la fin de la requête ou de la commande qui a fait naître l'alerte, et toutes les 5 minutes par `app:alertes` (qui rattrape ce qui attendait la fin du délai).
- La cloche reçoit une notification par personne et par envoi : l'alerte elle-même s'il n'y en a qu'une, sinon « N alertes système · sorte » vers la liste. La notification part aussi sur le téléphone (PWA) comme les autres.

## Ce que contient l'e-mail

Objet « [Succès Imprim] Alerte système AL-000042 : sorte » (ou « [Succès Imprim] 3 alertes système : sorte »), puis pour chaque alerte : référence (`AL-000042` au local, `W-AL-000042` en ligne), gravité, sorte, titre, nombre de fois et dates, origine, et le lien « Prendre en charge » vers l'écran (connexion demandée). **Jamais le détail technique, de mot de passe, de clé, de jeton, de numéro de téléphone, d'adresse e-mail ni de donnée de client** : le titre est construit sans les valeurs du message, le détail ne se lit que sur l'écran.

Le détail technique (classe, message, page, fichier, pile d'appels courte) est **nettoyé** avant d'être écrit (`NettoyageAlerte`) : identifiants des DSN, paramètres des adresses, jetons `Bearer`/`Basic`, `password=`, `token=`, `secret=`…, longues chaînes hexadécimales ou base64, numéros de téléphone et adresses e-mail sont masqués.

Les e-mails passent par l'historique des envois (type « Alerte système (personnel) », docs/messagerie-marketing.md). Sans `MAILER_DSN` (ou `null://`), sans `MAILER_FROM_ADDRESS` ou sans aucune adresse, rien ne part par e-mail : l'alerte reste dans la cloche et sur l'écran.

## Qui la reçoit

- Par défaut : toutes les personnes actives qui ont la **Direction** (`ROLE_ADMIN`) ou le droit **« Alertes système »** (`ROLE_ALERTES_SYSTEME`, à donner au technicien dans Réglages › Utilisateurs et droits, droits détaillés), à l'adresse e-mail de leur fiche employé.
- Les réglages permettent de choisir, **sorte par sorte**, e-mail oui ou non, application oui ou non, et des personnes précises (sinon tout le monde ci-dessus).
- **Adresses en plus** (10 au plus) dans les réglages, et `ALERTES_EMAIL` dans `.env.local` (une ou plusieurs adresses séparées par des virgules) : elles reçoivent tous les e-mails, en plus.
- Par défaut tout part par e-mail et dans l'application, **sauf la synchronisation au local, par e-mail seulement** : la cloche des administrateurs la montre déjà (AgentSynchro).

## Site en ligne et ERP local

Les deux installations ont chacune leur base, leurs personnes et leurs réglages.

- Une alerte née **en ligne** est envoyée **par e-mail directement depuis le serveur en ligne** (à ses comptes Direction et « Alertes système », aux adresses en plus et à `ALERTES_EMAIL`), sans attendre l'imprimerie. Elle **monte aussi au local** par la synchronisation (type `alerte_systeme`, sens ascendant, docs/synchronisation.md) : elle y arrive « ouverte » et « à prévenir », la cloche et le téléphone sonnent, mais **l'e-mail n'est pas renvoyé**. Le compteur et la dernière fois remontent ensuite ; un compteur qui monte sur une alerte résolue au local la rouvre.
- La prise en charge et la résolution restent de chaque côté (rien ne redescend).
- Si la base en ligne est elle-même hors service, l'alerte ne peut pas être écrite : elle reste dans le journal du serveur (`var/log/prod.log`). Si c'est l'imprimerie qui ne se manifeste plus, c'est le serveur en ligne qui prévient (« L'imprimerie ne se synchronise plus avec le site »).

## Écran Réglages › Alertes système

`/reglages/alertes-systeme`, Direction et droit « Alertes système ». Le menu Réglages a l'entrée « Alertes système ».

- Onglets **En cours** (ouvertes puis prises en charge), **Résolues**, **Toutes**, et un filtre par sorte ; les 200 dernières. Chaque ligne : gravité, référence, titre, sorte, nombre de fois, dernière fois, origine, qui l'a prise. Bouton **« Je prends »** sur la ligne.
- Page d'une alerte : en-tête (référence, état, gravité, sorte, origine, première et dernière fois), le **détail technique** nettoyé, « Je prends », « Résoudre » avec une **note de résolution** (ce qui a été fait), « Rouvrir » sur une alerte résolue.
- **Réglages** (Direction seulement) : délai entre deux e-mails d'une même sorte, seuil d'espace disque, adresses en plus, et pour chaque sorte e-mail, application et destinataires ; la liste des personnes sans adresse e-mail est signalée ; bouton « Envoyer une alerte d'essai ».
- Tous les gestes sont des formulaires POST avec jeton CSRF ; en dehors de la Direction et du droit, l'écran répond « accès refusé ».

## Tâche planifiée

`php bin/console app:alertes` toutes les 5 minutes, **sur les deux serveurs** :

- serveur en ligne : `*/5 * * * * cd /srv/successimprim && php bin/console app:alertes -q >> var/log/alertes.log 2>&1` (docs/deploiement.md) ;
- ERP local sous Windows : `scripts\windows\installer_tache_alertes.bat` (clic droit, « Exécuter en tant qu'administrateur »), qui installe la tâche « SuccessImprim_Alertes ».

Options : `--essai` crée une alerte d'essai, `--sans-verification` n'envoie que ce qui attend. La commande oublie aussi les alertes résolues depuis plus de 90 jours.

## Sécurité, sans boucle

- Une alerte ne casse jamais ce qui l'a fait naître : tout est rattrapé, l'alerte est écrite en base directement (hors du gestionnaire d'entités, que l'erreur a pu fermer).
- Un verrou empêche une alerte de naître pendant qu'on en écrit ou en envoie une : un e-mail d'alerte qui échoue ne fait pas d'autre alerte en boucle.
- Rien n'est signalé dans l'environnement de test.

## Fichiers

- `src/Entity/AlerteSysteme.php`, `src/Repository/AlerteSystemeRepository.php`, migration `Version20261018177000`.
- `src/Service/Alertes/` : `AlertesSysteme` (signaler, envoyer), `ReglagesAlertes` (ligne `alertes_systeme` de la table `reglage`), `NettoyageAlerte`, `VerificationsSysteme`, `AlertesMonologHandler` (branché dans `config/packages/monolog.yaml`).
- `src/EventSubscriber/AlertesExceptionSubscriber.php`, `AlertesEnvoiEmailSubscriber.php`, `src/Command/AlertesCommand.php`, `src/Controller/AlertesSystemeController.php`.
- `templates/alertes_systeme/`, `public/css/alertes-systeme.css`.

## Essayé le 18/10/2026

- Page qui plante en ligne : alerte `W-AL-…` créée, origine « site en ligne », journal de synchronisation noté ; quatre plantages de la même page = une alerte « 4 fois » ; détail sans le mot de passe du DSN, ni jeton, ni téléphone, ni e-mail.
- Délai : deux alertes de la même sorte en moins de 15 minutes, un seul e-mail ; la seconde part au passage suivant.
- Montée au local : la copie arrive « à prévenir », la cloche sonne, pas de second e-mail ; un compteur qui remonte sur l'alerte résolue au local la rouvre.
- Serveur de messagerie injoignable : l'envoi échoue, une alerte « Envoi d'e-mail » au plus, pas de boucle.
