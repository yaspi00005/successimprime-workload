# Espace client connecté (maquettes 13 à 19)

Le client se connecte par code SMS ou mot de passe (CX01), puis arrive dans son espace `/espace`. Il ne voit que sa propre fiche : chaque page passe par `src/Service/EspaceClient/EspaceClient.php`, qui filtre sur le client du compte connecté, et répond « introuvable » pour la commande, le BAT, le fichier, le devis ou la facture d'un autre client. Pas de coûts, de marges ni de noms d'employés.

## État (12/10/2026)

Construit, téléphone d'abord (onglets en bas), menu à gauche sur ordinateur :

- **Accueil (13)** : bandeau des BAT à valider, commandes en cours avec la frise Reçue › BAT › Impression › Finition › Prête, reste à payer avec le bouton Orange Money, devis à accepter, commandes récentes.
- **Mes commandes et suivi (15)** : liste avec état et avancement ; détail avec la frise datée (commande reçue, BAT validé par vous, impression, finition, prête à retirer ou à livrer, retirée ou livrée), livreur en route, montant et reste à payer, lien WhatsApp vers l'atelier (téléphone de la première entité).
- **Mes devis (14, à droite)** : filtres Tous, À accepter, Acceptés ; « Accepter » passe le devis envoyé en accepté et prévient le droit Devis ; PDF par le lien public signé.
- **Fichiers et BAT (16, à droite)** : BAT à valider et précédents ; page du BAT avec l'aperçu (image ou PDF) de la dernière version préparée par l'atelier, la frise des versions et des demandes du client. « Valider le BAT » note le BAT validé (horodaté, frise de conception, points du graphiste) et remet le contrôle à la prépresse pour la validation des fichiers et l'envoi en production ; « Demander une modification » repasse le contrôle en correction avec le texte du client. La prépresse est prévenue dans les deux cas.
- **Factures et paiement (17)** : solde à payer par commande, factures émises (PDF signé, proforma marquée), page de paiement. Tant que les clés Orange Money ne sont pas posées, la page donne le numéro marchand (tuile Entités) et la référence de commande ; le paiement est validé par la caisse.
- **Mon compte et notifications (19)** : coordonnées (modifiées par le bureau), réglage SMS, mot de passe, déconnexion ; fil des notifications reconstruit à partir des commandes (BAT prêt, paiement reçu, impression lancée, commande prête, livreur en route, livrée).

- **Nouvelle commande (14, à gauche)** : produits du catalogue publié (configurations actives avec un prix), largeur et hauteur en cm quand le produit se vend au m² ou sur mesure, finitions proposées (les obligatoires sont incluses), quantité, fichier choisi parmi les siens ou envoyé sur place, précisions pour l'atelier. Le total TTC indicatif est recalculé à chaque changement avec les prix du comptoir (prix B2B pour un client B2B) ; la commande arrive en brouillon « Reçue · à confirmer » (10 en attente au plus par client), sans paiement possible tant que l'atelier ne l'a pas confirmée. Au local, la fiche commande affiche « Commande passée par le client » avec le bouton Vérifier et confirmer.
- **Mes fichiers (16, à gauche)** : envoi PDF, AI, EPS, PSD, JPG, PNG, TIFF jusqu'à 500 Mo, par morceaux de 4 Mo repris après une coupure ; le type réel est vérifié (un fichier renommé est refusé), 5 Go par client. Contrôle automatique à l'arrivée (`AnalyseFichierImpression`) : résolution à la taille prévue si le client la donne, couleurs, polices, fonds perdus (3 mm, petits formats), proportions ; résumé OK, Attention ou À refaire. Mes logos (20 Mo au plus) gardés pour les prochaines commandes. Un fichier se retire tant qu'il n'est pas dans une commande. Les fichiers sont rangés hors de `public/` et servis seulement à leur client.

Reste à faire :

- Paiement Orange Money en ligne (clés dans `.env.local` du serveur).
- Réclamations clients et fidélité (18) : nouvelles tables ; règles de points, paliers et récompenses à fixer par Yaya.
- Utilisateurs du compte entreprise (demandeur, valideur, payeur) et adresses de livraison (19).
