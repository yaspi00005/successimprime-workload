# Recette par rôle (jalon J7)

Liste des essais à faire avant la mise en service, rôle par rôle. Chaque ligne dit ce qu'on fait et ce qu'on doit voir. Une ligne « ne voit pas » se vérifie aussi en tapant l'adresse de la page à la main : la réponse attendue est « accès refusé » ou la page de connexion.

Données d'essai : `php bin/console app:demo` (comptes `demo.*`, mot de passe `demo1234` ; clients `DEMO-…`, qui ne reçoivent jamais de SMS ni d'e-mail). Sur le site en ligne de test, le client 76000001 a le mot de passe `test1234`.

| Rôle | Compte d'essai |
|---|---|
| Direction | demo.kadiatou |
| Caisse et commercial | demo.aminata (droits du rôle seulement, sans la trésorerie), demo.moussa (sans les virements), demo.fanta (droits ajoutés : RH, trésorerie, paiements, statistiques) |
| Graphiste PAO | demo.seydou |
| Opérateur production | demo.oumar |
| Livreur | demo.bakary |
| Client | 76000001 sur l'espace client en ligne |

## Visiteur du site (en ligne, sans compte)

- [ ] L'accueil s'ouvre sur l'adresse du site, avec le menu, « Espace client » et « Demander un devis ».
- [ ] Sur téléphone, le menu s'ouvre par le bouton à trois traits ; le bouton WhatsApp ouvre une discussion avec l'atelier.
- [ ] Le calculateur donne un prix public pour une bâche aux dimensions choisies ; il n'affiche jamais un prix B2B.
- [ ] Une demande de devis sans compte affiche un numéro de suivi ; au local, un prospect « Site » et un devis brouillon apparaissent.
- [ ] « Suivre ma commande » avec un numéro et le bon téléphone montre les étapes, sans montant ; un mauvais téléphone donne le même message qu'une commande inconnue.
- [ ] Aucune page publique ne montre un nom de client sans accord, un montant d'un client, un coût ou une marge.
- [ ] /robots.txt et /sitemap.xml répondent ; l'ERP local répond « Disallow: / ».
- [ ] Sans action sociale publiée avec texte et photo, /nos-actions-sociales répond 404 et n'apparaît ni dans le pied de page, ni sur l'accueil, ni dans /sitemap.xml ; après la publication d'une action au local et la synchronisation, tout apparaît (jamais un brouillon).
- [ ] « Nous soutenir » (accueil, pied de page) ouvre la fiche Google dans un nouvel onglet ; le QR code s'affiche sur ordinateur ; lien vidé dans les réglages : plus rien.

## Client (espace client en ligne)

