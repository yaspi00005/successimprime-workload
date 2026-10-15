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
- [ ] Fidélité : solde, progression vers le seuil, historique, sans nom d'agent ni motif interne.
- [ ] Les notifications (BAT prêt, commande prête, livreur en route, livrée) arrivent sans montant.

## Direction (demo.kadiatou)

- [ ] Tableau de bord : indicateurs du mois, production, encaissements du jour, alertes (sauvegarde, synchronisation, tâches en retard).
- [ ] Parcours complet : devis, acceptation, commande, BAT, production, livraison, facture, paiement validé.
- [ ] Finances F01 à F07 : vue d'ensemble, encaissements, dépenses et budgets, fournisseurs, trésorerie à 90 jours, rentabilité, finances du jour sur téléphone.
- [ ] « À valider » : un paiement saisi en ligne se valide seulement au local, avec un compte choisi.
- [ ] Réglages : chaque tuile s'ouvre ; une valeur changée est gardée.
- [ ] Rôles et menus : passer un module en « Voir » pour un rôle ; une personne de ce rôle voit l'écran mais toute modification est refusée.
- [ ] Statistiques : objectifs du mois, export Excel et PDF, rapport de la semaine.
- [ ] Contenu du site : publier une réalisation ; elle apparaît en ligne après la synchronisation. Un nom de client n'apparaît qu'avec l'accord coché.
- [ ] Fidélité : régler un article (activé, points, seuil, dates), ajuster les points d'un client avec un motif ; l'action est inscrite au journal.
- [ ] Synchronisation et sauvegardes : écrans à jour, « Synchroniser maintenant » fonctionne.

## Caisse et commercial (demo.aminata, puis demo.fanta)

- [ ] Menu court : Ma journée, Caisse, Mes actions, Clients, Devis et commandes, Encaissements.
- [ ] Actions de caisse : une facture échue, un devis sans réponse depuis 48 h puis à J+7, une commande prête ; le lien WhatsApp ouvre un message sans montant pour le devis.
- [ ] Encaisser un paiement sur une commande ; il apparaît dans la caisse du jour. La liste des comptes ne montre aucun solde.
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
- [ ] Ne voit pas : finances, montants des commandes, réglages, salaires.

## Opérateur production (demo.oumar)

- [ ] Tableau de production : passer un travail d'une colonne à l'autre.
- [ ] Planning des machines et m² du jour.
- [ ] Pointage par QR et photo à l'arrivée et au départ.
- [ ] Ne voit pas : montants, clients en détail, finances, réglages.

## Livreur (demo.bakary)

- [ ] L'accueil ouvre la tournée du jour ; barre du bas Tournée, Livrer, Pointage, Moi.
- [ ] « En route » puis « Livrée » avec photo, signature et position ; le client est prévenu.
- [ ] Encaissement à la livraison : il arrive en caisse à valider.
- [ ] Ne voit que ses livraisons : tableau de bord, ventes et finances refusés.

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

- **ERP local** : `php bin/console app:recette:acces` (un seul compte : `--compte=demo.oumar`). Chaque compte `demo.*` demande chaque page de l'admin ; la commande signale une page ouverte sans son droit, une entrée du menu qui répond « accès refusé », une erreur 500, des montants (marges, salaires, paiements, chiffre d'affaires) ou des photos de pointage montrés sans le droit, la fiche d'une autre personne (bulletin, justificatif, photo de pointage, preuve de livraison), et un module en « Voir » qui accepte un envoi.
- **Site en ligne** (`APP_EN_LIGNE=1`, après une synchronisation depuis l'ERP d'essai) : la même commande essaie l'espace client avec un particulier, un client B2B et chaque personne d'un compte entreprise (titulaire, valideur, payeur, demandeur, lecture), les fiches et les envois d'un autre client, aucun montant pour un demandeur ou la lecture seule (pages, données JSON des pages et appels de prix compris), l'admin sans appareil autorisé, l'API, les pages publiques, `robots.txt`, `sitemap.xml`, le suivi de commande et le calculateur.

Le bilan est écrit dans `var/recette/` (`acces-local-….md` et la matrice compte × page `…-matrice.csv` : O ouverte, R refusée, C connexion, > redirigée, I introuvable, E erreur). La commande se termine en échec s'il reste une fuite, une erreur ou un contrôle raté ; les pages « à examiner » sont à relire. Un écart voulu (page ouverte à tout le personnel, montant de ses propres ventes) s'ajoute avec sa raison dans `RecetteAcces::ECARTS_VOULUS` ou `MONTANTS_VOULUS` (`src/Service/Recette/`). Pour aller vite sur une grande base : `APP_DEBUG=0`.
