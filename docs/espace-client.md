# Espace client connecté (maquettes 13 à 19)

Le client se connecte avec son numéro et son mot de passe (CX01, voir « Connexion » plus bas), puis arrive dans son espace `/espace`. Il ne voit que sa propre fiche : chaque page passe par `src/Service/EspaceClient/EspaceClient.php`, qui filtre sur le client du compte connecté, et répond « introuvable » pour la commande, le BAT, le fichier, le devis ou la facture d'un autre client. Pas de coûts, de marges ni de noms d'employés.

## État (13/10/2026)

Construit, téléphone d'abord (onglets en bas), menu à gauche sur ordinateur :

- **Accueil (13)** : bandeau des BAT à valider, commandes en cours avec la frise Reçue › BAT › Impression › Finition › Prête, reste à payer avec le bouton Orange Money, devis à accepter, commandes récentes.
- **Mes commandes et suivi (15)** : liste avec état et avancement ; détail avec la frise datée (commande reçue, BAT validé par vous, impression, finition, prête à retirer ou à livrer, retirée ou livrée), livreur en route, montant et reste à payer, lien WhatsApp vers l'atelier (téléphone de la première entité).
- **Mes devis (14, à droite)** : filtres Tous, À accepter, Acceptés ; « Accepter » passe le devis envoyé en accepté et prévient le droit Devis ; PDF par le lien public signé.
- **Fichiers et BAT (16, à droite)** : BAT à valider et précédents ; page du BAT avec l'aperçu (image ou PDF) de la dernière version préparée par l'atelier, la frise des versions et des demandes du client. « Valider le BAT » note le BAT validé (horodaté, frise de conception, points du graphiste) et remet le contrôle à la prépresse pour la validation des fichiers et l'envoi en production ; « Demander une modification » repasse le contrôle en correction avec le texte du client. Sur un BAT en image, le client touche l'image pour poser des repères numérotés (10 au plus), chacun avec ce qu'il faut changer ; le graphiste les voit sur l'aperçu de son poste (R03) et dans le fil des échanges, et la version suivante les affiche au client avec « corrigé en version N » (maquette 16). Sur un PDF, la demande reste en texte. La prépresse est prévenue dans les deux cas.
- **Factures et paiement (17)** : solde à payer par commande, factures émises (PDF signé, proforma marquée), page de paiement Orange Money (voir plus bas). Tant que les clés Orange Money ne sont pas posées, la page donne le numéro marchand (tuile Entités) et la référence de commande ; le paiement est validé par la caisse.
- **Mon compte et notifications (19)** : coordonnées (modifiées par le bureau), réglage SMS, mot de passe, déconnexion ; fil des notifications reconstruit à partir des commandes (BAT prêt, paiement reçu, impression lancée, commande prête, livreur en route, livrée).
- **Fidélité (18, à droite, construit le 14/10/2026)** : `/espace/fidelite`, entrée Fidélité du menu, pour un client hors grille revendeur (`typeClient` autre que B2B) et une personne qui paie. Solde en points et en francs, progression vers chaque seuil, points qui expirent bientôt, promo de réveil, rappel du ×2 payé le jour même, historique (type, points, date, commande ; ni agent ni motif). Lecture seule : c'est l'atelier qui utilise les points sur une commande. Un client à la grille revendeur (B2B) n'a pas l'entrée : le programme ne s'applique pas à cette grille de prix. Détails dans `docs/fidelite.md`.

- **Nouvelle commande (14, à gauche)** : produits du catalogue publié (configurations actives avec un prix), largeur et hauteur en cm quand le produit se vend au m² ou sur mesure, finitions proposées (les obligatoires sont incluses), quantité, fichier choisi parmi les siens ou envoyé sur place, précisions pour l'atelier. Le total TTC indicatif est recalculé à chaque changement avec les prix du comptoir (prix B2B pour un client B2B) ; la commande arrive en brouillon « Reçue · à confirmer » (10 en attente au plus par client), sans paiement possible tant que l'atelier ne l'a pas confirmée. Au local, la fiche commande affiche « Commande passée par le client » avec le bouton Vérifier et confirmer.
- **Mes fichiers (16, à gauche)** : envoi PDF, AI, EPS, PSD, JPG, PNG, TIFF jusqu'à 500 Mo, par morceaux de 4 Mo repris après une coupure ; le type réel est vérifié (un fichier renommé est refusé), 5 Go par client. Contrôle automatique à l'arrivée (`AnalyseFichierImpression`) : résolution à la taille prévue si le client la donne, couleurs, polices, fonds perdus (3 mm, petits formats), proportions ; résumé OK, Attention ou À refaire. Mes logos (20 Mo au plus) gardés pour les prochaines commandes. Un fichier se retire tant qu'il n'est pas dans une commande. Les fichiers sont rangés hors de `public/` et servis seulement à leur client.

## Connexion (CX01, revue le 14/10/2026)