- [ ] Connexion par numéro et mot de passe ; 5 essais faux bloquent 15 minutes.
- [ ] « Recevoir un code » : par e-mail si la fiche a une adresse, sinon par SMS ; destination masquée ; puis choix du mot de passe obligatoire.
- [ ] Fiche client (ERP local) › « Envoyer l'accès à l'espace client » : message WhatsApp avec identifiant, code et lien ; première connexion avec ce code, mot de passe obligatoire et e-mail facultatif ; le même code ne sert pas deux fois.
- [ ] Nouvelle commande avec fichier : elle arrive au local en brouillon « à confirmer » et ne peut pas être payée avant la confirmation.
- [ ] BAT : poser des repères sur l'image, demander une modification ; au local, la prépresse reçoit le texte. Valider un autre BAT.
- [ ] Factures et paiement Orange Money : le paiement arrive au local « en attente », sans compte, et n'est jamais validé tout seul.
- [ ] Réclamation avec photo : la photo n'est visible que du client et du personnel autorisé.
- [ ] Mes produits : recommander un produit ; le prix vient de la fiche du produit, pas de la page.
- [ ] Compte entreprise : un demandeur ne voit ni factures ni solde, ni aucun prix (commande, devis, Mes produits, Nouvelle commande, conception) ; seul un valideur accepte un devis ; seul un payeur paie.
- [ ] Plusieurs personnes d'une entreprise commandent chacune avec son numéro : la demande d'un demandeur attend un valideur, qui la voit « demandée par X » ; au local, la commande montre qui l'a demandée et qui l'a validée.
- [ ] Accès suspendu (fiche client, ERP local, puis synchronisation) : la personne lit « Votre accès à l'espace client est suspendu. Contactez Succès Imprim. » après son mot de passe, sa session ouverte se ferme, elle ne reçoit plus de notification ; les autres personnes de l'entreprise entrent toujours. « Rétablir » rend l'accès.
- [ ] Bandes d'annonces : une annonce « Espace client » s'affiche en haut des pages connectées, pas sur le site public ; « × » la ferme pour la visite.
- [ ] Fidélité : solde, progression vers le seuil, historique, sans nom d'agent ni motif interne.
- [ ] Les notifications (BAT prêt, commande prête, livreur en route, livrée) arrivent sans montant.
- [ ] Carte « Nous soutenir » du tableau de bord : « × » la ferme pour cette personne seulement (elle ne revient pas, les autres personnes de l'entreprise la gardent) ; une commande livrée montre l'invitation « Avis Google ».
- [ ] « Mon solde » (particulier, titulaire, payeur) : solde, relevé de la boutique sans nom d'employé, dépôts en ligne et leur état ; « Voir les frais » montre le dépôt, les frais en plus et le total ; le dépôt arrive au local « reçu », jamais validé tout seul. Le valideur et le demandeur reçoivent « accès refusé », la lecture seule revient à l'accueil ; aucun n'a la tuile « Mon solde ».

## Direction (demo.kadiatou)

- [ ] Tableau de bord : indicateurs du mois, production, encaissements du jour, alertes (sauvegarde, synchronisation, tâches en retard).
- [ ] Parcours complet : devis, acceptation, commande, BAT, production, livraison, facture, paiement validé.
- [ ] Finances F01 à F07 : vue d'ensemble, encaissements, dépenses et budgets, fournisseurs, trésorerie à 90 jours, rentabilité, finances du jour sur téléphone.
- [ ] « À valider » : un paiement saisi en ligne se valide seulement au local, avec un compte choisi.
- [ ] Compte du client (fiche › « Compte ») : dépôt (reçu D-…), « Rendre l'argent » avec motif et confirmation (reçu RB-…), jamais plus que le disponible ; un dépôt en ligne se valide ou se refuse ; Finances › Comptabilité › Balance : le compte 4191 égale le total de « Soldes clients ».
- [ ] Réglages : chaque tuile s'ouvre ; une valeur changée est gardée.
- [ ] Paramètres › Produits et options › Noms commerciaux : donner « Autocollant » au vinyle adhésif ; la liste des produits montre « Vinyle adhésif Autocollant » ; la saisie d'un devis trouve « autocollant » ; le devis PDF montre « Autocollant » puis « Vinyle adhésif » en plus petit. Après la synchronisation, « autocollant » trouve le produit dans « Nos produits » du site. La page est refusée aux autres rôles (docs/noms-commerciaux.md).
- [ ] Rôles et menus : passer un module en « Voir » pour un rôle ; une personne de ce rôle voit l'écran mais toute modification est refusée.
- [ ] Statistiques : objectifs du mois, export Excel et PDF, rapport de la semaine.
- [ ] Rapports par e-mail : aperçu du jour, de la semaine et du mois ; « M'envoyer un test » arrive avec le PDF ; cocher un destinataire, voir sa version (« Version de… ») ; l'historique note chaque envoi ([docs/rapports-mail.md](rapports-mail.md)).
- [ ] Contenu du site : publier une réalisation ; elle apparaît en ligne après la synchronisation. Un nom de client n'apparaît qu'avec l'accord coché.
- [ ] Équipe (Réglages › Site vitrine › Équipe) : une personne visible, avec photo et l'accord coché, apparaît dans « Notre équipe » de « Qui sommes-nous ? » après la synchronisation ; décocher son accord retire sa photo et la retire du site ; sans personne affichée, les cartes Direction, Atelier, PAO reviennent.
- [ ] Mot du gérant (même onglet, « Rédiger le mot du gérant ») : caché tant que la photo ou le texte manque ; rempli, il paraît avant « Notre équipe », et le gérant n'est pas répété dans la grille sauf « Le montrer aussi dans « Notre équipe » » ; cocher « Gérant » sur une autre fiche le décoche sur la première.
- [ ] Annonces (Réglages › Site vitrine › Annonces) : une annonce active pour le site public apparaît au-dessus de l'en-tête après la synchronisation ; programmée, elle n'apparaît qu'à son premier jour ; inactive ou terminée, plus du tout.
- [ ] Fiche client › Accès à l'espace client : suspendre une personne avec un motif, puis tout le compte ; le journal de la fiche note qui, quand et pourquoi ; « Rétablir » ; en « Voir », aucun bouton.
- [ ] Devis « À l'attention de » et commande « Commandé par » : la liste propose les contacts de la fiche ; le nom est imprimé sur le PDF du devis et sur la facture, et se lit sous le numéro dans la fiche client.
- [ ] Fidélité : régler un article (activé, points, seuil, dates), ajuster les points d'un client avec un motif ; l'action est inscrite au journal.
- [ ] Crédits SMS et e-mail (Clients) : sans les clés Orange, saisir le solde et la fin du pack, puis la fin de l'abonnement e-mail ; un SMS ou un e-mail envoyé apparaît dans « Historique des envois » sans son texte ; « Export CSV » ; sous le seuil ou à moins de 15 jours de la fin, une seule notification.
- [ ] Synchronisation et sauvegardes : écrans à jour, « Synchroniser maintenant » fonctionne.

## Caisse et commercial (demo.aminata, puis demo.fanta)

- [ ] Menu court : Ma journée, Caisse, Mes actions, Clients, Devis et commandes, Encaissements.
- [ ] Actions de caisse : une facture échue, un devis sans réponse depuis 48 h puis à J+7, une commande prête ; le lien WhatsApp ouvre un message sans montant pour le devis.
- [ ] Encaisser un paiement sur une commande ; il apparaît dans la caisse du jour. La liste des comptes ne montre aucun solde.
- [ ] Caisse, client `DEMO-0122` : « Payer avec le solde » une partie, puis encaisser le reste en espèces avec un « Montant remis » plus grand et « Garder la monnaie sur le compte du client » ; le reçu dit « Monnaie gardée sur votre compte ». « Dépôt » donne le reçu « Dépôt sur votre compte ». « Rendre l'argent » est là (droit donné d'office au rôle Caisse depuis le 18/10/2026) ; il disparaît pour une personne à qui la Direction l'a retiré sur sa fiche.
- [ ] Finances › Dépenses (aussi par « Aller à… › Nouveau › Dépense ») : saisir une dépense avec son reçu ; seules ses propres dépenses sont listées, sans budget.
- [ ] Caisse › « Virement » : verser la caisse sur le compte de la banque ; la banque n'est pas proposée comme compte source ; aucun solde dans le formulaire ni dans le message, retour à la caisse.
- [ ] Clôturer la caisse : le théorique de sa caisse est affiché, l'écart est signalé à la direction.
- [ ] Clients : liste, nouvelle fiche, fiche avec historique, journal.
- [ ] demo.aminata ne voit pas : stock et achats, réglages, salaires, montants de trésorerie (soldes des comptes, Finances › Trésorerie, Fournisseurs, liste des mouvements), statistiques globales.
- [ ] demo.moussa n'a pas le bouton « Virement » ; le formulaire ne lui propose que l'entrée et la sortie, sans la banque.
- [ ] demo.fanta voit en plus la trésorerie, les paiements et les statistiques, mais toujours pas les réglages ni le stock.

