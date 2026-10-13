# Espace client connecté (maquettes 13 à 19)

Le client se connecte par code SMS ou mot de passe (CX01), puis arrive dans son espace `/espace`. Il ne voit que sa propre fiche : chaque page passe par `src/Service/EspaceClient/EspaceClient.php`, qui filtre sur le client du compte connecté, et répond « introuvable » pour la commande, le BAT, le fichier, le devis ou la facture d'un autre client. Pas de coûts, de marges ni de noms d'employés.

## État (13/10/2026)

Construit, téléphone d'abord (onglets en bas), menu à gauche sur ordinateur :

- **Accueil (13)** : bandeau des BAT à valider, commandes en cours avec la frise Reçue › BAT › Impression › Finition › Prête, reste à payer avec le bouton Orange Money, devis à accepter, commandes récentes.
- **Mes commandes et suivi (15)** : liste avec état et avancement ; détail avec la frise datée (commande reçue, BAT validé par vous, impression, finition, prête à retirer ou à livrer, retirée ou livrée), livreur en route, montant et reste à payer, lien WhatsApp vers l'atelier (téléphone de la première entité).
- **Mes devis (14, à droite)** : filtres Tous, À accepter, Acceptés ; « Accepter » passe le devis envoyé en accepté et prévient le droit Devis ; PDF par le lien public signé.
- **Fichiers et BAT (16, à droite)** : BAT à valider et précédents ; page du BAT avec l'aperçu (image ou PDF) de la dernière version préparée par l'atelier, la frise des versions et des demandes du client. « Valider le BAT » note le BAT validé (horodaté, frise de conception, points du graphiste) et remet le contrôle à la prépresse pour la validation des fichiers et l'envoi en production ; « Demander une modification » repasse le contrôle en correction avec le texte du client. Sur un BAT en image, le client touche l'image pour poser des repères numérotés (10 au plus), chacun avec ce qu'il faut changer ; le graphiste les voit sur l'aperçu de son poste (R03) et dans le fil des échanges, et la version suivante les affiche au client avec « corrigé en version N » (maquette 16). Sur un PDF, la demande reste en texte. La prépresse est prévenue dans les deux cas.
- **Factures et paiement (17)** : solde à payer par commande, factures émises (PDF signé, proforma marquée), page de paiement. Tant que les clés Orange Money ne sont pas posées, la page donne le numéro marchand (tuile Entités) et la référence de commande ; le paiement est validé par la caisse.
- **Mon compte et notifications (19)** : coordonnées (modifiées par le bureau), réglage SMS, mot de passe, déconnexion ; fil des notifications reconstruit à partir des commandes (BAT prêt, paiement reçu, impression lancée, commande prête, livreur en route, livrée).

- **Nouvelle commande (14, à gauche)** : produits du catalogue publié (configurations actives avec un prix), largeur et hauteur en cm quand le produit se vend au m² ou sur mesure, finitions proposées (les obligatoires sont incluses), quantité, fichier choisi parmi les siens ou envoyé sur place, précisions pour l'atelier. Le total TTC indicatif est recalculé à chaque changement avec les prix du comptoir (prix B2B pour un client B2B) ; la commande arrive en brouillon « Reçue · à confirmer » (10 en attente au plus par client), sans paiement possible tant que l'atelier ne l'a pas confirmée. Au local, la fiche commande affiche « Commande passée par le client » avec le bouton Vérifier et confirmer.
- **Mes fichiers (16, à gauche)** : envoi PDF, AI, EPS, PSD, JPG, PNG, TIFF jusqu'à 500 Mo, par morceaux de 4 Mo repris après une coupure ; le type réel est vérifié (un fichier renommé est refusé), 5 Go par client. Contrôle automatique à l'arrivée (`AnalyseFichierImpression`) : résolution à la taille prévue si le client la donne, couleurs, polices, fonds perdus (3 mm, petits formats), proportions ; résumé OK, Attention ou À refaire. Mes logos (20 Mo au plus) gardés pour les prochaines commandes. Un fichier se retire tant qu'il n'est pas dans une commande. Les fichiers sont rangés hors de `public/` et servis seulement à leur client.

## Comptes entreprise (19, construit le 13/10/2026)

Une fiche entreprise ou B2B peut avoir plusieurs personnes, chacune avec son numéro et ses droits (`CompteClient.droits`). Un particulier n'a qu'un compte et fait tout. Code : `src/Service/EspaceClient/CompteEntreprise.php`, `src/Controller/EspaceEntrepriseController.php`.

