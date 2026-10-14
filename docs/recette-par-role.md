# Recette par rôle (jalon J7)

Liste des essais à faire avant la mise en service, rôle par rôle. Chaque ligne dit ce qu'on fait et ce qu'on doit voir. Une ligne « ne voit pas » se vérifie aussi en tapant l'adresse de la page à la main : la réponse attendue est « accès refusé » ou la page de connexion.

Données d'essai : `php bin/console app:demo` (comptes `demo.*`, mot de passe `demo1234` ; clients `DEMO-…`, qui ne reçoivent jamais de SMS ni d'e-mail). Sur le site en ligne de test, le client 76000001 a le mot de passe `test1234`.

| Rôle | Compte d'essai |
|---|---|
| Direction | demo.kadiatou |
| Caisse et commercial | demo.aminata (droits du rôle seulement), demo.fanta (droits ajoutés : RH, trésorerie, paiements, statistiques) |
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

- [ ] Connexion par code SMS, puis par mot de passe ; 5 essais faux bloquent 15 minutes.
- [ ] Nouvelle commande avec fichier : elle arrive au local en brouillon « à confirmer » et ne peut pas être payée avant la confirmation.
- [ ] BAT : poser des repères sur l'image, demander une modification ; au local, la prépresse reçoit le texte. Valider un autre BAT.
- [ ] Factures et paiement Orange Money : le paiement arrive au local « en attente », sans compte, et n'est jamais validé tout seul.
- [ ] Réclamation avec photo : la photo n'est visible que du client et du personnel autorisé.
- [ ] Mes produits : recommander un produit ; le prix vient de la fiche du produit, pas de la page.
- [ ] Compte entreprise : un demandeur ne voit ni factures ni solde ; seul un valideur accepte un devis ; seul un payeur paie.
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
- [ ] Encaisser un paiement sur une commande ; il apparaît dans la caisse du jour.
- [ ] Clients : liste, nouvelle fiche, fiche avec historique, journal.
- [ ] demo.aminata ne voit pas : stock et achats, réglages, salaires, montants de trésorerie, statistiques globales.
- [ ] demo.fanta voit en plus la trésorerie, les paiements et les statistiques, mais toujours pas les réglages ni le stock.

## Graphiste PAO (demo.seydou)

- [ ] Menu replié en icônes ; le poste PAO s'ouvre avec ses travaux, l'aperçu et le chronomètre.
- [ ] Démarrer, mettre en pause, envoyer le BAT ; le client le voit dans son espace.
- [ ] Outil de montage : poser plusieurs fichiers sur une feuille.
- [ ] Ne voit pas : finances, montants des commandes, réglages, salaires.
- [ ] Ne fait pas de factures et ne voit ni les créances ni les paiements (décision du 14/10/2026). Le droit se rend à une personne précise depuis sa fiche.

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

## Personnel, sur la tablette d'entrée et en ligne

- [ ] Accès par lien WhatsApp et code PIN ; 5 PIN faux bloquent le compte un moment.
- [ ] Pointage QR et photo ; les photos ne sont visibles que des personnes du rôle RH.
- [ ] Demande d'absence ou de congé, puis réponse du responsable.
- [ ] Un salaire n'est visible que de l'équipe RH et de la personne concernée.

## Administration en ligne

- [ ] Depuis un appareil non autorisé, le bon mot de passe est refusé et les administrateurs sont prévenus une fois par jour.
- [ ] Depuis un appareil autorisé : mot de passe, puis code SMS ; la session se ferme après 15 minutes sans activité.
- [ ] Un appareil en « Encaissement » ne peut qu'encaisser ou livrer ; toute autre modification est refusée.
- [ ] L'API répond « introuvable » en ligne ; les dépenses, budgets et relevés Orange Money restent sur l'ERP local.

## Technique

- [ ] `php bin/console app:verifier-installation` sans erreur sur les deux machines (docs/deploiement.md).
- [ ] Coupure d'internet à l'atelier pendant 20 minutes : l'alerte part une fois, puis tout passe au retour, sans doublon.
- [ ] Restauration d'une sauvegarde sur une machine d'essai (docs/sauvegardes.md).
- [ ] L'API refuse un jeton inconnu et répond « introuvable » en ligne (docs/api.md).
