# Données de démonstration

Une année d'activité fictive dans toutes les tables, pour voir le comportement des écrans et des statistiques.

## Charger ou enlever

```
php bin/console app:demo            # charge (relançable : l'ancienne démo est d'abord enlevée)
php bin/console app:demo --purger   # enlève tout
```

La commande refuse de tourner en production. Le fichier `donnees-demo/demo.sql` peut aussi s'importer dans phpMyAdmin.

## Contenu

- 12 employés avec leur compte : `demo.kadiatou` (direction), `demo.fanta` (comptable et RH : rôle caisse avec la trésorerie, les paiements, les statistiques, la RH et la comptabilité ajoutés sur sa fiche), `demo.aminata`, `demo.mariam` et `demo.moussa` (caisse et commercial, sans la trésorerie ; Moussa sans les virements), `demo.seydou` et `demo.aissata` (graphistes), `demo.oumar` et `demo.ibrahim` (machines), `demo.bakary` et `demo.sekou` (livreurs), `demo.awa` (entretien). Mot de passe : `demo1234`.
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
- Les promotions (`docs/promotions.md`), créées juste après par `app:promotions --demo` :
  - 7 promotions, une de chaque état : en cours pour tous (flyers et affiches, textile dès 10 pièces), prix fixe pour les entreprises (roll-up), avec code `BACHE500` pour les clients fidèles, terminée (« Été textile »), à venir (fêtes de fin d'année), suspendue (kakémono) ;
  - les lignes des commandes passées pendant les promotions en cours et terminée les portent, pour la fiche de suivi ;
  - 2 exceptions B2B sur le textile : une demandée par `demo.aminata` en attente, une acceptée par `demo.kadiatou` ;
  - 3 modèles d'anniversaire (1, 3 et 5 ans), seulement s'il n'y en a encore aucun ; les anniversaires des derniers jours fêtés par le passage du jour (aucun message ne part vers une fiche `DEMO-`), un prochain exclu, les autres à valider.
- Les demandes de conception (`docs/demande-conception.md`), vues par l'atelier dans Atelier › Demandes de conception :
  - `DC-D001` : kakémono de DEMO-0002 pour un graphiste, reçue, acompte Orange Money de 5 000 F à valider par la caisse, à prendre en charge ;
  - `DC-D002` : flyer en cours chez Seydou, acompte validé ;
  - `DC-D003` : cartes de visite terminées, acompte et solde payés ;
  - `DC-D004` : affiche de DEMO-0002 faite par l'IA, impression commandée « à confirmer » avec la finalisation ; DEMO-0002 a un relevé de crédit IA (pack de 3 images payé, 2 utilisées, solde 1 000 F).
- Le site vitrine, « Notre équipe » de la page « Qui sommes-nous ? » (`App\Service\Site\EquipeDemo`, docs/site-vitrine.md) : six personnes fictives (900001 à 900006) avec leur poste, aux avatars dessinés par le programme (jamais la photo d'une vraie personne), dont deux avec leurs initiales, et « Bakary C. » : « Visible » coché mais sans accord, il ne doit apparaître nulle part sur le site. Les textes des pages ne font pas partie de la démonstration : `app:site:contenu-initial` les crée.
- Le reste : planning et entretien (avec des rappels WhatsApp envoyés et à envoyer), charges récurrentes, clôtures de caisse, rapprochements bancaires, campagnes, actions de caisse, messagerie et notifications.

## Espace client de démonstration

Connexion sur `/espace/connexion` avec « mot de passe » : le numéro (8 chiffres) et le mot de passe `demo1234`. Les fiches `DEMO-` ne reçoivent jamais de code (ni e-mail ni SMS) et n'ont pas de bouton « Envoyer l'accès à l'espace client », d'où le mot de passe.

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
  - `bareme_prime` (le barème des primes, réglé par l'admin) ;
  - `role_utilisateur`, `entreprise` et `societe` (créés par les migrations, et réutilisés par la démo) ;
  - les tables techniques de Doctrine et de Messenger.
- Sociétés (`docs/societes.md`) : les lignes de démo arrivent avec la société par défaut. Juste après, `app:demo` applique la règle de la migration à ses seules lignes : les devis, factures, commandes et paiements de DREPA passent à la société DREPA.
- Les photos (pointage, entretien, livraisons) et l'image IA de `DC-D004` ne sont que des noms de fichiers : les images n'existent pas.
- Demandes de conception : la purge enlève aussi les éléments, images IA, transactions Orange Money, crédits et relevés de crédit IA des clients fictifs.
- Promotions : leur identifiant n'est pas réservé, elles sont repérées par leur note (« Démo : … ») ; la purge les enlève avec les exceptions, les anniversaires et les promotions d'anniversaire des clients fictifs. Les modèles d'anniversaire restent (ce sont des réglages). Pour garder les totaux, factures et paiements des lignes passées, leur prix unitaire est relevé du montant de la promotion : réenregistrer une de ces anciennes commandes sans rien changer garde ce prix et le total ; choisir un autre produit pour la ligne reprend le prix du catalogue, moins la promotion (jugée au jour de la commande).

Pour changer la démo, modifier `donnees-demo/generer_demo.py` puis le relancer (`python3 donnees-demo/generer_demo.py`). Le tirage est toujours le même.
