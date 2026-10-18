# Suivi des commandes : liste, tableau par étape, fiche

Maquettes de Yaya du 18/10/2026 : GC01 (liste), GC02 (tableau par étape), DT02 (fiche d'une commande côté admin), DT04 (fiche dans l'espace client sur téléphone, voir docs/espace-client.md). Les adresses ne changent pas : `/commandes/` et `/commandes/{id}`.

## L'étape d'une commande

Chaque commande a une étape « commerciale », calculée à partir de ce qui existe déjà (lignes, contrôles prépresse, paiements, bons de livraison). Rien n'est saisi en plus.

| Étape | Quand |
|---|---|
| À confirmer | commande passée en ligne que l'atelier n'a pas encore vérifiée (ou qui attend le valideur de l'entreprise) |
| Acompte attendu | l'acompte n'est pas payé et rien n'est encore imprimé |
| Fichier / BAT | une ligne attend son contrôle, sa PAO ou la réponse du client au BAT |
| En impression | toutes les lignes ont leur BAT validé (ou n'en ont pas besoin) ; impression à faire ou en cours |
| Prête | tout est imprimé, à retirer ou à livrer |
| En livraison | le livreur est parti |
| Livrée | tout est livré ou retiré |
| Annulée | toutes les lignes sont annulées |

- La commande avance au pas de sa ligne la moins avancée. Une ligne sans production (conception, frais, crédit IA) ne la retient pas.
- **L'acompte demandé** : celui de la fiche du client, sinon Réglages › Paiements › Acompte par défaut (50 %). À 0 %, jamais « Acompte attendu ». Une fois l'impression lancée sans l'acompte (client à crédit), la commande n'est plus montrée bloquée.
- **En retard** : la date « pour le » (livraison ou retrait) est passée et la commande n'est ni livrée ni annulée. L'étape s'affiche alors en rouge : « BAT en retard », « Impression en retard »…
- **Responsable** : la personne qui a la main à cette étape — le graphiste du BAT, l'opérateur de l'impression, le livreur — sinon la personne qui a saisi la commande.

Dans le code : `src/Service/Commandes/SuiviCommercial.php` (testé dans `tests/Unit/Service/Commandes/SuiviCommercialTest.php`). Tout est chargé en quelques requêtes pour toute la liste.

## Liste (GC01)

- **Cartes du haut**, toujours sur les commandes actives quels que soient les filtres : En cours, À livrer aujourd'hui (dont en livraison), En retard (avant impression / en production ou livraison), Acomptes attendus, Reste à encaisser (commandes en cours et livrées non soldées). Un clic sur une carte filtre la liste ; un second clic enlève le filtre.
- **Puces d'étape** avec leur nombre (Toutes, À confirmer, Acompte attendu, Fichier / BAT, En impression, Prêtes, En livraison, Livrées, En retard). « À confirmer » et « Livrées » n'apparaissent que s'il y en a.
- **Colonnes** : N°, client avec sa pastille, produit (la première ligne, « +2 » s'il y en a d'autres), montant, barre « Payé » en %, étape, pour le, responsable, crayon « Modifier » (tant qu'aucune ligne n'est en production). Toute la ligne ouvre la commande. Les petites flèches de filtre des colonnes restent (docs/filtres-tableaux.md).
- **Recherche** : l'ancienne recherche avancée (n°, client, état des travaux, situation financière, dates, montants, actives ou toutes, tri) est dans le panneau « Recherche », ouvert d'office quand une recherche est posée. Les filtres restent en mémoire pour la session, « Réinitialiser » les efface.
- **Exporter** : `/commandes/export`, un fichier CSV pour Excel des commandes affichées (mêmes filtres et même étape).
- **Devis** ouvre la liste des devis, **+ Commande** la saisie.
- Téléphone : les cartes passent sur deux colonnes, les puces défilent, chaque commande devient une petite carte (n° et étape, client, produit, montant et date, barre payé).

## Tableau par étape (GC02)

Bouton « Tableau » (le choix Liste / Tableau est retenu pour la session). Une colonne par étape : Acompte attendu, Fichier / BAT, En impression, Prête, En livraison (« À confirmer » en premier s'il y en a). Chaque carte : n°, date (en rouge si en retard, avec un trait rouge), client, produit, une ligne d'état, montant, responsable. Les cartes en retard d'abord, puis l'échéance la plus proche.

La ligne d'état : « acompte 30 000 F attendu », « BAT n°2 sans réponse depuis 26 h » (en rouge au-delà de 24 h), « correction demandée par le client », « PAO en cours », « DTF XP600 · 40/60 faits », « prévue 19/10 08:00 », « à retirer en boutique », « livraison prévue 20/10 · matin », « départ 10:30 ».

Filtre : toutes les échéances, à livrer aujourd'hui, **à livrer cette semaine** (les 7 prochains jours, retards compris), en retard.

Ce tableau est le suivi commercial, commande par commande. Le kanban de l'atelier (`/atelier/production`, ligne par ligne et machine par machine) ne change pas.

## Fiche d'une commande (DT02)

- **En-tête** : retour à la liste, n° · produit, étape, « pour le mar. 20/10 » (en rouge si en retard), client, qui l'a créée. Boutons : Bon de travail (impression de la page), Message client (Clients › Messages, sinon WhatsApp), Encaisser, **Envoyer le BAT** (ouvre la prépresse sur la ligne dont le BAT reste à envoyer ; seulement pour la prépresse ou la Direction). Le menu « … » garde Modifier, Facturer ou Facture PDF, WhatsApp, Contrôle prépresse, Annuler et Restaurer.
- **Frise** : Créée › Acompte › Fichier / BAT › Impression › Finition › Prête › Livrée (ou Retirée). Quand l'acompte bloque la production, l'étape en cours est l'acompte.
- **Lignes** : désignation, quantité, total ; en bas « Venant du devis D-… accepté le … » (le jour où il est devenu commande) et le total. Restent : promotions, code promo, livraison, remise, TVA, coût et marge (droit Coût et marge seulement), « Marquer livré », « Enregistrer comme produit du client ».
- **Fichiers et BAT** : fichiers du client, puis BAT n°1, n°2… avec leur statut (Refusé et la demande du client, Validé, En attente). Lien « Ouvrir dans l'outil prépresse » (droit Prépresse).
- **Production** : PAO · BAT (graphiste, temps passé, BAT validé ou en attente), Impression · machine (opérateur, en cours avec le nombre fait, terminée, ou prévue), finitions, puis Livraison (livreur, prévue ou partie) ou Retrait. Un point vert : fait ; rond orange : en cours ; gris : à venir.
- **Historique** : création (« depuis D-… »), paiements (droit Paiements seulement), BAT envoyés, refusés, validés, impression lancée et terminée.
- **Client** : nom, entreprise ou particulier, la personne à joindre (celle qui a commandé, sinon le contact principal) et, pour qui voit les paiements, le **solde impayé** du client sur toutes ses commandes, avec la première facture échue.
- **Paiements** (droit Paiements) : total, acompte et autres paiements (date, moyen, à valider), reste à payer en rouge.
- **Conversation** (droit Clients) : le dernier message de la commande, « Répondu en 12 min » ou « Sans réponse depuis 3 h », lien vers Clients › Messages. La messagerie par commande (lot en cours) viendra s'y brancher.
- Livraison et fidélité restent en bas de la colonne de droite. Téléphone : les cartes s'empilent, Envoyer le BAT et Encaisser en bas de l'écran.

Dans le code : `CommandesController::show`, `src/Service/Commandes/FicheCommande.php`, `SuiviCommandeService::etapes`, gabarits `templates/commandes/index.html.twig` et `show.html.twig`, styles `public/css/commandes-suivi.css`.

## Droits

Aucune adresse nouvelle hors l'export, aucun droit nouveau. La liste, le tableau, l'export et la fiche demandent le droit Commandes, comme avant. La barre « Payé », le reste à encaisser, les colonnes Payé / Reste de l'export, la carte Paiements, les paiements de l'historique et le solde impayé du client : droit Paiements (voir). Coût et marge : droit Coût et marge. Conversation : droit Clients. Envoyer le BAT et l'outil prépresse : droit Prépresse.

## Changement de comportement

La liste « commandes actives » ne garde plus une commande payée et livrée pour une seule ligne sans production (conception, frais) : avant, elle y restait pour toujours.