| Droit | Ce qu'il permet |
|---|---|
| Administrateur (« gère le compte ») | tout, plus ajouter ou retirer des personnes, changer leurs droits, gérer les adresses |
| Valideur | accepter ou refuser les demandes, accepter les devis |
| Payeur | voir l'onglet Factures, le solde, le reste à payer, payer |
| Aucun (demandeur) | commander ; la commande attend un valideur |

- **Mon compte** : profil de la personne (son nom, son rôle), coordonnées de l'entreprise, ligne « Adresses de livraison », carte « Utilisateurs du compte » avec « + Ajouter ». La personne ajoutée reçoit un SMS et se connecte avec son propre numéro. 20 personnes au plus ; il reste toujours au moins un administrateur ; on ne change pas ses propres droits ; le compte principal ne se retire pas en ligne.
- **Adresses de livraison** : nom court, adresse, repère, personne à appeler ; une adresse par défaut, présélectionnée en commandant (« Livrer : Siège » ou « Retrait en boutique »).
- **Nouvelle commande** : choix de l'adresse, n° de bon de commande (imprimé sur la facture). Pour un demandeur, le bouton devient « Envoyer la demande » et les valideurs reçoivent un SMS sans montant.
- **Validation** : l'accueil du valideur montre « N demandes à valider » ; la page de la commande propose « Valider et envoyer à l'atelier » ou « Refuser » avec un motif lu par le demandeur. Sans aucun valideur actif, la demande part directement.
- **Synchronisation** : la demande monte au local sans prévenir l'atelier. La décision du valideur remonte (`validationEntreprise`, `valideePar`, `motifRefusEntreprise`) : validée, le droit Commande est prévenu comme pour toute commande en ligne ; refusée, le local la classe (supprimée) et le site la garde visible au client avec son motif. Au local, la fiche commande affiche « demandée en ligne par X » et le bon de commande.
- Les demandes à valider ou refusées n'apparaissent pas dans « À confirmer » (API comprise).

## Réclamations (18 à gauche, construit le 13/10/2026)

Le client signale un souci sur un travail depuis Mon compte › Réclamations, ou depuis une commande confirmée (« Un problème avec cette commande ? »). Code : `src/Service/EspaceClient/ReclamationsClients.php`, entités `ReclamationClient` et `ReclamationClientPhoto` (à ne pas confondre avec `Reclamation`, qui sert aux réclamations des employés).

- **Côté client** : commande concernée (facultative), motif (couleur, finition, quantité, texte, retard, livraison, autre), titre, description, jusqu'à 3 photos prises au téléphone. La liste montre le ticket (R-0001, W-R-0001 en ligne), l'état (Reçue, En cours, Résolue, Non retenue), les photos, la réponse de l'atelier et la durée de traitement (« Résolue en 1 h »). 5 réclamations ouvertes au plus par client.
- **Photos** : ce qui n'est pas une image est refusé ; la photo est réduite à 1600 px et réencodée, ce qui retire la position GPS et les autres données EXIF (si le serveur ne sait pas lire le JPEG, la photo est gardée telle quelle mais sans ses segments de métadonnées, 5 Mo au plus). Elles sont rangées en base, pas dans `public/`, et servies seulement au client de la fiche et aux droits Clients.
- **Côté atelier** : Clients › Réclamations, réservé au droit Clients (`ROLE_CLIENT`), avec une pastille sur le menu Clients pour les réclamations reçues sans réponse. La fiche montre la description, les photos, le client et la commande ; la réponse part avec un état : « En cours » la laisse ouverte, « Résolue » ou « Non retenue » la clôt. Le client reçoit un SMS sans détail (« nous avons répondu à votre réclamation R-0002 ») s'il accepte les SMS.
- **Synchronisation** : la réclamation et ses photos montent au local (types `reclamation` et `reclamation_photo`) et le droit Clients est prévenu ; la réponse et l'état redescendent sur le site.

Captures : `/mnt/project-files/captures/reclamations/`.

Reste à faire :

- Paiement Orange Money en ligne (clés dans `.env.local` du serveur).
- Fidélité (18 à droite) : règles de points, paliers et récompenses à fixer par Yaya.
- Informations entreprise (NIF, RCCM) modifiables en ligne : aujourd'hui en lecture, changées par le bureau.
