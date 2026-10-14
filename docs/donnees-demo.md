# Données de démonstration

Une année d'activité fictive dans toutes les tables, pour voir le comportement des écrans et des statistiques.

## Charger ou enlever

```
php bin/console app:demo            # charge (relançable : l'ancienne démo est d'abord enlevée)
php bin/console app:demo --purger   # enlève tout
```

La commande refuse de tourner en production. Le fichier `donnees-demo/demo.sql` peut aussi s'importer dans phpMyAdmin.

## Contenu

- 12 employés avec leur compte : `demo.kadiatou` (direction), `demo.fanta` (comptable et RH), `demo.aminata`, `demo.mariam` et `demo.moussa` (caisse et commercial), `demo.seydou` et `demo.aissata` (graphistes), `demo.oumar` et `demo.ibrahim` (machines), `demo.bakary` et `demo.sekou` (livreurs), `demo.awa` (entretien). Mot de passe : `demo1234`.
- Le catalogue : 13 produits, avec leurs supports, formats, finitions et machines, ainsi que les articles de stock et les fournisseurs.
- 200 clients (`DEMO-0001` à `DEMO-0200`) et environ 770 commandes sur 12 mois, avec :
  - devis, factures, paiements et relances ;
  - les mouvements de trésorerie sur 4 comptes.
- Les 120 derniers jours ont aussi :
  - la prépresse, la conception, les ordres de production et le journal des machines ;
  - les livraisons et les étiquettes.
- Le personnel : pointages (60 jours), demandes, congés, bulletins de paie, réclamations.
- Employés sans accès (écrans E01 à E05) :
  - `demo.adama` (gardien de nuit, 19 h – 7 h) et `demo.nana` (aide atelier) pointent sur la tablette ;
  - `demo.rokia` (stagiaire) a une invitation en attente, dont le lien ne s'ouvre pas ;
  - `demo.lassana` est parti (accès retiré).
- La fidélité et les avis, calculés juste après le chargement par `app:fidelite --demo` et `app:sondages:demo` : relevés de points des particuliers, 3 règles d'article, promos de réveil, avis après livraison et deux sondages. `--purger` les enlève aussi.
- Le reste : planning et entretien (avec des rappels WhatsApp envoyés et à envoyer), charges récurrentes, clôtures de caisse, rapprochements bancaires, campagnes, actions de caisse, messagerie et notifications.

## Espace client de démonstration

Connexion sur `/espace/connexion` avec « mot de passe » : le numéro (8 chiffres) et le mot de passe `demo1234`. Les fiches `DEMO-` ne reçoivent jamais de code SMS, d'où le mot de passe.

Salon Espoir (`DEMO-0002`, entreprise à la grille revendeur), une personne par type de droits :

| Personne | Numéro | Droits | Ce qu'on y voit |
|---|---|---|---|
| Oumar Guindo, Gérant (titulaire) | 60 69 78 04 | gère le compte, valideur, payeur | tout : utilisateurs, « Qui reçoit quoi », proposition de raison sociale ou NIF |
| Awa Coulibaly, Intendante | 60 44 51 01 | valideur | devis à accepter, BAT ; ni factures ni montants dus |
| Ibrahim Touré, Comptabilité | 60 44 51 02 | payeur | factures, solde, « Payer », plafond de crédit |
| Mariam Dembélé, Assistante | 60 44 51 03 | demandeur (aucun droit) | sa commande attend un valideur |
| Réception, Accueil | 60 44 51 04 | Lecture · suivi | commandes, livraisons, fichiers ; rien qui écrive |

Contenu de Salon Espoir : un BAT à valider (plaques de chambres), des t-shirts en impression avec leur livraison prévue, un roll-up en route, des kakémonos livrés, une facture à payer aujourd'hui, une facture échue, un devis envoyé la veille (bâche de façade), un échange de messages avec l'atelier, deux adresses de livraison et la conseillère qui suit le client.

Particulier : le client particulier qui a le plus de commandes (Amadou Sow, 60 00 62 12), avec la fidélité (les particuliers ne sont pas à la grille revendeur) et la page Mon compte sans les parties entreprise. 45 autres entreprises de la démonstration ont un compte titulaire sur le numéro de leur fiche, avec le même mot de passe.

Le générateur affiche les deux comptes principaux à la fin (« Espace client : titulaire … particulier … »). L'espace client tourne sur le site en ligne (`APP_EN_LIGNE=1`) : charger la démo sur une base d'essai du site, ou synchroniser depuis un ERP local d'essai.

## Repères

- Toutes les lignes de démo ont un identifiant à partir de 900001. La purge enlève donc aussi ce qui a été saisi dans ces tables après le chargement : la démo est faite pour une base d'essai.
- Les dates sont relatives au jour du chargement.
- Les téléphones des clients sont fictifs (60 xx xx xx) et la case « recevoir les SMS » est décochée : aucun message ne peut partir vers un vrai abonné.
- Tables non touchées :
  - `reglage` (les réglages restent ceux de l'admin) ;
  - `role_utilisateur` et `entreprise` (créés par les migrations, et réutilisés par la démo) ;
  - les tables techniques de Doctrine et de Messenger.
- Les photos (pointage, entretien, livraisons) ne sont que des noms de fichiers : les images n'existent pas.

Pour changer la démo, modifier `donnees-demo/generer_demo.py` puis le relancer (`python3 donnees-demo/generer_demo.py`). Le tirage est toujours le même.
