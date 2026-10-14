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
- Les promotions (`docs/promotions.md`), créées juste après par `app:promotions --demo` :
  - 7 promotions, une de chaque état : en cours pour tous (flyers et affiches, textile dès 10 pièces), prix fixe pour les entreprises (roll-up), avec code `BACHE500` pour les clients fidèles, terminée (« Été textile »), à venir (fêtes de fin d'année), suspendue (kakémono) ;
  - les lignes des commandes passées pendant les promotions en cours et terminée les portent, pour la fiche de suivi ;
  - 2 exceptions B2B sur le textile : une demandée par `demo.aminata` en attente, une acceptée par `demo.kadiatou` ;
  - 3 modèles d'anniversaire (1, 3 et 5 ans), seulement s'il n'y en a encore aucun ; les anniversaires des derniers jours fêtés par le passage du jour (aucun message ne part vers une fiche `DEMO-`), un prochain exclu, les autres à valider.
- Le reste : planning et entretien (avec des rappels WhatsApp envoyés et à envoyer), charges récurrentes, clôtures de caisse, rapprochements bancaires, campagnes, actions de caisse, messagerie et notifications.

## Repères

- Toutes les lignes de démo ont un identifiant à partir de 900001. La purge enlève donc aussi ce qui a été saisi dans ces tables après le chargement : la démo est faite pour une base d'essai.
- Les dates sont relatives au jour du chargement.
- Les téléphones des clients sont fictifs (60 xx xx xx) et la case « recevoir les SMS » est décochée : aucun message ne peut partir vers un vrai abonné.
- Tables non touchées :
  - `reglage` (les réglages restent ceux de l'admin) ;
  - `role_utilisateur` et `entreprise` (créés par les migrations, et réutilisés par la démo) ;
  - les tables techniques de Doctrine et de Messenger.
- Les photos (pointage, entretien, livraisons) ne sont que des noms de fichiers : les images n'existent pas.
- Promotions : leur identifiant n'est pas réservé, elles sont repérées par leur note (« Démo : … ») ; la purge les enlève avec les exceptions, les anniversaires et les promotions d'anniversaire des clients fictifs. Les modèles d'anniversaire restent (ce sont des réglages). Pour garder les totaux, factures et paiements des lignes passées, leur prix unitaire est relevé du montant de la promotion : modifier une de ces anciennes commandes recalcule la ligne au prix du catalogue, moins la promotion (jugée au jour de la commande).

Pour changer la démo, modifier `donnees-demo/generer_demo.py` puis le relancer (`python3 donnees-demo/generer_demo.py`). Le tirage est toujours le même.
