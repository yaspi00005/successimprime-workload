# Espace client connecté (maquettes 13 à 19)

Le client se connecte avec son numéro et son mot de passe (CX01, voir « Connexion » plus bas), puis arrive dans son espace `/espace`. Il ne voit que sa propre fiche : chaque page passe par `src/Service/EspaceClient/EspaceClient.php`, qui filtre sur le client du compte connecté, et répond « introuvable » pour la commande, le BAT, le fichier, le devis ou la facture d'un autre client. Pas de coûts ni de marges. Du personnel, le client voit dans le suivi d'une commande, le BAT et les messages le prénom et le métier seulement (« Seydou (graphiste PAO) », `Employes::getNomPublic`), jamais le nom de famille, le téléphone ni la photo ; seule la carte « Votre conseiller(ère) » porte le prénom et le nom du commercial qui suit le client (champ `conseiller`, maquette EC01), avec le numéro de la boutique.

## État (13/10/2026)

Construit, téléphone d'abord (onglets en bas), menu à gauche sur ordinateur :

- **Accueil (13)** : bandeau des BAT à valider, commandes en cours avec la frise Reçue › BAT › Impression › Finition › Prête, reste à payer avec le bouton Orange Money, devis à accepter, commandes récentes.
- **Mes commandes et suivi (15, refait le 16/10/2026 sur les maquettes CO05 et CO06)** : liste avec état et avancement ; page d'une commande avec « Où en est ma commande ? » étape par étape, le résumé (montant avec la ligne « Remise − N F » s'il y a une remise globale, déjà déduite, payé et reste pour qui paie), le BAT à valider et les messages de la commande, lien WhatsApp vers l'atelier (téléphone de la première entité). Voir « Suivi de la commande et BAT » plus bas.
- **Mes devis (14, à droite)** : filtres Tous, À accepter, Acceptés ; « Accepter » passe le devis envoyé en accepté et prévient le droit Devis ; PDF par le lien public signé.
- **Fichiers et BAT (16, à droite)** : BAT à valider et précédents ; page du BAT avec l'aperçu (image ou PDF) de la dernière version préparée par l'atelier, la frise des versions et des demandes du client. « Valider le BAT » note le BAT validé (horodaté, frise de conception, points du graphiste) et remet le contrôle à la prépresse pour la validation des fichiers et l'envoi en production ; « Demander une modification » repasse le contrôle en correction avec le texte du client. Sur un BAT en image, le client touche l'image pour poser des repères numérotés (10 au plus), chacun avec ce qu'il faut changer ; le graphiste les voit sur l'aperçu de son poste (R03) et dans le fil des échanges, et la version suivante les affiche au client avec « corrigé en version N » (maquette 16). Sur un PDF, la demande reste en texte. La prépresse est prévenue dans les deux cas. Depuis le 16/10/2026, la page du BAT montre la même carte que la page de la commande (points à vérifier avant de valider, corrections gratuites) : voir « Suivi de la commande et BAT ».
- **Factures et paiements (17, refait le 16/10/2026 sur les maquettes FP01 à FP05)** : ce qui est à payer, plusieurs factures et acomptes payés en une fois par Orange Money avec l'avoir déduit, détail d'une facture, reçus, relevé de compte en PDF. Voir « Factures et paiements » plus bas. Tant que les clés Orange Money ne sont pas posées, la page donne le numéro marchand (tuile Entités) et les références ; le paiement est validé par la caisse.
- **Mon compte et notifications (19)** : coordonnées (modifiées par le bureau), réglage SMS, mot de passe, déconnexion ; fil des notifications reconstruit à partir des commandes (BAT prêt, paiement reçu, impression lancée, commande prête, livreur en route, livrée).
- **Fidélité (18, à droite, construit le 14/10/2026)** : `/espace/fidelite`, entrée Fidélité du menu, pour un client hors grille revendeur (`typeClient` autre que B2B) et une personne qui paie. Solde en points et en francs, progression vers chaque seuil, points qui expirent bientôt, promo de réveil, rappel du ×2 payé le jour même, historique (type, points, date, commande ; ni agent ni motif). Lecture seule : c'est l'atelier qui utilise les points sur une commande. Un client à la grille revendeur (B2B) n'a pas l'entrée : le programme ne s'applique pas à cette grille de prix. Détails dans `docs/fidelite.md`.

- **Nouvelle commande** : en quatre étapes depuis le 18/10/2026, voir « Nouvelle commande en quatre étapes (CO01 à CO04) » plus bas. La commande arrive toujours en brouillon « Reçue · à confirmer » (10 en attente au plus par client). Au local, la fiche commande affiche « Commande passée par le client » avec le bouton Vérifier et confirmer, et les choix du client (délai, livraison, créneau, adresse).
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