## Graphiste PAO (demo.seydou)

- [ ] Menu replié en icônes ; le poste PAO s'ouvre avec ses travaux, l'aperçu et le chronomètre.
- [ ] Démarrer, mettre en pause, envoyer le BAT ; le client le voit dans son espace.
- [ ] Outil de montage : poser plusieurs fichiers sur une feuille.
- [ ] Ne voit pas : finances, montants des commandes, réglages, salaires, ni l'onglet « Compte » d'une fiche client.

## Opérateur production (demo.oumar)

- [ ] Ma file machine : démarrer puis terminer un travail ; sur le tableau de production, la carte avance d'elle-même à la colonne suivante (on ne la déplace pas à la main).
- [ ] Planning des machines et m² du jour.
- [ ] Pointage par QR et photo à l'arrivée et au départ.
- [ ] Ne voit pas : montants, clients en détail, finances, réglages.

## Livreur (demo.bakary)

- [ ] L'accueil ouvre la tournée du jour (Ma tournée) ; menu Ma tournée, Mes livraisons et Mon espace ; barre du bas Tournée, Livraisons, Pointage, Moi. Ni recherche, ni messagerie, ni « Aller à… ».
- [ ] Sur le téléphone : appeler le client d'un toucher, ouvrir l'adresse sur la carte ; le reste à encaisser n'apparaît que sur ses propres livraisons.
- [ ] « Je pars » puis « Livrée » avec le nom de la personne qui reçoit, la signature, une photo et la position ; le client est prévenu.
- [ ] « Échec » avec une raison : le bureau et la Direction reçoivent « à replanifier » ; le client voit « Reportée » dans son espace, sans la raison.
- [ ] Mes livraisons : ses remises et ses échecs des 30 derniers jours, sans montant, jamais ceux d'un autre livreur.
- [ ] Encaissement à la livraison : il arrive en caisse à valider.
- [ ] Ne voit que sa tournée et ses livraisons : tableau de bord, clients, commandes, statistiques, recherche, messagerie, planning, liste des livraisons, « À retirer » et suivi refusés, même tapés à la main.