Le client se connecte avec **son numéro et son mot de passe**. La première fois, ou s'il a oublié son mot de passe, il passe par un code. Deux chemins, qui finissent au même endroit :

| | Code par e-mail ou SMS (le client seul) | Code d'accès de la boutique (WhatsApp) |
|---|---|---|
| Où | « Recevoir un code » sur `/espace/connexion`, ou « Créer mon compte » | `/espace/premiere-connexion`, lien du message WhatsApp |
| Le code | 6 chiffres, 5 minutes, 5 essais | 8 caractères (sans 0, O, 1, I ni L), 48 heures, une seule fois |
| Envoyé | par le site, à l'e-mail du compte ou de la fiche, sinon par SMS | par l'équipe, depuis la fiche client (ERP local) |
| Ensuite | mot de passe obligatoire s'il n'y en a pas (proposé sinon), e-mail proposé à qui n'en a pas | mot de passe obligatoire ; e-mail proposé, facultatif (décision de Yaya du 15/10 : sans e-mail, un mot de passe oublié passe par la boutique ou par SMS) |

**Code par e-mail ou SMS** (`CodeDeConnexion`, `EspaceClientComptes`) :

- Le code part à l'e-mail de la personne (Mon compte › Sécurité), sinon, pour le titulaire (le numéro de la fiche), à l'e-mail de la fiche client, sinon par SMS. Une personne ajoutée à un compte entreprise ne reçoit jamais ses codes à l'adresse de l'entreprise : sans e-mail à elle, c'est le SMS.
- « Créer mon compte » demande l'e-mail (conseillé). Si le numéro est déjà celui d'une fiche ou d'un compte, le code part à l'adresse ou au numéro de cette fiche, **jamais à l'adresse tapée**. Un nouveau client reçoit le code à l'adresse tapée, notée ensuite sur sa nouvelle fiche.
- L'écran dit où le code est parti, masqué (« y•••@gmail.com », « +223 •• •• 42 12 »). Un numéro inconnu reçoit la même réponse, sans que rien parte.
- Le message donne le code, sa durée et l'avertissement de ne le donner à personne. Jamais de montant ni de lien qui connecte. Les fiches `DEMO-` ne reçoivent rien.
- Limites (`rate_limiter.yaml`) : 3 codes par numéro et par adresse e-mail en 15 minutes, 10 par adresse IP en une heure, quel que soit le moyen. Hors production seulement, un code qui n'a pas pu partir s'affiche à l'écran.
- Sans e-mail réglé sur le site (`MAILER_DSN`, `MAILER_FROM_ADDRESS`, voir `docs/deploiement.md`), les codes partent par SMS. Un e-mail refusé par le serveur d'envoi passe aussi au SMS.
- Paramètres › Espace client › « Code de première connexion et de mot de passe oublié » coupe ce chemin ; le code d'accès de la boutique reste possible.

**Code d'accès de la boutique** (`AccesEspace`, `AccesEspaceClient`) :

- Fiche client (ERP local) › **« Envoyer l'accès à l'espace client »**, pour le droit Clients (`ROLE_CLIENT`). Caché et refusé en « Voir », absent pour une fiche `DEMO-`, et il faut un numéro malien sur la fiche. À la création d'une fiche, la case « Inviter à l'espace client par WhatsApp » met le même bouton en haut de la fiche.
- La page qui suit montre le message et « Ouvrir WhatsApp » (lien wa.me vers le numéro de la fiche) : l'identifiant (le numéro), le code, sa validité et le lien de la page « Première connexion », numéro rempli. Le lien prend l'adresse du site `SYNC_URL` ; **jamais le code dans un lien**. Le code ne s'affiche qu'une fois (page non gardée par le navigateur).
- Seule l'empreinte est gardée (sha256 du numéro et du code). Elle descend sur le site avec l'échéance (synchronisation `acces_espace`). Un nouvel accès remplace le précédent, qui ne sert plus. Le journal de la fiche note qui a envoyé l'accès et quand, jamais le code ; la fiche montre le dernier envoi et son échéance.
- Il sert aussi au **mot de passe oublié passé par la boutique** : un compte qui a déjà un mot de passe doit en choisir un nouveau.
- Entreprise : l'accès va au numéro de la fiche (la personne principale). Elle invite les autres depuis Mon compte.
- Chaque essai compte dans les mêmes limites que les codes (3 par numéro en 15 minutes, 10 par adresse IP en une heure). Faux, expiré, déjà servi ou remplacé : un seul message. Refusé aussi si le numéro de la fiche a changé depuis, ou si ce numéro a déjà un compte fermé ou sur une autre fiche : à régler par la boutique.

**Sécuriser mon compte** (`/espace/securiser`) :

- Tant qu'une étape obligatoire reste à faire, les autres pages de l'espace renvoient ici ; seule « Se déconnecter » reste ouverte.
- L'e-mail donné par le titulaire d'une fiche sans e-mail va aussi sur la fiche : il monte au local, qui ne le prend que si sa fiche n'en a toujours pas. Il se change dans Mon compte › Sécurité (mot de passe actuel demandé). Une adresse ajoutée ici n'est pas vérifiée par un code ; seule celle d'un nouveau client l'est (il y a reçu son code).
- Changer de mot de passe déconnecte les autres appareils restés connectés (cookies « rester connecté » signés avec le mot de passe).