**Coque (EC01, EC05).** Sur ordinateur : menu à gauche, barre du haut (recherche, « + Nouvelle commande », cloche, personne et fonction), carte « Votre conseiller(ère) » (nom ; WhatsApp et appel vers le numéro de la boutique, jamais celui de l'employé). Sur téléphone : en-tête (entreprise, « Bonjour Prénom », cloche) et cinq onglets (Accueil, Commandes, Fichiers, Factures, Compte). Le bas de chaque page laisse la hauteur des onglets, la zone du geste de l'iPhone et une marge (`--ec-bas-libre` dans `espace-app.css`) : au bout du défilement, les totaux et la dernière carte restent au-dessus des onglets.

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

**Livraisons.** `/espace/livraisons` : en route, à venir, livrées, à partir des bons de livraison synchronisés (créneau, heure de départ, heure de remise). Après un échec, la livraison est « Reportée » avec la date : l'atelier recontacte le client pour une nouvelle date (ni la raison ni le livreur ne passent au site).

**Messages.** `/espace/messages` : fil entre le client et l'atelier.

- Texte seulement (1 000 caractères), une commande en sujet si on veut, 10 messages par heure et par personne (`espace_message_compte` dans `rate_limiter.yaml`).
- Le message monte au local, où le droit Clients (`ROLE_CLIENT`) est prévenu. L'équipe répond depuis **Clients › Messages**, avec une pastille sur le menu Clients pour les messages non traités.
- La réponse redescend et prévient le client (« Réponse de l'atelier », sans montant). Elle est signée du prénom et du métier de qui a répondu (« Seydou (graphiste PAO) »), « Succès Imprim » pour un compte sans fiche employé ; le traitement et le compte de l'employé restent au local.
- **Qui répond (demande de Yaya du 16/10/2026, maquette attendue : présentation par défaut).** Chaque réponse de l'atelier porte, côté client (page Messages et « Messages de la commande »), un petit disque à l'initiale du prénom et « Seydou · graphiste PAO » (`MessageClient::getSignature`, `templates/espace/app/_agent.html.twig`). Le disque garde une teinte fixe par prénom ; « SI » en bleu de la marque pour « Succès Imprim » et les anciennes réponses. Côté client, jamais de photo ni de nom de famille : la fiche employé n'a pas encore d'accord « photo visible des clients », la photo reste donc réservée à l'équipe. Tout est tiré de l'auteur enregistré à la réponse, le seul qui redescend en ligne. Les messages du client ne changent pas.
- Dans **Clients › Messages** (ERP), chaque réponse montre la photo de l'employé qui a répondu (fiche employé, route privée `app_photo_employe`, personnel seulement) ou ses initiales sur un disque de couleur, et son prénom ; au survol, ce que le client voit (« Le client voit : Seydou · graphiste PAO »). Sur le site en ligne, en lecture seule, l'employé n'est pas connu : le disque et le prénom de la signature.
- Un message qui porte une commande s'affiche aussi sur la page de cette commande (« Messages de la commande »), et l'équipe voit la commande à côté de chaque message dans Clients › Messages.

**Données de démonstration** : voir « Espace client de démonstration » dans `docs/donnees-demo.md`.

## Nouvelle commande en quatre étapes (CO01 à CO04, CO06, construit le 18/10/2026)

`/espace/commandes/nouvelle` (`EspaceNouvelleCommandeController`) : Produit, Options, Fichier, Livraison et paiement. Les étapes 2 à 4 sont un seul formulaire ; le navigateur montre une étape à la fois (onglets et, sur téléphone, barres de progression et barre sombre du prix avec « Continuer »). Sans JavaScript, tout s'affiche et s'envoie d'un coup. **Les prix sont toujours calculés par le serveur** (`/espace/commandes/estimer`, le même calcul qu'à l'enregistrement) ; la page ne fait qu'afficher.

**1. Produit (CO01).** Puces des catégories, une carte par produit publié (sa configuration la moins chère : « dès 3 500 F / m² », prix revendeur pour un client B2B ; un demandeur voit « sur mesure, au m² »). À droite : « Commander à nouveau » (dernières lignes ; « Refaire » ouvre l'étape 4 avec le même produit, la même taille et le **même fichier**, copié depuis l'ancienne commande, `FichiersClient::reprendre`), « Mes produits enregistrés », « Besoin spécial ? » (demande de devis). Bandeau « Pas encore de visuel ? » vers la demande de conception.

**2. Options (CO02).** Largeur et hauteur en mètres (formats courants 2 × 1, 3 × 1, 4 × 2, 5 × 3), quantité ; **matière** = les configurations du même produit (support, description, supplément au m² par rapport à la moins chère, `PageNouvelleCommande::matieres`) ; finitions de la matière choisie (« inclus », « +300 F/m² », obligatoires cochées) ; délai **Standard** (48 h) ou **Express** (24 h, +30 %). Aperçu à l'échelle avec les œillets (finition dont le nom contient « œillets », espacement lu dans le nom, 50 cm sinon). « Votre commande » à droite : surface × prix, finitions, délai, total, acompte, et « Prête le samedi 17/10 si le BAT est validé avant 16 h aujourd'hui » (`DelaisCommande` : le délai part le jour même avant l'heure limite et si la boutique est ouverte, sinon le jour ouvré suivant, puis un jour ouvré par tranche de 24 h ; jours et fériés de Réglages › Horaires).

**Express.** Une ligne libre « Express 24 h (+30 %) » s'ajoute à la commande (pourcentage du prix des produits, avant code promo), sans production ni PAO : visible sur la commande et la facture, jamais un prix de ligne gonflé. La commande garde `delaiProduction` (`standard` ou `express`). Si la boutique change le prix en confirmant, elle ajuste cette ligne elle-même.

**3. Fichier (CO03).** « J'ai mon fichier » (envoi par morceaux, déjà existant, ou un fichier déjà envoyé), « Une de mes créations » (images IA prêtes du client) ou « Pas de fichier » (note pour l'atelier : visuel à concevoir). Le fichier est contrôlé à la taille commandée (`/espace/commandes/nouvelle/controle/{id}`, seulement un fichier du client) : dimensions, résolution, couleurs, polices, fonds perdus, type, et **texte près du bord** (`BordsFichier` : JPEG et PNG, détails à moins de 4 cm du bord d'une bâche, 2 cm d'une affiche, 4 mm d'une carte ; un indice, jamais un refus : « le graphiste les décalera, sans frais »). « Après la commande » explique le BAT.

**4. Livraison et paiement (CO04).** Retrait en boutique (0 F) ou **Livraison à Bamako** : frais du réglage « Frais de livraison en ligne » (un des paliers, 2 000 F par défaut, 0 = offerte ; la boutique peut les changer en confirmant), adresse (enregistrée ou autre), contact, **créneau** (matin ou après-midi des trois premiers jours ouvrés à partir du jour où elle est prête ; seul un créneau proposé est accepté). La date du créneau devient la date de livraison, le libellé est gardé (`creneauLivraison`). Paiement, pour qui paie (particulier, payeur d'une entreprise) : **Acompte** (pourcentage de la fiche du client, sinon Réglages › Paiements, 50 % ; arrondi au franc supérieur), **Tout payer maintenant**, ou **Espèces en boutique** ; frais Orange Money annoncés ; « Ne donnez jamais votre code secret » ; case des conditions de vente obligatoire ; bouton « Payer 12 400 F et commander ».

**Paiement à la commande** (`/espace/commandes/nouvelle/{id}/paiement`, payeur seulement). La commande est **d'abord enregistrée** « à confirmer », puis le récapitulatif Orange Money (même présentation que FP03 : montant, fonds de soutien, frais de retrait, total) part par **le même règlement que « Factures et paiements »** (`ReglementsEnLigne`, `PaiementALaCommande`) : retour sur « Paiement reçu », paiement « en attente » à la caisse, jamais validé tout seul. Seule la commande en ligne de ce client, encore à confirmer, ni en attente ni refusée par son valideur, se paie ainsi, et jamais plus que son reste. Annulé chez Orange : retour sur ce récapitulatif. La production attend toujours la confirmation de la boutique et le BAT ; si la boutique change le prix, l'espace montre le nouveau reste. La commande confirmée se paie ensuite dans « Factures et paiements ». La page de la commande propose « Payer maintenant ? » tant que rien n'a été payé (`_commande_brouillon_paiement.html.twig`).

**Entreprises.** Le valideur et le payeur voient les prix. Un demandeur (et toute personne dont la commande doit être validée) voit les quantités, les délais et les créneaux, aucun montant (ni dans la page ni dans les réponses JSON) ; son étape 4 s'appelle « Livraison », sans paiement, et le bouton « Envoyer la demande ». Le valideur est prévenu sans montant. Un client B2B voit les prix de la grille revendeur, sans code promo.

**Interrupteurs (revue du 18/10/2026).** Réglages › Espace client, appliqués par le serveur et descendus au site :
- **Commande en ligne** (allumé au départ) : coupé, la page Nouvelle commande dit « La commande en ligne est fermée pour le moment » (devis, message à l'atelier), l'envoi du formulaire, l'estimation et le contrôle du fichier sont refusés. Le refus est dans `NouvelleCommande::verifierEnAttente`, par où passent toutes les commandes de l'espace : Mes produits, Atelier 3D et impression d'une conception aussi. Les commandes déjà passées continuent.
- **Paiement Orange Money en ligne** (allumé au départ) : `OrangeMoney::estOuvert()` = clés posées dans `.env.local` **et** interrupteur allumé. Coupé, l'étape 4 ne propose pas Orange Money (« Vous payez en boutique »), les pages de paiement (à la commande, Factures et paiements, commande confirmée, conception, crédit IA) montrent le numéro marchand au lieu du bouton, et tout nouveau paiement est refusé par le serveur. Un paiement déjà ouvert chez Orange se vérifie toujours. Tant que l'interrupteur n'était pas appliqué, Orange Money marchait dès que les clés étaient posées : il est donc allumé par défaut, et la migration `Version20261018110000` rallume un interrupteur resté coupé dans un réglage déjà enregistré, pour que le paiement de l'espace continue après la mise à jour. Le couper ensuite ferme vraiment le paiement en ligne.

**Réglages.** Réglages › Espace client : délai standard (48 h), express proposé (oui), délai express (24 h), majoration express (30 %), heure limite (16:00). Réglages › Livraison et objet : « Frais de livraison en ligne » (0 ou un des paliers, contrôlé à l'enregistrement). Tous descendent au site (`CatalogueSynchro::REGLAGES_PUBLICS`), avec les jours fériés.

**Captures** : `/mnt/project-files/captures/commande-en-ligne/`.

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

### Plusieurs interlocuteurs pour une entreprise (vérifié le 15/10/2026)

Demande de Yaya : « Un compte pour une entreprise peut avoir plusieurs interlocuteurs […] deux ou trois personnes qui font des commandes pour une entreprise. »

**Ce qui existait** : chaque personne a son propre numéro, son mot de passe et ses droits (tableau ci-dessus) ; une commande en ligne note qui l'a demandée (`Commandes::demandeePar`, « en ligne par X ») et qui l'a validée. Vérifié de bout en bout le 15/10/2026 sur Salon Espoir : Mariam (demandeur) envoie une demande sans voir de prix, Awa (valideur) la voit « demandée par Mariam Dembélé » et la valide, Ibrahim (payeur) commande aussi avec son propre numéro ; au local, la commande montre « en ligne par Mariam Dembélé, validée par Awa Coulibaly » et son bon de commande.

**Ajouté pour la boutique** (une commande ou un devis saisi au comptoir pour une entreprise) :

- Devis : champ **« À l'attention de »** (`Devis::interlocuteur`, 80 caractères) ; commande : champ **« Commandé par »** (le même `demandeePar` que les commandes en ligne). Dans la carte du client de la saisie, avec une liste proposée : les contacts de la fiche (fonction, contact principal) puis les noms déjà notés sur ses devis et commandes, dont les personnes qui commandent en ligne (`/saisie/interlocuteurs/{id}`, droits Devis, Commande ou Clients ; des noms seulement, ni numéro ni montant). Le champ reste libre.
- Un devis transformé en commande donne son interlocuteur au « Commandé par » de la commande. Une commande passée en ligne garde le nom de la personne connectée : le champ n'est pas modifiable à la boutique.
- Affiché sur le devis (« À l'attention de »), la commande (« commandée par X »), la fiche client (onglets Commandes et Devis, sous le numéro) ; imprimé sur le PDF du devis (cadre client) et de la facture (« Commandé par », sous le bon de commande). Une facture déjà émise garde son PDF.
- Le devis le fait descendre au site (synchronisation, champ `interlocuteur`).

## Suspendre l'accès à l'espace client (construit le 15/10/2026)

Demande de Yaya : « on a la possibilité de bloquer l'espace client d'un utilisateur ». Code : `src/Service/EspaceClient/SuspensionsEspace.php`, `src/Entity/SuspensionEspace.php`, `src/Controller/EspaceSuspensionController.php`, `templates/clients/_espace_suspension.html.twig`.

- **Où** : fiche client (ERP local), bloc « Accès à l'espace client », pour le droit Clients (`ROLE_CLIENT`). Caché et refusé en « Voir » (LectureSeuleSubscriber) ; en ligne, le bloc montre les suspensions sans bouton (« se suspend et se rétablit depuis l'ERP de la boutique »).
- **Qui** : tout le compte (chaque personne de l'entreprise), ou une personne, reconnue à son numéro de connexion : le numéro de la fiche, un contact de la fiche qui a un numéro, ou un autre numéro tapé (une personne ajoutée en ligne par l'entreprise, que la boutique ne voit pas dans sa liste).
- **Motif obligatoire**, gardé à l'atelier : le client lit seulement « Votre accès à l'espace client est suspendu. Contactez Succès Imprim. ».
- **Journal** : chaque suspension et chaque rétablissement est noté au journal de la fiche (catégorie Contacts : qui, quand, pourquoi). La suspension levée reste dans la base (« Rétablir » ne l'efface pas) et les trois dernières se lisent sous le bloc.
- **Rien n'est effacé** : commandes, factures, fichiers et compte restent ; « Rétablir » rend l'accès tel qu'il était (mot de passe compris).
- **Sur le site** (après la synchronisation, type `suspension_espace` : seuls la fiche, le numéro, le début et la levée descendent ; ni le motif, ni le nom, ni l'employé) :
  - la connexion est refusée avec le message, **seulement après le bon mot de passe ou le bon code** (avec un mauvais mot de passe, rien ne dit que l'accès est suspendu) ; « rester connecté » aussi (`EspaceClientChecker`) ;
  - une session ouverte se ferme à la page suivante, avec le message (`EspaceClientSubscriber`) ;
  - « Recevoir un code » ne part pas : même réponse qu'un numéro inconnu (`EspaceClientComptes`) ; la boutique ne peut pas envoyer de code d'accès au numéro suspendu ;
  - ni notification (application, SMS, e-mail), ni SMS d'invitation, de demande à valider ou de conception à payer (`NotificationsClient`, `CompteEntreprise`, `DemandesConception`). Les autres personnes de l'entreprise continuent normalement.
- Le statut « Bloqué » de la fiche (conditions commerciales) est une autre chose : il ne coupe pas l'espace client.
- La recette des accès vérifie qu'une session tombe dès la suspension et que les autres personnes entrent toujours.

## Bandes d'annonces

Les annonces saisies dans Réglages › Site vitrine › Annonces avec la cible « Espace client » ou « les deux » s'affichent en haut du contenu de chaque page connectée. Règles et comportement : docs/site-vitrine.md, « Bandes d'annonces ».

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

- **Parcours** : Payer avec Orange Money › récapitulatif › « Continuer vers Orange Money » › page sécurisée d'Orange Money (code secret du client) › retour sur la commande. Le lien d'Orange vaut 10 minutes ; s'il est encore valable pour le même montant et le même total, il est repris, sinon un nouveau paiement est ouvert. Seul un payeur (compte entreprise) ou un particulier peut payer, et seulement une commande confirmée par l'atelier (exception : l'acompte ou le tout payé à la commande en ligne, voir « Nouvelle commande en quatre étapes »).
- **Frais payés par le client** (15/10/2026) : chaque paiement Orange Money (commande, acompte de conception, pack de crédit IA) ajoute le fonds de soutien et le frais de retrait, 1 % chacun par défaut, arrondis au franc. Le récapitulatif montre « Montant de la commande » (ou « Reste à payer sur la commande », l'acompte, le prix du pack), « Fonds de soutien (1 %) », « Frais de retrait Orange Money (1 %) », « Total payé avec Orange Money » et une ligne d'explication, avec « Annuler » et « Continuer vers Orange Money ». Le serveur calcule tout (`FraisOrangeMoney`) et garde montant et frais sur la transaction ; Orange reçoit le total. Si le total a changé entre le récapitulatif et le clic (taux modifiés, paiement reçu entre-temps), le client revoit le récapitulatif avant de partir. Un taux à 0 % fait disparaître sa ligne.
- **Taux** : Réglages › Paiements (tuile, ou « Ouvrir la page complète »), réservés à la direction (ROLE_ADMIN, ou ROLE_PARAMETRES donné par elle). Ils sont tenus dans `ParametresPaiement` et partent au site en ligne avec les réglages publics de la tuile ; sans synchronisation, le site applique 1 % et 1 %.
- **Confirmation** : Orange prévient le site (`POST /om/notification/{référence}`, adresse publique). La notification n'est crue que si elle porte le jeton reçu à l'ouverture ; le site n'en garde que l'empreinte sha256. Au retour du client, l'état est aussi relu chez Orange (`transactionstatus`), sans attendre la notification.
- **Argent reçu** : il devient un paiement Orange Money « en attente », référence `OM <n° de transaction Orange>`, jamais validé tout seul. Son montant est la part de la commande ; le fonds de soutien et le frais de retrait vont dans les champs de frais du paiement (comme à la caisse) : ni le prix de la commande ni la facture ne changent. À la validation, l'argent va toujours sur le compte « Orange Money en ligne » (compte de l'API, imposé dans « À valider », créé au premier paiement validé s'il n'existe pas) : le montant en vente et, à part, les frais en « Frais Orange Money à prélever », qui ne sont pas une recette : Orange les reprend quand l'argent sort du compte (docs/finances.md, « Orange Money : deux sortes de comptes »). L'annulation d'un paiement validé reprend les deux. La synchronisation le monte au local, où la caisse et la direction sont prévenues ; la caisse le valide dans Finances › À valider (ou sur le paiement) après contrôle du compte Orange Money. Le client est ensuite prévenu par la chaîne des notifications.
- En attendant la caisse, la somme n'est plus redemandée en ligne : la commande affiche « Payé par Orange Money, en validation » et le solde ne la compte plus.
- La commande montre au payeur (ou au particulier) chaque paiement Orange Money avec ses frais : « Payé avec Orange Money le 15/10 », la part de la commande, le fonds de soutien, le frais de retrait et le total. Le valideur, le demandeur et la lecture seule ne voient aucun montant ; les notifications et les SMS n'en portent jamais.
- Table `transaction_orange_money` (site en ligne) : commande, compte, montant, fonds de soutien et frais de retrait (le total est ce qu'Orange débite), référence, jeton de paiement, empreinte du jeton de notification, état (initiee, reussie, echouee, expiree), paiement créé.
- **À poser sur le serveur en ligne** (`.env.local`, jamais dans le dépôt) : `ORANGE_MONEY_CLIENT_ID`, `ORANGE_MONEY_CLIENT_SECRET`, `ORANGE_MONEY_MERCHANT_KEY`. D'abord l'essai avec `ORANGE_MONEY_API=https://api.orange.com/orange-money-webpay/dev/v1` et `ORANGE_MONEY_DEVISE=OUV`, puis la production `https://api.orange.com/orange-money-webpay/ml/v1` avec `XOF`. Sans clés, la page garde le numéro marchand et la référence de commande.
- **Essayer les clés** : `php bin/console app:orange-money:tester` demande un jeton à Orange et dit, étape par étape, ce qui manque ou ce qu'Orange refuse, sans afficher aucune clé. Il montre aussi le calcul des frais pour 100 OUV (« 100 OUV + fonds de soutien 1 % (1) + frais de retrait 1 % (1) = 102 OUV payés par le client »), même sans clé. Avec `--paiement` (API d'essai seulement), il ouvre aussi un paiement de 100 OUV frais compris (102 OUV) pour vérifier la clé marchand. On met le Client ID et le Client Secret tels quels : l'en-tête « Authorization » du portail Orange est fabriqué par l'application. Orange refuse les adresses localhost : l'essai renvoie alors vers example.com, et l'espace client ouvert sur localhost affiche « Orange Money refuse les adresses localhost » sans appeler Orange. Pour un essai complet depuis un poste, ouvrir l'espace par un tunnel public (par exemple `cloudflared tunnel --url http://localhost:8000`) avec `SYMFONY_TRUSTED_PROXIES=127.0.0.1` dans `.env.local` pour que les liens soient en https ; la notification arrive alors aussi.
- **Paiement d'un client refusé** : le client voit seulement « Orange Money a refusé d'ouvrir le paiement ». La réponse d'Orange est gardée pour les 5 derniers refus (date, référence SI…, raison ; ni clé, ni jeton, ni montant) dans `var/orange-money/refus.json`, et `app:orange-money:tester` l'affiche sous le tableau. Un refus « HTTP 403 … insufficient scope (Your scope is not allowed) » veut dire que l'application Orange du Client ID n'a pas l'API appelée. Cas le plus courant : des clés de production au Mali avec l'adresse de l'API d'essai ; mettre alors `ORANGE_MONEY_API=https://api.orange.com/orange-money-webpay/ml/v1` et `ORANGE_MONEY_DEVISE=XOF`. Sinon, ajouter l'API Orange Money Web Payment dans « My apps » sur developer.orange.com, et prendre la clé marchand de la même application. Le testeur affiche les 6 premiers caractères du Client ID pour vérifier qu'il s'agit bien de l'application qui a l'API (« Approved »). Le jeton Orange est gardé en cache sous une clé qui suit l'adresse de l'API et les clés : changer `.env.local` prend effet sans vider le cache. Sur un 401 ou un 403, le site redemande une seule fois un jeton neuf avant de déclarer le refus (cas d'un jeton pris avant l'ajout de l'API à l'application).

Captures : `/mnt/project-files/captures/orange-money/` ; frais et récapitulatif : `/mnt/project-files/captures/frais-om/`.

Reste à faire :

- Fidélité (18 à droite) : règles de points, paliers et récompenses à fixer par Yaya.
- RCCM et autres coordonnées de l'entreprise : en lecture en ligne, changées par le bureau (la raison sociale et le NIF se proposent depuis Mon compte).
- WhatsApp dans la chaîne des notifications, entre l'application et le SMS, quand la clé Meta sera posée. Les choix des clients sont déjà enregistrés (colonne WhatsApp de « Comment je suis prévenu »).
- Remise habituelle : elle reste au local (non synchronisée), donc Mon compte ne la montre pas. À décider par Yaya si elle doit apparaître dans « Conditions ».
- « Livreur en route » et « livrée » : les bons de livraison passent au site depuis le 13/10/2026 (sans livreur, photos ni signature). Après le déploiement : `php bin/console app:synchro --initialiser=livraison` sur le local.

## Factures et paiements (FP01 à FP05, construit le 16/10/2026)

`/espace/factures` remplace l'ancienne page Factures. Tout y est calculé par le serveur (`FacturesClient`, `ReglementsEnLigne`) ; le navigateur ne fait qu'afficher et recalculer la barre de sélection.

**Qui y entre.** Un particulier, le titulaire d'une entreprise et un compte « payeur » (`EspaceFacturesController::peutVoirLesFactures`). Le valideur (prix et devis, sans le droit payeur), le demandeur et la lecture seule reçoivent « accès refusé » sur chaque adresse, même tapée à la main (`DroitsEspace` et le contrôleur) : ce sont le solde, les factures et le crédit de l'entreprise, que la règle `montantsVisibles` réserve au payeur. Une facture, un paiement, un reçu ou un règlement d'un autre client répond « introuvable » (404), de même qu'une commande d'un autre client glissée dans la sélection.

| Adresse | Page |
| --- | --- |
| `GET /espace/factures` | Vue d'ensemble (FP01 ; FP05 sur téléphone) |
| `GET /espace/factures/{id}` | Détail d'une facture (FP02) |
| `GET /espace/factures/payer?e[]=…` | Récapitulatif avant Orange Money (FP03), en fenêtre ou en page sans JavaScript |
| `POST /espace/factures/payer` | Lance le règlement (jeton CSRF, limite par compte) |
| `GET /espace/factures/paiement/{référence}` | Paiement reçu (FP04, FP05) |
| `GET /espace/factures/recu/{id}` | Reçu d'un paiement validé, en PDF |
| `GET /espace/factures/releve?du=…&au=…` | Relevé de compte en PDF |

**Vue d'ensemble (FP01).**

- Tuiles : Total à payer (dont échu, et ce qui se réglera à la livraison), Payé dans l'année (paiements validés), Prochaine échéance, Avoir disponible. Sur téléphone, seule la tuile du total reste.
- « À payer » : factures non soldées, commandes livrées ou prêtes sans facture, acomptes des commandes en préparation (pourcentage du client, sinon l'acompte par défaut des Réglages), solde d'ouverture. Ordre : la plus ancienne échéance d'abord. Ce qui attend la validation de la boutique n'est plus redemandé.
- Les cases cochées font la barre « N sélectionnés · total (− avoir) · Payer X par Orange Money ».
- Onglets Factures, Paiements, Avoirs, Reçus (année au choix).
- À droite : Moyens de paiement, Bon à savoir, « Une question sur une facture ? ». « Contester » ouvre une réclamation pré-remplie ; « Message » ouvre la messagerie avec la référence de la facture.

**Payer plusieurs éléments en une fois (FP03).**

- Le récapitulatif liste les éléments, l'avoir déduit (case à décocher), « Tout payer » ou « Autre montant ». Un paiement partiel va de 1 000 F (ou moins s'il reste moins) jusqu'au total ; il paie d'abord la plus ancienne échéance.
- Les frais Orange Money sont montrés avant de partir : fonds de soutien et frais de retrait, 1 % chacun par défaut, calculés une fois sur le montant payé, puis partagés au prorata entre les commandes.
- Le numéro Orange Money se saisit sur la page d'Orange : Web Payment ne le reçoit pas d'ici, il n'est donc ni demandé ni pré-rempli. La page dit « Vous confirmez sur la page Orange Money avec votre code secret » et « Ne donnez jamais votre code secret : Succès Imprim ne vous le demandera jamais ».
- Au clic, le serveur recalcule tout (dû, avoir, répartition, frais). Si le total a changé depuis l'affichage, le client revoit le récapitulatif. Il y a un seul passage chez Orange pour le total ; un second clic dans les 9 minutes reprend le même lien.
- Limite : 8 lancements par compte et par 15 minutes (`espace_paiement_compte`).
- Tables `reglement_en_ligne` et `reglement_en_ligne_part` (une ligne par commande : part Orange Money, part d'avoir, frais, rang). `transaction_orange_money.reglement_id` relie la transaction Orange. L'ancien paiement d'une seule commande (`/espace/payer/{id}`) reste en place.

**Argent reçu.**

- À la confirmation d'Orange, il y a **un paiement « en attente » par commande** (`PaiementsDuReglement`), jamais validé tout seul. Sa référence est `OM <transaction Orange> n/N`, une par paiement, pour que « Référence déjà utilisée » ne bloque pas la validation au local. Ses frais sont dans les champs de frais du paiement, comme à la caisse.
- Une notification rejouée ne crée rien de plus. Un paiement échoué ou expiré marque le règlement « non abouti » et libère l'avoir.

**Avoir.**

- L'avoir est le solde créditeur du client (`soldeCredit`), en lecture seule en ligne.
- L'utiliser crée un paiement « solde client » en attente (`AV <règlement>`), validé par la caisse dans Finances › À valider (`PaiementService::validerAvoir`). Le solde ne baisse qu'à la validation.
- En attendant, l'avoir est montré « réservé » ou « en validation » et n'est pas promis deux fois : 30 minutes pour un règlement encore chez Orange, puis tant que le paiement attend la boutique.
- Payé par l'avoir seul, le règlement est reçu tout de suite, sans Orange.

**Paiement reçu (FP04, FP05).**

- États honnêtes, ligne par ligne : « en cours chez Orange Money », « reçu · en validation », « soldée », « non payé ». Le titre dit « X F payés » pour la part Orange Money, « Avoir utilisé » pour un avoir seul.
- Au retour d'Orange, l'état est relu chez Orange ; la page s'actualise toute seule pendant 2 minutes.
- Le vrai numéro de reçu (« R-000123 », celui de la caisse) n'apparaît qu'une fois la boutique passée, avec son bouton PDF.
- « Et maintenant » vient des données : BAT à valider, prochaine échéance avec le prochain rappel, commande prête.
- « Partager sur WhatsApp » ouvre wa.me avec un texte sans montant ni lien privé.

**Détail d'une facture (FP02).**

- Lignes, totaux comme sur le PDF (remise, TVA ; la majoration ne s'affiche qu'au local), acompte payé, reste à payer ou « Soldée », QR de vérification (`/f/{code}`) si la facture a un code au bon format.
- Paiements reçus avec barre de progression et reçus.
- Rappels envoyés et prochain rappel (règles de rappel synchronisées). Une facture soldée n'en a plus.
- Boutons : PDF, Partager (lien de la page, qui demande la connexion ; texte sans montant), « Payer le reste », « Payer un montant ».

**Reçu et relevé (PDF, Dompdf).**

- Reçu : en-tête de l'entité qui facture, numéro R-…, date du paiement et de la validation, client, commande et facture, moyen et référence, montant reçu, frais Orange Money payés en plus, reste à payer ; aucun nom d'employé.
- Relevé : du … au …, en-tête de l'entreprise, bloc client (raison sociale, RCCM, NIF), lignes Débit, Crédit et Solde (factures et paiements validés), solde d'ouverture, solde de clôture. Une note signale ce qui attend la validation.

**Moyens de paiement.**

- Orange Money (ici, ou le numéro marchand tant que les clés ne sont pas posées), espèces au comptoir, virement au-delà du seuil des Réglages.
- Les coordonnées bancaires sont un réglage, Réglages › Paiements › « Coordonnées bancaires pour les virements », réservé à la direction (ROLE_ADMIN, ou ROLE_PARAMETRES donné par elle). Il part au site avec les réglages publics. Vide, la page dit « coordonnées à demander en boutique ».

**Notifications.** Aucune notification ni aucun SMS ne porte de montant ni de clé. Le paiement validé est annoncé par la chaîne existante (« Paiement reçu pour la commande … »), aux payeurs seulement.

Captures : `/mnt/project-files/captures/factures-paiements/`.

## Suivi de la commande et BAT (CO05, CO06, construit le 16/10/2026)

`/espace/commandes/{id}` (`EspaceClientController::commande`, `templates/espace/app/commande.html.twig`). Sur ordinateur : « Où en est ma commande ? » et le résumé à gauche, le BAT et les messages de la commande à droite, « Facture et reçus » et « Message » en haut. Sur téléphone : le BAT qui attend une réponse en premier (Valider, Corriger), puis le suivi, le résumé et les messages. Styles et script chargés par cette page seulement (`public/css/espace-suivi.css`, `public/js/espace-bat.js`).

**Où en est ma commande ?** `src/Service/EspaceClient/SuiviCommandeClient.php`. Chaque étape vient des données ; une étape sans donnée reste « à venir », rien n'est deviné.

| Étape | Source | Ce que voit le client |
|---|---|---|
| Commande reçue | date de la commande | « 15/10 · 10:20 », « à confirmer par l'atelier » pour une commande en ligne pas encore confirmée |
| Acompte payé / Commande payée | paiements validés, Orange Money en validation | montant, heure, moyen, référence du paiement ; **pour qui paie seulement** |
| Fichier contrôlé | contrôle prépresse (`ControlePrePresse::isFichierControle`, date `controleLe`) et prénom de qui l'a contrôlé | « par Seydou (graphiste PAO) · 10:40 » ; « l'atelier vérifie votre fichier » pendant le contrôle ; « Fichier à revoir » si rejeté ; fait sans heure quand un BAT est déjà parti sans contrôle daté (création du graphiste sans fichier du client) |
| BAT à valider | BAT envoyés (suivi de conception) | « envoyé à 11:05 · en attente de vous » (« … de votre entreprise » pour la lecture seule), « Correction du BAT », « BAT validé » |
| En impression | `SuiviCommandeService::etapesClient` (débuts et fins de production des lignes) | « après validation du BAT », « lancée à … », « En finition », « Imprimée » |
| Prête | idem | date de fin, ou « prévue samedi 17/10 » (date de livraison de la commande) |
| Livrée / Retirée | bon de livraison | « En route » (heure de départ, créneau), « Livraison reportée » après un échec (sans la raison ni le livreur), date de remise ; « reste 11 500 F à payer au livreur » pour qui paie |

Une commande déjà imprimée dont l'atelier n'a enregistré ni contrôle ni BAT n'affiche pas ces deux étapes. Sur téléphone, le détail est raccourci (montant seul, heure seule, « en attente »). Une demande d'entreprise pas encore validée (ou refusée) n'a pas de suivi : elle n'est pas à l'atelier.

**Montants.** Total (et remise) : pour qui voit les montants (`coque.montants` : particulier, payeur, valideur). Acompte, payé, reste à payer, paiements Orange Money, « reste à payer au livreur » : pour qui paie (`peutPayer`). Rien pour un demandeur ni pour la lecture seule. « Facture et reçus » (qui paie) ouvre la facture de la commande (`app_espace_facture_detail`, maquette FP02), sinon « Factures et paiements ».

**Carte du BAT** (`templates/espace/app/_bat_carte.html.twig`, aussi sur `/espace/bat/{id}`) : « BAT n°1 · à valider », envoyé par qui et quand, l'image (ou le PDF) avec sa taille, son support, ses finitions et sa quantité, « Validez vite pour garder la livraison prévue samedi 17/10 » quand l'atelier a une date.

- **Avant de valider, vérifiez** : l'orthographe, les numéros de téléphone et les adresses, les couleurs et le logo, la taille (« La taille : 3 m × 1 m »). « Valider le BAT et lancer l'impression » ne s'active qu'une fois tout coché ; **le serveur le vérifie aussi** (`EspaceClient::verificationsCochees`) et refuse sinon. Les points cochés restent avec la validation (événement « BAT validé » du suivi de conception, remonté au local par la synchronisation) et s'affichent sous le BAT validé.
- **Qui valide** : la règle existante, tout le monde sauf un compte Lecture · suivi (il voit le BAT et « une personne de votre entreprise le valide »). La validation est notée au nom de la personne (`CompteClient::getNomAffiche`).
- **Demander une correction · 2 gratuites** : repères sur l'image et texte, comme avant. Les corrections gratuites se règlent dans **Réglages › Espace client › Corrections de BAT gratuites** (2 par défaut, par BAT ; réglage public, il descend au site). Au-delà, la demande reste possible : la page prévient qu'elle peut être facturée et la notification de la prépresse le dit ; aucun prix n'est calculé.
- **Historique du BAT** : fichier contrôlé, versions envoyées (prénom et métier du graphiste), demandes du client, validation et points vérifiés. Rien de la vie interne de l'atelier (chronomètre, erreurs, retard).

**Messages de la commande** : le fil Messages filtré sur la commande (`MessagesClient::fil($client, $commande)`), avec le champ pour écrire (il revient sur la page de la commande). Pas pour un compte Lecture · suivi. L'atelier répond depuis Clients › Messages, la commande choisie d'office.

**Pas encore là** (à brancher avec la nouvelle commande en ligne) : « 48 h après validation » et « Validez avant 16 h » demandent le délai de production et l'heure limite de la commande ; la page dit en attendant « après validation du BAT » et « Validez vite pour garder la livraison prévue … ».

Captures : `/mnt/project-files/captures/suivi-bat/`.

## Atelier 3D et « Mes créations » (construit le 14/10/2026)

`/espace/atelier-3d` : le client compose un t-shirt, un polo ou une casquette en 3D (couleur, logo, texte devant et dos, quantités par taille) et voit son prix (grille, prix B2B, paliers, promotion indiquée). « Commander » crée une commande « à confirmer » avec les vues jointes comme maquette ; un demandeur ne voit aucun montant et envoie une demande à son valideur. Entrées : carte en haut de « Nouvelle commande », « Mes créations » dans le menu et la puce de la page Commandes sur téléphone. `/espace/creations` : les modèles validés, à rouvrir ou à commander de nouveau. Pas pour un compte « Lecture · suivi ». Détails dans `docs/atelier-3d.md`.

La page d'une commande remplit sa frise étape par étape, et fête la commande prête par quelques confettis (une fois par appareil, jamais avec « réduire les animations »).

## Demande de conception (DC01 à DC05, construit le 14/10/2026)

`/espace/conception` : le client fait faire un visuel par un graphiste (acompte Orange Money) ou par l'IA (crédit IA, 1 000 F l'image), puis commande l'impression. Seuls un particulier, un administrateur ou un payeur paient, achètent du crédit ou génèrent ; un demandeur prépare et voit qui peut payer ; la lecture seule regarde. Détails, droits, règles d'argent et réglage du fournisseur d'images dans `docs/demande-conception.md`.