## Suivi des livraisons (demo.kadiatou, demo.fanta, demo.seydou)

- [ ] Ventes › Suivi des livraisons : filtrer par jour, livreur et statut ; chaque livraison dit si elle est livrée ou pas, à quel moment, par qui et qui l'a reçue (ou la raison de l'échec). Aucun montant.
- [ ] Page d'une commande : bloc « Livraison » avec le statut, le moment, le livreur et la personne qui a reçu.
- [ ] Sur le site en ligne, `/livraisons/suivi` et `/livraisons/tournee` répondent « page introuvable ».

## Guide et formation (jalon J7)

- [ ] « ? Aide » en haut de chaque écran, sur le bureau et le téléphone : les fiches du rôle, avec en premier celles de l'écran quitté (docs/guide-utilisateur.md).
- [ ] demo.aminata, demo.seydou et demo.bakary tapent l'adresse d'une fiche d'un autre rôle (`/aide/a-valider`, `/aide/tournee`, `/aide/caisse-virement`) ou de son image : « accès refusé ».
- [ ] « Imprimer mon guide » : couverture, sommaire, une page A4 par fiche, seulement les fiches de la personne.
- [ ] « Fiches du formateur » (`/aide/formation`) : Direction seulement.
- [ ] Sur le site en ligne, `/aide` répond « page introuvable ».
- [ ] Une personne nouvelle, après sa demi-journée « Caisse et commercial » (docs/formation.md), réussit seule l'exercice final J7 : client, devis, commande, acompte encaissé avec son reçu, facture.

## Personnel, sur la tablette d'entrée et en ligne

- [ ] Accès par lien WhatsApp et code PIN ; 5 PIN faux bloquent le compte un moment.
- [ ] Pointage QR et photo ; les photos ne sont visibles que des personnes du rôle RH.
- [ ] Demande d'absence ou de congé, puis réponse du responsable.
- [ ] Un salaire n'est visible que de l'équipe RH et de la personne concernée.

## Administration en ligne