Le personnel de l'administration en ligne reçoit son code de sécurité de la même façon (e-mail de l'employé, sinon SMS) : voir `docs/deploiement.md`.

## Coque, tableau de bord, notifications et Mon compte (maquettes EC01 à EC05, construit le 14/10/2026)

Code : `src/Service/EspaceClient/CoqueEspace.php` (menu, pastilles, onglets, cloche, conseiller), `FilNotifications.php`, `PreferencesNotifications.php`, `NotificationsClient.php`, `MonCompte.php`, `AppareilsClient.php`, `MessagesClient.php`, `PropositionsClient.php`, `RecommanderClient.php`, `src/Security/DroitsEspace.php`, `src/EventSubscriber/EspaceClientSubscriber.php`. Migration `Version20261014142129`. Captures : `/mnt/project-files/captures/espace-client/ec-*.png`.

**Coque (EC01, EC05).** Sur ordinateur : menu à gauche, barre du haut (recherche, « + Nouvelle commande », cloche, personne et fonction), carte « Votre conseiller(ère) » (nom ; WhatsApp et appel vers le numéro de la boutique, jamais celui de l'employé). Sur téléphone : en-tête (entreprise, « Bonjour Prénom », cloche) et cinq onglets (Accueil, Commandes, Fichiers, Factures, Compte).

- Pastilles : rouge pour les notifications non lues ; jaune pour les devis à accepter (valideur), les BAT en attente et les commandes à régler (payeur).
- Le menu est la liste `CoqueEspace::MENU` : une entrée = une ligne (route, libellé, icône, pages qui l'allument, qui la voit, onglet du téléphone). Factures et paiements : payeur seulement. Fidélité : payeur d'un client hors grille revendeur. Nouvelle commande, Mes produits, Mes devis, Messages : pas pour un compte Lecture · suivi.
- La recherche de la barre du haut (`/espace/recherche`) cherche côté serveur dans les commandes, devis, factures et fichiers **du client seulement**, selon les droits : pas de devis en Lecture · suivi, pas de factures sans le droit payeur.

**Tableau de bord (EC01, EC05).** Cartes d'action (BAT à valider, paiement en retard ou du jour, devis à accepter, demandes à valider), commandes en cours avec la frise en 5 étapes (Commande, BAT, Impression, Finition, Livraison ou Retrait), activité récente, « Mon compte en un coup d'œil » pour qui paie (solde dû et part échue, plafond de crédit utilisé ou dépassé, commandes de l'année, points fidélité hors grille revendeur), « Recommander rapidement » (derniers articles au prix recalculé par le serveur au jour même ; « + Panier » ouvre la commande pré-remplie, rien n'est commandé d'un clic), bandeau fidélité. Un compte Lecture · suivi voit le suivi et une carte qui explique son accès.

**Notifications (EC02, EC03, EC05).**

- La cloche ouvre les dernières notifications ; sans JavaScript, c'est un lien vers la page. Page `/espace/notifications` : filtres par catégorie avec leur nombre, groupes par jour, pastille de type, boutons d'action selon les droits (valider le BAT, accepter le devis, payer, suivre la livraison, lire le message), « Marquer comme lu », « Tout marquer comme lu » ; sur téléphone, glisser à gauche marque comme lu.
- L'état lu est **propre à chaque personne et tenu en ligne seulement** (table `notification_client_lue`, et `compte_client.notifications_lues_jusqua` pour « Tout marquer comme lu ») : il ne passe pas au local. Un événement non lu de plus de 14 jours n'est plus « nouveau ».
- Catégories et montants suivent les droits : factures et paiements seulement pour qui paie ; production et livraison seulement pour un compte Lecture · suivi. Les appels du conseiller ne sont pas dans le fil : rien des notes d'appel ne quitte le local.
- **« Comment je suis prévenu »** : grille App, WhatsApp, SMS, E-mail par catégorie (BAT, Production, Livraison, Factures, Devis, Messages, Offres), propre à chaque personne (`compte_client.preferences_notifications`). Par défaut : le SMS (payant) seulement pour le titulaire, et pour BAT, Livraison et Messages ; la production dans l'application seulement ; Offres décochée. Cocher une case Offres vaut accord du client pour les offres (`consentementMarketingLe`, remonté au local par la liste blanche existante). Les factures ne vont jamais à quelqu'un qui ne paie pas, quelles que soient les cases.
- **Heures calmes** : ni SMS ni WhatsApp de 21 h à 7 h. Le SMS est mis de côté (`notification_client_differee`) et part à 7 h, sauf la livraison du jour, qui part tout de suite. Il est abandonné si la personne a décoché la case entre-temps ou si l'événement a plus de 12 heures.
- Chaque personne est prévenue sur **son propre numéro**, selon ses cases. Les fiches de démonstration (`DEMO-`) ne reçoivent jamais de SMS ni d'e-mail, et aucun message ne contient de montant.
- **WhatsApp** : le choix est enregistré, mais rien ne part tant que l'atelier n'a pas branché WhatsApp ; la page le dit.
- **« Qui reçoit quoi »** (pour la personne qui gère le compte) : ce que reçoit chaque personne du compte.
- **Tâche planifiée du site en ligne, toutes les 15 minutes** : `php bin/console app:espace:notifications` envoie les SMS mis de côté pendant les heures calmes et les rappels d'échéance des factures (une fois chacun, sans montant). Elle refuse de tourner sur l'ERP local. Ligne de crontab dans `docs/deploiement.md`.

**Mon compte (EC04).**

- **Entreprise** : raison sociale et NIF modifiables par la personne qui gère le compte, mais **jamais appliqués en ligne**. La demande devient une proposition (`proposition_client`) qui monte au local ; l'équipe Clients est prévenue et l'applique ou la refuse depuis la fiche client (bloc « Changement demandé par le client »). Appliquée, elle passe par le journal des modifications de la fiche (« depuis l'espace client ») et la fiche redescend ; refusée, le motif se lit en ligne pendant 30 jours.
- « Facturé par » (entité de facturation) et « Conditions » (délai de paiement) sont en lecture. Les conditions et la phrase « Les conditions commerciales sont fixées avec votre conseillère » ne se montrent qu'à la personne qui gère le compte et à qui paie.
- **Adresses de livraison** et **Utilisateurs du compte** : « + Inviter » avec le nom, le numéro, la fonction (Intendante, Comptabilité, Réception…) et les droits. La personne invitée reçoit un SMS et se connecte avec son propre numéro (« Recevoir un code », puis elle choisit son mot de passe).
- **Sécurité** : numéro masqué (+223 6X XX XX XX), appareils connectés (regroupés par type, celui-ci en premier), dernière connexion, « Déconnecter les autres appareils », « Télécharger mes données », changement de mot de passe.
- **Déconnecter les autres appareils** augmente `compte_client.version_session`. Les autres sessions tombent à leur requête suivante et leurs cookies « rester connecté » sont refusés (`signature_properties: [password, versionSession]` dans `security.yaml`). Au déploiement, les cookies « rester connecté » déjà posés ne valent plus : chaque client se reconnecte une fois.
- **Télécharger mes données** (`/espace/compte/mes-donnees`, JSON) : seulement ce que la personne voit déjà dans son espace, selon ses droits (ni factures ni montants sans le droit payeur, pas de devis en Lecture · suivi), sans aucun champ interne.
- Un **particulier** a la même page sans les parties entreprise : Mes coordonnées, adresses, sécurité.

**Lecture · suivi (EC04).** Droit `lecture` d'un compte entreprise, proposé dans le formulaire d'invitation et le résumé des droits ; il remplace les autres droits. La personne suit les commandes, les livraisons et les fichiers. Elle ne commande pas, ne valide ni BAT ni devis, ne paie pas, n'écrit pas de message, n'ajoute personne et ne modifie rien. C'est **vérifié côté serveur à chaque requête** (`DroitsEspace`, appelé par `EspaceClientSubscriber`), en plus des contrôles de chaque page :

- tout envoi est refusé (403), sauf ce qui ne regarde qu'elle : marquer lu, ses préférences, son mot de passe, ses appareils ;
- les pages qui ne servent qu'à commander, payer ou accepter la renvoient au tableau de bord.

**Livraisons.** `/espace/livraisons` : en route, à venir, livrées, à partir des bons de livraison synchronisés (créneau, heure de départ, heure de remise).

**Messages.** `/espace/messages` : fil entre le client et l'atelier.

- Texte seulement (1 000 caractères), une commande en sujet si on veut, 10 messages par heure et par personne (`espace_message_compte` dans `rate_limiter.yaml`).
- Le message monte au local, où le droit Clients (`ROLE_CLIENT`) est prévenu. L'équipe répond depuis **Clients › Messages**, avec une pastille sur le menu Clients pour les messages non traités.
- La réponse redescend et prévient le client (« Réponse de l'atelier », sans montant). Le traitement et l'employé qui a répondu restent au local.

**Données de démonstration** : voir « Espace client de démonstration » dans `docs/donnees-demo.md`.

## Comptes entreprise (19, construit le 13/10/2026)

Une fiche entreprise ou B2B peut avoir plusieurs personnes, chacune avec son numéro et ses droits (`CompteClient.droits`). Un particulier n'a qu'un compte et fait tout. Code : `src/Service/EspaceClient/CompteEntreprise.php`, `src/Controller/EspaceEntrepriseController.php`.

| Droit | Ce qu'il permet |
|---|---|
| Administrateur (« gère le compte ») | tout, plus ajouter ou retirer des personnes, changer leurs droits, gérer les adresses |
| Valideur | accepter ou refuser les demandes, accepter les devis, voir les prix |
| Payeur | voir l'onglet Factures, le solde, le reste à payer, payer |
| Aucun (demandeur) | commander, sans voir aucun prix ; la commande attend un valideur |
| Lecture · suivi | suivre les commandes, les livraisons et les fichiers, rien d'autre (voir plus haut) |

**Qui voit les montants** (une seule règle, `CompteClient::montantsVisibles()`, lue par les gabarits via `coque.montants`, par les appels JSON et par l'atelier 3D) : un particulier voit tout ; dans une entreprise, le payeur et le valideur voient les prix (total d'une commande, devis et son PDF, « Recommander rapidement », Mes produits, Nouvelle commande, tarifs de la conception), le payeur seul le solde, les factures, le reste à payer et le crédit. Un demandeur et la lecture seule ne reçoivent aucun montant, ni dans la page ni dans les réponses JSON : ils voient les quantités et « prix confirmé par votre valideur ». La recette des accès (`app:recette:acces`) le contrôle.

- **Mon compte** : profil de la personne (son nom, son rôle), coordonnées de l'entreprise, ligne « Adresses de livraison », carte « Utilisateurs du compte » avec « + Ajouter ». La personne ajoutée reçoit un SMS et se connecte avec son propre numéro. 20 personnes au plus ; il reste toujours au moins un administrateur ; on ne change pas ses propres droits ; le compte principal ne se retire pas en ligne.
- **Adresses de livraison** : nom court, adresse, repère, personne à appeler ; une adresse par défaut, présélectionnée en commandant (« Livrer : Siège » ou « Retrait en boutique »).
- **Nouvelle commande** : choix de l'adresse, n° de bon de commande (imprimé sur la facture). Pour un demandeur, le bouton devient « Envoyer la demande » et les valideurs reçoivent un SMS sans montant.
- **Validation** : l'accueil du valideur montre « N demandes à valider » ; la page de la commande propose « Valider et envoyer à l'atelier » ou « Refuser » avec un motif lu par le demandeur. Sans aucun valideur actif, la demande part directement.
- **Synchronisation** : la demande monte au local sans prévenir l'atelier. La décision du valideur remonte (`validationEntreprise`, `valideePar`, `motifRefusEntreprise`) : validée, le droit Commande est prévenu comme pour toute commande en ligne ; refusée, le local la classe (supprimée) et le site la garde visible au client avec son motif. Au local, la fiche commande affiche « demandée en ligne par X » et le bon de commande.
- Les demandes à valider ou refusées n'apparaissent pas dans « À confirmer » (API comprise).
- **Solde d'ouverture** (créance reprise à l'import, docs/import-donnees.md) : comme tout montant de l'entreprise, seul le payeur le voit, dans Factures et paiements (« Solde d'ouverture · Montant dû au démarrage du compte ») et le solde dû ; il peut le payer en ligne. Il n'est ni dans le fil des commandes ni dans les notifications.

## Réclamations (18 à gauche, construit le 13/10/2026)

Le client signale un souci sur un travail depuis Mon compte › Réclamations, ou depuis une commande confirmée (« Un problème avec cette commande ? »). Code : `src/Service/EspaceClient/ReclamationsClients.php`, entités `ReclamationClient` et `ReclamationClientPhoto` (à ne pas confondre avec `Reclamation`, qui sert aux réclamations des employés).

- **Côté client** : commande concernée (facultative), motif (couleur, finition, quantité, texte, retard, livraison, autre), titre, description, jusqu'à 3 photos prises au téléphone. La liste montre le ticket (R-0001, W-R-0001 en ligne), l'état (Reçue, En cours, Résolue, Non retenue), les photos, la réponse de l'atelier et la durée de traitement (« Résolue en 1 h »). 5 réclamations ouvertes au plus par client.
- **Photos** : ce qui n'est pas une image est refusé ; la photo est réduite à 1600 px et réencodée, ce qui retire la position GPS et les autres données EXIF (si le serveur ne sait pas lire le JPEG, la photo est gardée telle quelle mais sans ses segments de métadonnées, 5 Mo au plus). Elles sont rangées en base, pas dans `public/`, et servies seulement au client de la fiche et aux droits Clients.
- **Côté atelier** : Clients › Réclamations, réservé au droit Clients (`ROLE_CLIENT`), avec une pastille sur le menu Clients pour les réclamations reçues sans réponse. La fiche montre la description, les photos, le client et la commande ; la réponse part avec un état : « En cours » la laisse ouverte, « Résolue » ou « Non retenue » la clôt. Le client reçoit un SMS sans détail (« nous avons répondu à votre réclamation R-0002 ») s'il accepte les SMS.
- **Synchronisation** : la réclamation et ses photos montent au local (types `reclamation` et `reclamation_photo`) et le droit Clients est prévenu ; la réponse et l'état redescendent sur le site.

Captures : `/mnt/project-files/captures/reclamations/`.

## Notifications et application installable (J5, construit le 13/10/2026)

- **Application** : l'espace client s'installe sur le téléphone (`public/espace.webmanifest`, portée `/espace`, même `sw.js` que le personnel). Le tableau de bord le propose à la première visite sur l'appareil, Mon compte le garde toujours : voir « Application sur l'écran d'accueil » plus bas.
- **Notifications** : quand la synchronisation apporte un changement au site (BAT prêt, y compris chaque nouvelle version, commande prête, paiement validé, réponse à une réclamation), `NotificationsClient` prévient le client une seule fois, en fin de requête : d'abord sur les téléphones où il a activé les notifications (Mon compte › Sur ce téléphone, gratuit), sinon par SMS s'il les accepte, sinon par e-mail. Les messages n'ont aucun montant (« Paiement reçu pour la commande CMD-… »). Un paiement ne va qu'aux payeurs d'un compte entreprise. Un événement de plus de 12 heures ne part plus (mise en service, synchronisation en retard).
- Journal des envois : table `notification_client_envoyee` (moyen utilisé : push, sms, email, aucun). Les fiches de démonstration (code `DEMO-`) ne reçoivent jamais de SMS ni d'e-mail.
- **À poser sur le serveur en ligne** (`.env.local`, jamais dans le dépôt) : les clés des notifications (`php bin/console app:push:cles`), les identifiants Orange SMS, et `MAILER_DSN` avec `MAILER_FROM_ADDRESS` pour l'e-mail. Sans elles, l'événement reste seulement dans le fil Notifications du client.
- La réponse à une réclamation ne part plus par SMS depuis l'atelier : elle passe par cette chaîne quand elle arrive sur le site.

## Application sur l'écran d'accueil (construit le 15/10/2026)

En attendant les applications Android et iPhone, le client met son espace sur l'écran d'accueil de son téléphone : une icône Succès Imprim qui ouvre `/espace` en plein écran, comme une application, et qui reçoit les notifications (sur iPhone, seule l'application ajoutée à l'écran d'accueil les reçoit). Code : `templates/espace/app/_installer_carte.html.twig`, `_installer_etapes.html.twig`, `public/js/espace-installer.js`, styles en fin de `public/css/espace-app.css`. Captures : `/mnt/project-files/captures/ecran-accueil/`.

**La carte du tableau de bord.** À la première visite du tableau de bord sur un appareil, une carte « Installez Succès Imprim sur votre téléphone » apparaît : une phrase (ouvrir son espace d'un geste, recevoir les alertes de ses commandes), « Ajouter à l'écran d'accueil » et « Plus tard ». Feuille posée au-dessus des onglets sur téléphone, petit panneau en bas à gauche sur ordinateur (« sur cet ordinateur », « Installer l'application »). Ni montant ni donnée du client.

| Où | Ce que fait le bouton |
|---|---|
| Android : Chrome, Edge, Samsung Internet ; Chrome et Edge sur ordinateur | la fenêtre d'installation du navigateur. La carte attend que le navigateur dise qu'il sait installer (événement `beforeinstallprompt`) : sur un premier passage, Chrome attend souvent un clic et une trentaine de secondes sur le site. Installée : « C'est fait… Merci ! », puis plus jamais. Refusée dans la fenêtre du navigateur : compte comme « Plus tard ». |
| iPhone et iPad, Safari | Safari n'a pas de bouton : la carte montre trois gestes illustrés (Partager, en bas de l'écran ou dans « ⋯ » ; « Sur l'écran d'accueil » ; « Ajouter »), puis « C'est fait ». |
| iPhone dans une autre application (WhatsApp, Chrome, Facebook…) | les mêmes gestes, avec « Ouvrez d'abord cette page dans Safari » et « Copier l'adresse » (l'adresse de l'espace, rien d'autre). |
| Application déjà ouverte depuis l'écran d'accueil, Firefox sur ordinateur, ou tout navigateur qui ne sait pas installer | rien. |

- **« Plus tard »** (ou Échap dans la carte) la cache sur cet appareil. Elle revient **une seule fois**, sept jours après ; au deuxième « Plus tard », elle ne revient plus. **Mon compte** garde toujours le bloc « Application Succès Imprim » (« Installer », ou les gestes sur iPhone) tant que l'application n'est pas installée.
- **Mémoire de l'appareil, pas du compte** : clé `si-espace-installer` du `localStorage` (`plusTard`, `le`, `installee`). C'est l'écran d'accueil de ce téléphone qui compte : une marque sur le serveur, au compte, cacherait la carte sur un nouveau téléphone dès qu'on a dit « Plus tard » sur l'ordinateur. Le serveur garantit déjà le reste : la carte n'est que dans le tableau de bord d'un client connecté (pare-feu de l'espace, jamais le personnel), après les étapes obligatoires de « Sécuriser mon compte », et seulement sur le site en ligne (`site_en_ligne()`), jamais sur les pages publiques. Rien à synchroniser, pas de table. Si le navigateur refuse toute mémoire (données du site bloquées), la carte ne s'affiche pas, puisqu'elle ne pourrait pas retenir « Plus tard » ; la page marche normalement et Mon compte garde le bouton. Pour revoir la carte en essai : effacer la clé dans les outils du navigateur.
- **Accessibilité** : pas de voile, la page reste cliquable ; elle s'allonge de la hauteur de la carte, rien ne reste caché dessous, et le bouton de l'assistant remonte au-dessus. La carte ne prend pas le focus en apparaissant ; elle est annoncée poliment au lecteur d'écran, se joint au clavier dans l'ordre de la page et ne retient pas le focus. Animation d'entrée seulement sans « réduire les animations ». Boutons d'au moins 44 px.
- La barre « Installer » propre au navigateur (Chrome sur Android) ne s'affiche ni sur les pages de connexion ni dans l'espace : c'est l'espace qui propose, au bon moment.

**Conditions d'installation** (vérifiées dans Chromium et par `ApplicationEcranAccueilTest`) :

- `public/espace.webmanifest` : nom et nom court en français, `start_url` et `scope` `/espace`, `display: standalone`, couleurs, icônes 192 et 512 px (`any`) et leurs versions `maskable` (logo dans le cercle central, pour les icônes rondes d'Android).
- iPhone : `images/icone-180.png` (180 px, fond blanc sans transparence) en `apple-touch-icon`, titre « Succès Imprim », plein écran.
- `public/sw.js`, portée `/espace` : il laisse passer chaque page par le réseau ; sans réseau, il montre `public/hors-ligne-espace.html` (« Pas de connexion internet », « Réessayer », recharge seule au retour du réseau). Cette page sans aucune donnée est la seule chose gardée dans le téléphone : aucune page de l'espace, aucun montant, aucun fichier n'est mis en cache. Le personnel (même fichier, portée `/`) garde le comportement d'avant : notifications seulement, pas d'écoute des pages.
- Il faut une adresse https sur le serveur (ou localhost en essai).

## Paiement Orange Money (17, J5, construit le 13/10/2026)

- **Parcours** : Payer avec Orange Money › récapitulatif › « Continuer vers Orange Money » › page sécurisée d'Orange Money (code secret du client) › retour sur la commande. Le lien d'Orange vaut 10 minutes ; s'il est encore valable pour le même montant et le même total, il est repris, sinon un nouveau paiement est ouvert. Seul un payeur (compte entreprise) ou un particulier peut payer, et seulement une commande confirmée par l'atelier.
- **Frais payés par le client** (15/10/2026) : chaque paiement Orange Money (commande, acompte de conception, pack de crédit IA) ajoute le fonds de soutien et le frais de retrait, 1 % chacun par défaut, arrondis au franc. Le récapitulatif montre « Montant de la commande » (ou « Reste à payer sur la commande », l'acompte, le prix du pack), « Fonds de soutien (1 %) », « Frais de retrait Orange Money (1 %) », « Total payé avec Orange Money » et une ligne d'explication, avec « Annuler » et « Continuer vers Orange Money ». Le serveur calcule tout (`FraisOrangeMoney`) et garde montant et frais sur la transaction ; Orange reçoit le total. Si le total a changé entre le récapitulatif et le clic (taux modifiés, paiement reçu entre-temps), le client revoit le récapitulatif avant de partir. Un taux à 0 % fait disparaître sa ligne.
- **Taux** : Réglages › Paiements (tuile, ou « Ouvrir la page complète »), réservés à la direction (ROLE_ADMIN, ou ROLE_PARAMETRES donné par elle). Ils sont tenus dans `ParametresPaiement` et partent au site en ligne avec les réglages publics de la tuile ; sans synchronisation, le site applique 1 % et 1 %.
- **Confirmation** : Orange prévient le site (`POST /om/notification/{référence}`, adresse publique). La notification n'est crue que si elle porte le jeton reçu à l'ouverture ; le site n'en garde que l'empreinte sha256. Au retour du client, l'état est aussi relu chez Orange (`transactionstatus`), sans attendre la notification.
- **Argent reçu** : il devient un paiement Orange Money « en attente », référence `OM <n° de transaction Orange>`, jamais validé tout seul. Son montant est la part de la commande ; le fonds de soutien et le frais de retrait vont dans les champs de frais du paiement (comme à la caisse) : ni le prix de la commande ni la facture ne changent. À la validation, l'argent va toujours sur le compte « Orange Money en ligne » (compte de l'API, imposé dans « À valider », créé au premier paiement validé s'il n'existe pas) : le montant en vente et, à part, les frais en « Frais Orange Money à prélever », qui ne sont pas une recette : Orange les reprend quand l'argent sort du compte (docs/finances.md, « Orange Money : deux sortes de comptes »). L'annulation d'un paiement validé reprend les deux. La synchronisation le monte au local, où la caisse et la direction sont prévenues ; la caisse le valide dans Finances › À valider (ou sur le paiement) après contrôle du compte Orange Money. Le client est ensuite prévenu par la chaîne des notifications.
- En attendant la caisse, la somme n'est plus redemandée en ligne : la commande affiche « Payé par Orange Money, en validation » et le solde ne la compte plus.
- La commande montre au payeur (ou au particulier) chaque paiement Orange Money avec ses frais : « Payé avec Orange Money le 15/10 », la part de la commande, le fonds de soutien, le frais de retrait et le total. Le valideur, le demandeur et la lecture seule ne voient aucun montant ; les notifications et les SMS n'en portent jamais.
- Table `transaction_orange_money` (site en ligne) : commande, compte, montant, fonds de soutien et frais de retrait (le total est ce qu'Orange débite), référence, jeton de paiement, empreinte du jeton de notification, état (initiee, reussie, echouee, expiree), paiement créé.
- **À poser sur le serveur en ligne** (`.env.local`, jamais dans le dépôt) : `ORANGE_MONEY_CLIENT_ID`, `ORANGE_MONEY_CLIENT_SECRET`, `ORANGE_MONEY_MERCHANT_KEY`. D'abord l'essai avec `ORANGE_MONEY_API=https://api.orange.com/orange-money-webpay/dev/v1` et `ORANGE_MONEY_DEVISE=OUV`, puis la production `https://api.orange.com/orange-money-webpay/ml/v1` avec `XOF`. Sans clés, la page garde le numéro marchand et la référence de commande.
- **Essayer les clés** : `php bin/console app:orange-money:tester` demande un jeton à Orange et dit, étape par étape, ce qui manque ou ce qu'Orange refuse, sans afficher aucune clé. Il montre aussi le calcul des frais pour 100 OUV (« 100 OUV + fonds de soutien 1 % (1) + frais de retrait 1 % (1) = 102 OUV payés par le client »), même sans clé. Avec `--paiement` (API d'essai seulement), il ouvre aussi un paiement de 100 OUV frais compris (102 OUV) pour vérifier la clé marchand. On met le Client ID et le Client Secret tels quels : l'en-tête « Authorization » du portail Orange est fabriqué par l'application. Orange refuse les adresses localhost : l'essai renvoie alors vers example.com, et l'espace client ouvert sur localhost affiche « Orange Money refuse les adresses localhost » sans appeler Orange. Pour un essai complet depuis un poste, ouvrir l'espace par un tunnel public (par exemple `cloudflared tunnel --url http://localhost:8000`) avec `SYMFONY_TRUSTED_PROXIES=127.0.0.1` dans `.env.local` pour que les liens soient en https ; la notification arrive alors aussi.

Captures : `/mnt/project-files/captures/orange-money/` ; frais et récapitulatif : `/mnt/project-files/captures/frais-om/`.

Reste à faire :

- Fidélité (18 à droite) : règles de points, paliers et récompenses à fixer par Yaya.
- RCCM et autres coordonnées de l'entreprise : en lecture en ligne, changées par le bureau (la raison sociale et le NIF se proposent depuis Mon compte).
- WhatsApp dans la chaîne des notifications, entre l'application et le SMS, quand la clé Meta sera posée. Les choix des clients sont déjà enregistrés (colonne WhatsApp de « Comment je suis prévenu »).
- Remise habituelle : elle reste au local (non synchronisée), donc Mon compte ne la montre pas. À décider par Yaya si elle doit apparaître dans « Conditions ».
- « Livreur en route » et « livrée » : les bons de livraison passent au site depuis le 13/10/2026 (sans livreur, photos ni signature). Après le déploiement : `php bin/console app:synchro --initialiser=livraison` sur le local.

## Atelier 3D et « Mes créations » (construit le 14/10/2026)

`/espace/atelier-3d` : le client compose un t-shirt, un polo ou une casquette en 3D (couleur, logo, texte devant et dos, quantités par taille) et voit son prix (grille, prix B2B, paliers, promotion indiquée). « Commander » crée une commande « à confirmer » avec les vues jointes comme maquette ; un demandeur ne voit aucun montant et envoie une demande à son valideur. Entrées : carte en haut de « Nouvelle commande », « Mes créations » dans le menu et la puce de la page Commandes sur téléphone. `/espace/creations` : les modèles validés, à rouvrir ou à commander de nouveau. Pas pour un compte « Lecture · suivi ». Détails dans `docs/atelier-3d.md`.

La page d'une commande remplit sa frise étape par étape, et fête la commande prête par quelques confettis (une fois par appareil, jamais avec « réduire les animations »).

## Demande de conception (DC01 à DC05, construit le 14/10/2026)

`/espace/conception` : le client fait faire un visuel par un graphiste (acompte Orange Money) ou par l'IA (crédit IA, 1 000 F l'image), puis commande l'impression. Seuls un particulier, un administrateur ou un payeur paient, achètent du crédit ou génèrent ; un demandeur prépare et voit qui peut payer ; la lecture seule regarde. Détails, droits, règles d'argent et réglage du fournisseur d'images dans `docs/demande-conception.md`.
