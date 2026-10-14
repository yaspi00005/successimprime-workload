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
- Le patrimoine (`docs/immobilisations-primes.md`) :
  - 8 machines amorties à l'usage, dont le copieur Canon C3226i (pages A4 / A3, 12 mois de relevés saisis par `demo.fanta`), le traceur HP T830 (relevé simple par `demo.oumar`, celui du mois dernier manque), l'imprimante PVC (cartes) et la sublimation (transferts) ; les commandes de polos passent en sublimation et une partie des cartes en PVC ;
  - 33 biens au registre, `IMM-D001` à `IMM-D033` (machines d'abord, puis informatique, mobilier, motos, climatiseurs, énergie, audiovisuel, logiciels), dont 7 confiés à une personne (l'iMac de Seydou, la tablette d'Aminata, la moto de Bakary…), un vendu et quelques-uns à contrôler ;
  - les dotations des 6 derniers mois terminés, passées par `app:immobilisations:dotations --demo` (journal OD, 681 / 28x).
- Les primes, par `app:primes --demo` : évaluations des 4 derniers mois de chaque employé qui a un rôle de prime, avec appréciation et validation. Les mois plus anciens ont leurs primes validées et reportées sur le bulletin ; le mois dernier attend « Valider les primes », et Seydou n'y a pas encore d'appréciation. Moussa a une version 2 en août (« Facture du 28 saisie en retard ») et Sékou une prime suspendue. `demo.fanta` a le droit Comptabilité et patrimoine, et Aïssata a Seydou pour responsable.
- La fidélité et les avis, calculés juste après le chargement par `app:fidelite --demo` et `app:sondages:demo` : relevés de points des particuliers, 3 règles d'article, promos de réveil, avis après livraison et deux sondages. `--purger` les enlève aussi.
- Le reste : planning et entretien (avec des rappels WhatsApp envoyés et à envoyer), charges récurrentes, clôtures de caisse, rapprochements bancaires, campagnes, actions de caisse, messagerie et notifications.

## Repères

- Toutes les lignes de démo ont un identifiant à partir de 900001. La purge enlève donc aussi ce qui a été saisi dans ces tables après le chargement : la démo est faite pour une base d'essai.
- Les dates sont relatives au jour du chargement.
- Les téléphones des clients sont fictifs (60 xx xx xx) et la case « recevoir les SMS » est décochée : aucun message ne peut partir vers un vrai abonné.
- Tables non touchées :
  - `reglage` (les réglages restent ceux de l'admin) ;
  - `bareme_prime` (le barème des primes, réglé par l'admin) ;
  - `role_utilisateur` et `entreprise` (créés par les migrations, et réutilisés par la démo) ;
  - les tables techniques de Doctrine et de Messenger.
- Les photos (pointage, entretien, livraisons) ne sont que des noms de fichiers : les images n'existent pas.

Pour changer la démo, modifier `donnees-demo/generer_demo.py` puis le relancer (`python3 donnees-demo/generer_demo.py`). Le tirage est toujours le même.