- [ ] Depuis un appareil non autorisé, le bon mot de passe est refusé et les administrateurs sont prévenus une fois par jour. Le message est le même avec un mauvais mot de passe ou un identifiant inconnu (« Cet appareil n'est pas autorisé… »).
- [ ] Depuis un appareil autorisé : mot de passe, puis code de sécurité par e-mail (par SMS sans adresse) ; la session se ferme après 15 minutes sans activité.
- [ ] Un appareil en « Encaissement » ne peut qu'encaisser ou livrer ; toute autre modification est refusée.
- [ ] L'API répond « introuvable » en ligne ; les dépenses, budgets et relevés Orange Money restent sur l'ERP local.

## Technique

- [ ] `php bin/console app:verifier-installation` sans erreur sur les deux machines (docs/deploiement.md).
- [ ] Coupure d'internet à l'atelier pendant 20 minutes : l'alerte part une fois, puis tout passe au retour, sans doublon.
- [ ] Restauration d'une sauvegarde sur une machine d'essai (docs/sauvegardes.md).
- [ ] L'API refuse un jeton inconnu et répond « introuvable » en ligne (docs/api.md).

## Vérification automatique des accès

Avant de cocher cette liste, et avant chaque mise en production, une commande ouvre chaque page avec chaque compte de démonstration et compare ce qu'elle obtient aux droits. Elle se lance sur une base d'essai qui a la démonstration (`app:demo`), jamais en production (elle refuse). Elle n'enregistre rien : les pages sont demandées dans une transaction annulée à la fin, sans aucun envoi de SMS, d'e-mail ni de notification.

- **ERP local** : `php bin/console app:recette:acces` (un seul compte : `--compte=demo.oumar`). Chaque compte `demo.*` demande chaque page de l'admin ; la commande signale une page ouverte sans son droit, une entrée du menu qui répond « accès refusé », une erreur 500, des montants (marges, salaires, paiements, chiffre d'affaires) ou des photos de pointage montrés sans le droit, la fiche d'une autre personne (bulletin, justificatif, photo de pointage, preuve de livraison, échec noté sur la livraison d'un autre livreur, livraisons d'un autre dans « Mes livraisons », journées de pointage d'un autre dans « Mon pointage », même avec son identifiant dans l'adresse), un module en « Voir » qui accepte un envoi, et les crédits SMS et e-mail (écran, historique, export) ouverts sans leur droit ou montrant un numéro, une adresse ou un objet d'e-mail en entier sans le droit Clients (le destinataire d'un message au personnel, ou sa recherche, sans être la direction). Rapports par e-mail : hors Direction, choisir les destinataires ou renvoyer un rapport est refusé, l'aperçu ne montre jamais la version d'un autre, l'e-mail de chacun a exactement les parties que ses droits ouvrent, et un compte qui n'a que « Chiffre d'affaires global » ne voit dans son e-mail ni montant, ni nom de client, ni partie réservée. Compte du client : l'onglet « Compte » d'une fiche n'est montré qu'avec Paiements ou Encaissements, un dépôt n'atteint le serveur qu'avec Encaissements et « Rendre l'argent » qu'avec en plus le droit de le faire. Les onglets « Actions sociales » et « Nous soutenir » de Réglages › Site vitrine sont essayés avec chaque compte (ouverts avec le droit Site vitrine, refusés sinon, enregistrement refusé), et le site public avec et sans action sociale montrée (page, accueil, pied de page, plan du site, brouillon jamais montré, lien « Nous soutenir » en nouvel onglet, QR code). La suppression d'un client (bouton « Supprimer » de la fiche, Direction seulement) n'arrive au serveur qu'avec la Direction. Pour la corbeille, elle pose deux entrées d'essai (une adresse de client, un jour férié) et vérifie que chaque compte n'ouvre l'écran qu'avec la Direction ou le droit « Corbeille », n'ouvre, ne restaure ni ne supprime une entrée sans le droit de sa fiche, et qu'en « Voir » rien ne se restaure (docs/corbeille.md). Pour le livreur seul, toute page ouverte hors de sa tournée, de ses livraisons et de son espace est une fuite, et l'accueil doit le renvoyer sur sa tournée.
- **Site en ligne** (`APP_EN_LIGNE=1`, après une synchronisation depuis l'ERP d'essai) : la même commande essaie l'espace client avec un particulier, un client B2B et chaque personne d'un compte entreprise (titulaire, valideur, payeur, demandeur, lecture), les fiches et les envois d'un autre client, « Mon solde » réservé à qui paie (un dépôt lancé par le valideur, le demandeur ou la lecture est refusé et ne crée rien), aucun montant pour un demandeur ou la lecture seule (pages, données JSON des pages et appels de prix compris), la session d'une personne suspendue par la boutique (fermée à la page suivante, les autres personnes de l'entreprise entrent toujours), la carte « Nous soutenir » fermée par une personne (« Lecture · suivi » comprise) et gardée pour les autres, l'admin sans appareil autorisé (corbeille comprise), l'API, les pages publiques, aucune personne de l'équipe masquée ou sans son accord sur « Qui sommes-nous ? » (trois témoins ajoutés le temps du contrôle, plus les personnes cachées de la base), aucun mot du gérant caché (deux gérants témoins, sans accord et sans photo) et le mot du gérant de la base montré une seule fois, `robots.txt`, `sitemap.xml`, le suivi de commande et le calculateur.

Le bilan est écrit dans `var/recette/` (`acces-local-….md` et la matrice compte × page `…-matrice.csv` : O ouverte, R refusée, C connexion, > redirigée, I introuvable, E erreur). La commande se termine en échec s'il reste une fuite, une erreur ou un contrôle raté ; les pages « à examiner » sont à relire. Un écart voulu (page ouverte à tout le personnel, montant de ses propres ventes) s'ajoute avec sa raison dans `RecetteAcces::ECARTS_VOULUS` ou `MONTANTS_VOULUS` (`src/Service/Recette/`). Pour aller vite sur une grande base : `APP_DEBUG=0`.
