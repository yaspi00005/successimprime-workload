# Espace client connecté (maquettes 13 à 19)

Le client se connecte par code SMS ou mot de passe (CX01), puis arrive dans son espace `/espace`. Il ne voit que sa propre fiche : chaque page passe par `src/Service/EspaceClient/EspaceClient.php`, qui filtre sur le client du compte connecté, et répond « introuvable » pour la commande, le BAT, le fichier, le devis ou la facture d'un autre client. Pas de coûts, de marges ni de noms d'employés.

## État (11/10/2026)

Construit, téléphone d'abord (onglets en bas), menu à gauche sur ordinateur :

- **Accueil (13)** : bandeau des BAT à valider, commandes en cours avec la frise Reçue › BAT › Impression › Finition › Prête, reste à payer avec le bouton Orange Money, devis à accepter, commandes récentes.
- **Mes commandes et suivi (15)** : liste avec état et avancement ; détail avec la frise datée (commande reçue, BAT validé par vous, impression, finition, prête à retirer ou à livrer, retirée ou livrée), livreur en route, montant et reste à payer, lien WhatsApp vers l'atelier (téléphone de la première entité).
- **Mes devis (14, à droite)** : filtres Tous, À accepter, Acceptés ; « Accepter » passe le devis envoyé en accepté et prévient le droit Devis ; PDF par le lien public signé.
- **Fichiers et BAT (16, à droite)** : BAT à valider et précédents ; page du BAT avec l'aperçu (image ou PDF) de la dernière version préparée par l'atelier, la frise des versions et des demandes du client. « Valider le BAT » note le BAT validé (horodaté, frise de conception, points du graphiste) et remet le contrôle à la prépresse pour la validation des fichiers et l'envoi en production ; « Demander une modification » repasse le contrôle en correction avec le texte du client. La prépresse est prévenue dans les deux cas.
- **Factures et paiement (17)** : solde à payer par commande, factures émises (PDF signé, proforma marquée), page de paiement. Tant que les clés Orange Money ne sont pas posées, la page donne le numéro marchand (tuile Entités) et la référence de commande ; le paiement est validé par la caisse.
- **Mon compte et notifications (19)** : coordonnées (modifiées par le bureau), réglage SMS, mot de passe, déconnexion ; fil des notifications reconstruit à partir des commandes (BAT prêt, paiement reçu, impression lancée, commande prête, livreur en route, livrée).

Reste à faire :

- Nouvelle commande avec prix indicatif (14, à gauche) et envoi de fichiers avec contrôle automatique (16, à gauche), avec `AnalyseFichierImpression`.
- Paiement Orange Money en ligne (clés dans `.env.local` du serveur).
- Réclamations clients et fidélité (18) : nouvelles tables ; règles de points, paliers et récompenses à fixer par Yaya.
- Utilisateurs du compte entreprise (demandeur, valideur, payeur) et adresses de livraison (19).
