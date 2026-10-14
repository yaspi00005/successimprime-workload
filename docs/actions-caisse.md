# Actions de caisse et commerciales : délai de 4 h de travail

Yaya a envoyé 4 maquettes le 11/10/2026. Elles sont dans `/mnt/project-files/maquettes/actions-caisse/`, renommées AC01 à AC04 pour ne pas se confondre avec les maquettes de saisie de commande C01 à C09 :
- AC01 : Mes actions (poste de caisse) ;
- AC02 : Mes actions et résultat d'un appel (mobile) ;
- AC03 : lignes automatiques et règle + 10 / − 15 (admin) ;
- AC04 : journal des actions et points (admin).

Les noms, montants et heures sont fictifs.

## La règle

- Une **ligne d'action** (relancer une facture, rappeler un client…) est créée pour la personne de caisse.
- Faite dans les **4 heures de travail** après sa création : **+ 10**. Au-delà : **− 15**, appliqué **une seule fois** ; la ligne reste à faire.
- Le délai, les points et les heures de travail se règlent dans AC03.

### Comment le délai est compté

- **Heures de travail seulement** : lundi à samedi, 8 h – 18 h. Le compteur s'arrête la nuit, le dimanche, pendant la pause de 13 h à 14 h et les jours fériés (même table des jours fériés que le planning, `docs/planning-taches.md`).
- **Départ** : à la création de la ligne, qui s'affiche au même moment sur l'écran de la caisse.
- **Exemples** (vérifiés sur les maquettes) :
  - créée samedi 17:00 → 1 h samedi + 3 h lundi → à faire avant **lundi 11:00** ;
  - créée vendredi 16:30 → 1 h 30 vendredi + 2 h 30 samedi → avant **samedi 10:30** ;
  - créée lundi 09:40 → la pause de 13 h à 14 h ne compte pas → avant **14:40**.
- Une ligne créée hors des heures de travail (la nuit, le dimanche) démarre à la prochaine ouverture.
- Le compte à rebours est vert, orange sous 2 h, rouge une fois en retard.

Le calcul (ajouter 4 h de travail à une date, temps de travail écoulé entre deux dates) est une classe sans base de données, testée seule.

## Les lignes créées automatiquement (AC03)

Chaque source est activable :

| Source | Quand la ligne est créée |
|---|---|
| Facture échue | « Relance facture » le lendemain de l'échéance, puis tous les 3 jours |
| Devis sans réponse | 48 h sans acceptation ni refus, puis seconde relance à J+7 une fois la première faite (réglage « Seconde relance du devis à », 0 pour l'arrêter). À la seconde, l'agent du devis est prévenu et le message WhatsApp proposé change |
| Commande prête | dès que l'atelier passe la commande en « Prête » |
| Nouveau prospect | formulaire du site, discussion, appel entrant non converti |
| Client inactif | aucune commande depuis 60 jours |
| Avis après livraison | le lendemain de la livraison |
| Lignes manuelles | ajoutées par le responsable (ex. « prospecter 5 écoles cette semaine ») |

- Une même cause ne crée pas deux lignes ouvertes : tant qu'une relance de la facture est à faire, la relance suivante n'est pas créée.
- La ligne disparaît d'elle-même, sans points ni pénalité, si sa cause disparaît avant qu'elle soit faite (facture payée, devis accepté).

## Faire une action (AC01, AC02)

- Boutons **Appel** (lien `tel:`), **WhatsApp** (lien `wa.me` avec message prêt, puis API quand la clé sera là) et **Fait**.
- **Résultat obligatoire** : promesse de paiement, payé, pas de réponse, à rappeler, litige… avec une note. Sans résultat, la ligne ne compte pas comme faite.
- **Promesse de paiement** : la date promise et le moyen (Orange Money, espèces…) créent seuls une nouvelle ligne « vérifier le paiement » ce jour-là.
- **Pas de réponse / à rappeler** : la ligne est faite (+ 10 si dans le délai) et une nouvelle ligne de rappel est créée.

### La preuve

Proposition de Yaya, retenue : une ligne ne compte comme faite qu'avec un résultat. La preuve enregistrée est :
- l'heure à laquelle « Appel » ou « WhatsApp » a été touché depuis l'application ;
- le résultat et la note.

Limite à connaître : depuis un navigateur, l'application sait qu'on a touché « Appel », mais pas si l'appel a vraiment eu lieu ni sa durée. Afficher « appel de 3 min 40 » (AC02) demande une téléphonie connectée (standard VoIP ou opérateur), à prévoir plus tard si besoin. D'ici là, le **contrôle au hasard** du responsable (AC04) sert de garde-fou : il ouvre quelques lignes chaque semaine et peut annuler les points d'une ligne non justifiée, avec un motif.

## Absence ou congé

Proposition de Yaya, retenue : quand la personne de caisse est absente ou en congé (pointage ou congé validé de l'espace employé), ses lignes ouvertes passent à l'autre personne de caisse en poste, et leur délai repart de zéro. Les − 15 déjà appliqués restent à la première personne.

## Le journal (AC04)

- Par personne et par mois : lignes, faites à l'heure, en retard, points (ex. 79 × 10 − 7 × 15 = 685).
- Pourcentage « dans les délais » = lignes faites à l'heure ÷ lignes du mois.
- Chaque ligne : date, client et action, création, temps de travail écoulé, preuve, points.
- Boutons « Contrôle au hasard » et « Export ».

## Lien avec les primes

Ces points sont ceux du métier « Caisse et commercial » dans le barème par métier (`docs/suivi-metiers.md`) : points du mois ÷ points possibles (10 × lignes du mois) = note « Productivité et qualité ». Comme partout, l'admin peut ajuster des points avec un motif.

## Droits

- La personne de caisse voit ses lignes et ses points.
- Le responsable et l'admin voient le journal de toute l'équipe et règlent AC03.
- Les montants des factures relancées suivent le droit « paiements » (`ROLE_PAIEMENT_VOIR`).

## Ordre de construction

1. Calcul des heures de travail et du délai (avec jours fériés), testé seul.
2. Lignes d'action : sources automatiques, lignes manuelles, AC01.
3. Résultat d'un appel, promesse de paiement, mobile AC02.
4. Règles AC03, journal et contrôle au hasard AC04, points vers les primes.

## État (11/10/2026)

Construit : calcul des heures de travail (`HeuresTravail`, jours fériés dans Paramètres › Horaires), sources automatiques, attribution à la personne de caisse en poste (pointage du jour), réattribution en cas d'absence, AC01 (Ventes › Mes actions), AC02 (résultat, promesse, rappel), AC03 et AC04 (Réglages › Actions commerciales, droit « Actions commerciales de l'équipe »), contrôle au hasard, export CSV. Les règles se changent dans Paramètres › Relances et délais.

Choix faits :
- une source ancienne ne crée pas de ligne en masse au démarrage : factures échues depuis 90 jours au plus, autres sources sur 7 jours ;
- sans appel ni WhatsApp touché depuis l'application, une note est obligatoire ;
- « pas de réponse » ou « à rappeler » sans date crée le rappel 2 h de travail plus tard, pour qu'on ne puisse pas enchaîner les rappels et les points ;
- une ligne sans personne de caisse s'affiche chez toutes les personnes de caisse jusqu'à ce que l'une la fasse.

Reste : envoi WhatsApp par l'API (liens wa.me en attendant), lien des points vers les primes.

Sur le serveur, ajouter au cron :

```
*/15 * * * * cd /chemin/du/site && php bin/console app:actions-caisse
```
