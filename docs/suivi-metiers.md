# Suivi du travail par métier et points

Yaya a décrit le 10/10/2026 sept maquettes de suivi par métier (graphiste, machiniste, livreur, agent d'entretien, admin). Les images ne sont pas encore arrivées dans le projet ; ce cadrage suit le descriptif. Les données des maquettes sont fictives.

Ce suivi complète le pointage (`docs/pointage.md`) et les primes (`docs/immobilisations-primes.md`).

## Le principe

- Chaque métier gagne des points sur son propre travail, selon un **barème par métier** modifiable par l'admin.
- Les points du mois sont divisés par les **points possibles** du métier ce mois-là : cela donne une note sur 100, le critère **« Productivité et qualité »**.
- Ce critère entre dans le score sur 100 de l'évaluation, avec la ponctualité et les autres critères. Le score fixe le palier de prime (90 → 100 %, 80 → 75 %, 70 → 50 %, 60 → 25 %, moins de 60 → 0).
- Les points d'un livreur et ceux d'un graphiste ne se comparent pas : chacun est noté en pourcentage de ses propres points possibles. Le classement du mois se fait sur ces pourcentages.
- Comme pour le pointage, l'admin peut ajuster des points avec un motif, gardé dans l'historique.

## Graphiste (poste PAO)

- **Mon tableau** : 5 colonnes, À faire → En conception → BAT envoyé → Corrections → Validé. Le travail en cours a un chronomètre. Chaque carte affiche son échéance avec un point vert (dans les temps), orange (bientôt) ou rouge (dépassée).
- **Évolution d'une conception** : une frise horodatée du brief à l'envoi en production, avec les versions (V1, V2…), le temps prévu et le temps réel, et le détail des points gagnés.
- **Règle** : un changement d'avis du client ne coûte aucun point. Seule une erreur du graphiste en retire. Quand il renvoie un BAT, le graphiste (ou le client dans son espace) indique la raison : « demande du client » ou « erreur à corriger ».
- Le tableau s'appuie sur les BAT et les versions de fichiers qui existent déjà dans les commandes.

## Machiniste (mobile)

- **Ma file** : les travaux de sa machine, dans l'ordre, avec l'heure de sortie prévue, les m² produits et le taux de gâche.
- **Travail en cours** : chronomètre, progression en m², fin estimée comparée à la sortie prévue, et boutons « Signaler une gâche » et « Incident ».
- Les m² et la gâche alimentent aussi l'amortissement à l'usage des machines et le coût de revient (`docs/immobilisations-primes.md`).

## Livreur (mobile)

- **Ma journée** : chaque livraison avec son créneau, l'heure réelle d'arrivée et de remise.
- **Livraison terminée** : la preuve (photos, signature, position GPS) et les points gagnés.
- Ce sont les écrans livreur de `docs/documents-livraisons.md` (L03), avec les points en plus.

## Agent d'entretien (mobile)

- **Planning** : les tâches de la journée par créneau horaire.
- **Validation** : il coche la checklist, puis touche « Prendre la photo et valider », sur le même principe que le pointage (photo en direct, heure du serveur).
- Les tâches viennent du planning (`docs/planning-taches.md`), en tâches récurrentes quotidiennes.

## Admin

- **Suivi des équipes en direct** : ce que fait chacun en ce moment (conception, machine, tournée, ménage), les alertes de délai et le classement du mois.
- **Barème des points par métier** : chaque valeur est modifiable. L'écran montre le chemin des points jusqu'à la prime : points du mois ÷ points possibles = note « Productivité et qualité », qui entre dans le score sur 100, qui fixe le palier.

## Droits

- Chacun voit son propre tableau, ses points et sa note, jamais ceux des autres.
- Le responsable voit son équipe ; l'admin voit tout.
- Le classement du mois est réservé aux responsables et à l'admin. Le montrer aux employés est une option, désactivée par défaut.

## Ordre de construction

À faire après le pointage et le barème des primes (N03), car il en reprend la note et le calcul :
1. barème des points par métier et calcul de la note (une classe sans base de données, testée seule) ;
2. tableau du graphiste et frise d'une conception ;
3. file du machiniste ;
4. points du livreur et validation de l'agent d'entretien ;
5. suivi des équipes en direct.
