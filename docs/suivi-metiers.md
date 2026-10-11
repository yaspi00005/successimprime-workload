# Suivi du travail par métier et points

Yaya a envoyé le 10/10/2026 sept maquettes de suivi par métier. Elles sont dans `/mnt/project-files/maquettes/suivi-metiers/` :
- W01 : tableau de conception du graphiste ;
- W02 : évolution d'une conception, heures et points ;
- W03 : file machine et travail en cours (machiniste, mobile) ;
- W04 : journée, créneaux et points (livreur, mobile) ;
- W05 : planning d'entretien et validation photo (mobile) ;
- W06 : suivi des équipes en direct (admin) ;
- W07 : barème des points par métier (admin).

Les noms, heures et points sont fictifs.

Ce suivi complète le pointage (`docs/pointage.md`) et les primes (`docs/immobilisations-primes.md`).

## Le principe

- Chaque métier gagne des points sur son propre travail, selon un **barème par métier** modifiable par l'admin.
- Les points du mois sont divisés par les **points possibles** du métier ce mois-là : cela donne une note sur 100, le critère **« Productivité et qualité »**.
- Ce critère entre dans le score sur 100 de l'évaluation, avec la ponctualité et les autres critères. Le score fixe le palier de prime (90 → 100 %, 80 → 75 %, 70 → 50 %, 60 → 25 %, moins de 60 → 0).
- Les points d'un livreur et ceux d'un graphiste ne se comparent pas : chacun est noté en pourcentage de ses propres points possibles. Le classement du mois se fait sur ces pourcentages.
- Comme pour le pointage, l'admin peut ajuster des points avec un motif, gardé dans l'historique.

## Le barème (W07)

Chaque valeur est modifiable ; tout changement est gardé dans « Historique des changements ».

| Graphiste PAO | | Opérateur production | | Livreur | | Agent d'entretien | |
|---|---|---|---|---|---|---|---|
| BAT envoyé avant l'échéance | + 10 | Travail sorti avant l'heure prévue | + 5 | Livré dans le créneau | + 5 | Tâche faite dans le créneau, avec photo | + 3 |
| Validé sans erreur du graphiste | + 5 | Production, par 10 m² | + 1 | Preuve complète (photo, signature, GPS) | + 2 | Contrôle du responsable conforme | + 2 |
| Retard, par tranche de 30 min | − 2 | Entretien des têtes avant 08:30 | + 3 | Encaissement remis le jour même | + 3 | Checklist de fermeture complète | + 5 |
| Erreur du graphiste (faute, format) | − 10 | Gâche du jour sous 3 % | + 5 | Retard de plus de 30 min | − 5 | Tâche en retard de plus de 30 min | − 1 |
| Changement d'avis du client | 0 | Réimpression due à l'opérateur | − 10 | Colis abîmé ou erreur de livraison | − 10 | Contrôle non conforme (à refaire) | − 3 |

- **Graphiste** : les points d'un travail sont multipliés par sa difficulté, simple × 1, moyen × 1,5, complexe × 2, arrondis à l'entier inférieur. Exemple W02 : (10 + 5) × 1,5 = 22 points.
- **Opérateur** : une panne signalée ne retire pas de points.
- **Livreur** : un retard justifié (panne, client absent) est neutralisé par le responsable.
- **Agent d'entretien** : le responsable contrôle 2 tâches tirées au hasard chaque jour.

**Points possibles** du mois : la somme des points positifs que l'employé aurait gagnés si tout son travail du mois avait été parfait (avec les coefficients). La note « Productivité et qualité » = points gagnés ÷ points possibles × 100, entre 0 et 100. Dans W07, ce critère pèse **40 %** du score sur 100 ; le reste vient de la ponctualité (`docs/pointage.md`), de l'appréciation du responsable et des autres critères du rôle.

**Contestation** : chaque point gagné ou perdu est relié à son travail (commande, livraison, tâche) et à son heure. L'employé voit ce détail dans son application et peut contester un retrait ; le responsable accepte (le retrait est annulé) ou refuse avec un motif.

## Graphiste (poste PAO)

- **Mon tableau** : 5 colonnes, À faire → En conception → BAT envoyé → Corrections → Validé. Le travail en cours a un chronomètre. Chaque carte affiche son échéance avec un point vert (dans les temps), orange (bientôt) ou rouge (dépassée).
- **Évolution d'une conception** : une frise horodatée du brief à l'envoi en production, avec les versions (V1, V2…), le temps prévu et le temps réel, et le détail des points gagnés.
- **Chronomètre** : « Démarrer le suivant », « Pause », « Envoyer le BAT ». Il s'arrête pendant les pauses et pendant l'attente du client.
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

## État (11/10/2026)

Étape 1 construite : le barème (`src/Service/SuiviMetiers/Bareme.php`, testé seul) et l'écran W07 (`/equipe/bareme`, tuile « Barème des points », droit Administrateur RH). Les valeurs sont des réglages de la tuile : chaque changement va dans le journal des Paramètres et, depuis l'admin en ligne, attend la validation sur l'ERP local. Les coefficients de difficulté, le poids du critère et le nombre de contrôles d'entretien par jour s'y règlent aussi ; les paliers de prime sont ceux de cette spécification.

Étape 2 construite : Atelier › Conception PAO (`/atelier/pao`, droit Prépresse). Chaque contrôle prépresse a un suivi de conception (table `conception`) : graphiste, difficulté, échéance du BAT (par défaut le délai de la tuile Barème, en heures de travail), temps prévu, chronomètre, versions, retours, frise et points. La colonne du tableau suit le statut prépresse. « BAT validé par le client » compte les points du barème puis tente la validation prépresse (qui demande des fichiers complets). Un BAT envoyé depuis l'écran prépresse est aussi noté dans la frise. Le graphiste voit ses travaux et ceux que personne n'a pris ; le responsable de production voit toute l'équipe.

Reste : étapes 3 à 5 (file du machiniste, points du livreur et de l'agent d'entretien, suivi en direct), et la réponse du client depuis son espace (jalon J5) : aujourd'hui l'équipe l'enregistre.
