# Pointage par QR et photo

Yaya a envoyé 6 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/pointage/` :
- Q01 : l'affiche QR à imprimer ;
- Q02 : le scan, puis la photo ;
- Q03 : l'arrivée et le départ enregistrés ;
- Q04 : les pointages du jour, avec photos ;
- Q05 : la fiche du mois d'un employé et le détail d'un pointage ;
- Q06 : les réglages du pointage.

Les noms, heures et photos sont fictifs. Rien de tout ça n'existe encore dans le code : l'entité `Employes` n'a que les champs RH de base.

## Le parcours de l'employé (Q01 à Q03)

1. Dans l'application, il touche **Pointer** et scanne le QR affiché à l'entrée.
2. La caméra frontale s'ouvre, avec un seul bouton : « Prendre la photo et enregistrer ». La photo est prise en direct ; on ne peut pas choisir une image de la galerie.
3. Un écran confirme « Arrivée enregistrée 07:52 » ou « Départ enregistré 18:06 », avec le temps de présence du jour et de la semaine.

L'employé est reconnu par **son compte** dans l'application. La photo est une preuve que c'est bien lui qui a pointé ; il n'y a **pas de reconnaissance faciale** (pas de données biométriques).

## Les règles

- **Arrivée et départ automatiques** : un pointage ouvre la présence (arrivée), le suivant la ferme (départ), même après minuit. L'employé n'a rien à choisir.
- **Anti-doublon** : deux pointages à moins de 2 minutes d'écart n'en font qu'un.
- **Heure fiable** : l'heure enregistrée est celle du serveur, jamais celle du téléphone.
- **Wi-Fi de l'atelier** (option) : le serveur vérifie que le pointage vient du réseau de l'atelier ou de la boutique (adresse IP de la connexion). Si l'application tourne sur un serveur local, le pointage marche même sans Internet.
- **Téléphone enregistré** (option) : chaque employé pointe depuis un appareil validé par la RH. Un nouvel appareil doit être validé avant de pouvoir pointer.
- **Zone GPS** (option) : le pointage est refusé à plus de 100 m du point de pointage.
- **QR renouvelable** : chaque point de pointage (PT-01 Atelier, PT-02 Boutique) a un code secret. « Renouveler » rend l'ancien code inutilisable, par exemple si une photo du QR circule. Le QR n'est accepté que s'il vient de Succès Imprim.
- **Pause déduite** : 13 h – 14 h par défaut, retirée du temps de présence (07:52 – 18:06 = 10 h 14, moins 1 h = 9 h 14).
- **Départ non pointé** : alerte à la RH à 20 h. La journée reste « à régulariser » jusqu'à une correction.
- **Correction** : toute correction manuelle exige un motif et reste visible dans l'historique (qui, quand, avant, après).

## Côté RH (Q04 à Q06)

- **Q04 Pointages du jour** : présents, retards, départs non pointés, heures supplémentaires ; une ligne par employé avec ses photos, ses heures, son retard et son statut. Les congés et absences validés de l'espace employé apparaissent ici (« Congé payé »).
- **Q05 Fiche du mois** : le journal jour par jour, les totaux (jours présents, retards, heures), et le détail d'un pointage (photos, point, téléphone, position, heure serveur). Boutons « Feuille de présence » (PDF) et « Corriger un pointage ».
- **Q06 Réglages** : les points de pointage (impression de l'affiche Q01, renouvellement), les règles (anti-doublon, tolérance de retard, pause, alerte de départ), les contrôles en option et la conservation des photos.

## Calcul des points

Barème de Yaya (16/10/2026). Il remplace l'ancien barème de ponctualité à partir de la **date de début** (Paramètres › Points du pointage) : la migration la pose au jour où elle passe. Les journées d'avant gardent l'ancien barème (plus bas), pour que les mois passés ne changent pas sans qu'on le décide. Vider la date applique le nouveau calcul à toutes les journées.

**Deux horaires types**, chacun avec ses heures dues :

| Horaire | Heures | Heures dues |
|---|---|---|
| Accueil et caisse | 9 h – 17 h | 8 h |
| Atelier et autres | 9 h – 18 h | 9 h |

Chaque employé suit l'horaire de son poste : « Accueil et caisse » quand le poste parle de caisse, d'accueil, de secrétariat ou d'assistance (ou, sans poste, quand son rôle est la caisse), « Atelier et autres » sinon. La fiche employé (Équipe › Employés › Modifier, « Horaire des points ») permet de choisir l'autre. Des **horaires propres** (gardien de nuit 19 h – 7 h, mi-temps…) passent avant l'horaire type : ses heures dues sont alors de la fin moins le début, même après minuit.

**Points d'une journée** (heures faites = du pointage d'arrivée au pointage de départ) :

| Situation | Points |
|---|---|
| Heures dues faites, à l'heure ou en rattrapant un retard en partant plus tard | + 10 |
| En retard et heures dues pas faites | − 15 |
| À l'heure mais parti avant la fin, heures pas faites | 0 |
| Chaque **heure entière** au-delà des heures dues | + 5 par heure |
| Journée (ou garde) de 20 h ou plus | 50, **à la place** du reste |
| Départ non pointé | 0, la journée reste à régulariser par la RH |
| Congé, absence validée, jour férié | 0, et la journée ne compte pas |

- **Marge** : la tolérance de retard (10 min, Paramètres › Pointage) sert aussi de marge sur les heures. Arriver à 9 h 10 n'est pas un retard ; 8 h 51 faites sur 9 h dues comptent comme faites.
- **Pause** : par défaut, la pause est comptée dans les heures (9 h – 18 h = 9 h dues). L'interrupteur « Retirer la pause des heures » la retire des heures dues et des heures faites.
- **Heures en plus** : les points des heures en plus et de la longue journée ne sont acquis qu'une fois les heures validées par la RH (bouton « Valider les heures sup. » du détail d'un pointage), pour qu'un départ oublié ne rapporte rien. L'interrupteur « Valider les heures en plus » permet de s'en passer.
- **Jour de repos travaillé** : pas d'heures dues, donc ni + 10 ni − 15 ; chaque heure entière compte en plus (+ 5), ou 50 à partir de 20 h.
- **Départ non pointé** : la journée ne rapporte rien et reste une anomalie (« Départ non pointé ») ; la RH corrige le départ avec un motif, et les points se calculent alors.
- **Ajustement** : l'ajustement par l'admin avec motif (plus bas) s'ajoute toujours au calcul.

Toutes les valeurs (heures des deux horaires, + 10, − 15, + 5, 20 h, 50, pause, validation, date de début) se règlent dans **Paramètres › Points du pointage** (droit « Administrateur RH » ou admin). Les points ne sont pas enregistrés : ils se recalculent à l'affichage, donc un changement de réglage vaut pour toutes les journées depuis la date de début. Une évaluation de prime déjà validée garde ses notes.

**Exemples** (marge de 10 min, pause comptée) :

| Employé et horaire | Arrivée – départ | Heures faites / dues | Points | Pourquoi |
|---|---|---|---|---|
| Atelier 9 h – 18 h | 8 h 55 – 18 h 05 | 9 h 10 / 9 h | + 10 | à l'heure, heures faites |
| Atelier | 9 h 40 – 18 h 45 | 9 h 05 / 9 h | + 10 | retard de 40 min rattrapé |
| Atelier | 9 h 40 – 18 h 00 | 8 h 20 / 9 h | − 15 | retard et heures pas faites |
| Atelier | 8 h 58 – 16 h 00 | 7 h 02 / 9 h | 0 | à l'heure mais parti avant la fin |
| Atelier | 9 h 00 – 18 h 59 | 9 h 59 / 9 h | + 10 | 59 min en plus : pas une heure entière |
| Accueil 9 h – 17 h | 9 h 00 – 19 h 30 | 10 h 30 / 8 h | + 20 | + 10, et 2 h en plus (+ 10) une fois validées |
| Atelier | 9 h 00 – 2 h 30 le lendemain | 17 h 30 / 9 h | + 50 | + 10, et 8 h en plus (+ 40) une fois validées |
| Atelier, garde | 8 h 00 – 8 h 00 le lendemain | 24 h / 9 h | 50 | longue journée, à la place du + 10 et des heures en plus |
| Gardien, horaires propres 19 h – 7 h | 19 h 00 – 7 h 05 | 12 h 05 / 12 h | + 10 | poste de nuit, heures faites |
| Atelier, dimanche (repos) | 9 h 00 – 13 h 30 | 4 h 30 / repos | + 20 | 4 h en plus une fois validées |
| Atelier | 9 h 00 – (pas de départ) | — | 0 | départ non pointé, à régulariser |

Où le voir :
- **L'employé** : Mon espace › Ponctualité, page « Mon pointage » (`/mon-espace/pointage`) : chaque jour avec l'arrivée, le départ, les heures faites et dues, les points et la raison en clair, le total du mois et « Comment sont comptés les points ». Il ne voit que ses propres journées. L'écran de confirmation du départ (Q03) donne aussi les heures et les points du jour.
- **La RH** (droit « Administrateur RH ») : colonne « Points » des pointages du jour (Q04), et fiche du mois (Q05) avec la raison sous chaque jour, le détail d'une journée et le total du mois.

**Note de ponctualité sur 100** pour l'évaluation (N02) : points du mois ÷ (10 × jours travaillés) × 100, plafonnée à 100 et jamais sous 0 (10 = les points « heures faites »). 22 jours avec les heures faites : 220 ÷ 220 = **100** ; 21 jours faits et un retard non rattrapé : (210 − 15) ÷ 220 = **89**.

## Ancien barème (journées avant la date de début)

Barème de Yaya (10/10/2026), toujours utilisé pour les journées d'avant la date de début du calcul des points. Ses valeurs restent visibles, sans modification, dans Paramètres › Pointage.

| Situation | Points |
|---|---|
| À l'heure (dans la tolérance de 10 min) | + 10 |
| En retard | − 10 |
| Reste au-delà de l'heure de fin (au moins 1 h de plus) | + 30 |
| Nuit passée au bureau (présence de 24 h) | + 100 |
| Oubli de pointage (arrivée ou départ à régulariser) | − 20 |
| Congé, absence validée, jour férié | 0, et la journée ne compte pas |

**Ajustement par l'admin** : l'admin (ou l'administrateur RH) peut ajuster les points d'une journée, par exemple annuler le − 20 d'un oubli justifié (panne de téléphone, réseau coupé) ou retirer un bonus. Chaque ajustement exige un motif, garde l'avant et l'après dans l'historique, et l'employé voit le motif sur sa fiche. Une fois l'évaluation du mois validée, un ajustement crée une nouvelle version de l'évaluation. Une journée ne cumule qu'une seule ligne de bonus (+ 30 ou + 100, la plus haute), en plus de + 10 ou − 10 pour l'arrivée.

Avec l'ancien barème, la note de ponctualité suit le même calcul ; les bonus peuvent rattraper des retards.
- 22 jours, tous à l'heure : 220 ÷ 220 = **100**.
- 21 à l'heure, 1 retard : 200 ÷ 220 = **91**.
- 20 à l'heure, 2 retards, 1 soirée au-delà de l'heure : 210 ÷ 220 = **95**.

Cette note est le critère « Ponctualité et présence » du barème des primes (`docs/immobilisations-primes.md`, poids 10 % pour la caisse et le commercial). Avant la mise en service du pointage, ce critère n'a pas de données : son poids est réparti sur les autres critères.

Les heures en plus (soirée, nuit) comptent aussi en heures supplémentaires pour la paie, après validation par le responsable. Les points de bonus ne sont acquis qu'une fois ces heures validées, pour éviter un départ « oublié » qui rapporterait des points.

### Nuit au bureau

On passe parfois la nuit au bureau. Le pointage ne se coupe donc pas à minuit :
- un pointage ouvre une **présence** ; le pointage suivant la ferme, même le lendemain ;
- au-delà de 26 h sans second pointage, la présence est « départ non pointé » et passe à régulariser ;
- la présence est rattachée au jour de l'arrivée ; le pointage du lendemain matin après une nuit ferme la présence de la veille et compte comme départ, puis le pointage suivant ouvre une nouvelle journée.

## Lien avec la paie

La feuille de présence du mois donne, par employé : jours présents, absences (justifiées ou non), retards, heures normales et heures supplémentaires. La paie et les sommes dues (`docs/espace-employe.md`) la reprennent ; rien n'est payé automatiquement.

## Confidentialité et conditions

- **HTTPS obligatoire** : sans HTTPS, le téléphone refuse d'ouvrir la caméra. Chaque téléphone demande une fois l'autorisation d'utiliser la caméra.
- **Photos** : visibles seulement avec le droit « administrateur RH » (droit ROLE_RH, dans `src/Security/Droits.php`). Elles sont supprimées automatiquement après 90 jours ; les heures restent dans la feuille de présence. L'employé ne voit que ses propres pointages.
- **Information** : les employés sont informés à l'embauche (pointage, photo, durée de conservation). La durée de 90 jours est à confirmer avec le conseil juridique.
- **Tablette partagée** (option, désactivée par défaut) : pour les employés sans smartphone, une tablette à l'entrée ; on choisit son nom, puis la photo est prise.

## Ordre de construction

1. Points de pointage, affiche QR, pointage depuis le téléphone (scan, photo, heure serveur), écran de confirmation.
2. Pointages du jour, fiche du mois, corrections avec motif, feuille de présence PDF.
3. Contrôles en option (téléphone enregistré, GPS, Wi-Fi), tablette partagée, suppression des photos à 90 jours.
4. Points de ponctualité et note sur 100, branchés sur l'évaluation des primes et sur la paie.

Le calcul (arrivée/départ, pause, retard, points, note) est une classe sans base de données, testée seule.

## État (18/10/2026)

Construit : étapes 1 et 2, téléphone enregistré, zone GPS, suppression des photos. Écrans : `/pointer` (employé, lien « Pointer » dans le menu du profil), `/equipe/pointage` (droit « Administrateur RH », onglet Réglages › Pointage). Les règles se changent dans Paramètres › Pointage et Horaires. Un jour de repos travaillé ne compte ni retard ni ponctualité : tout le temps passé est en heures sup. à valider.

Calcul des points (18/10/2026) : nouveau barème par heures faites, horaire type par employé, page « Mon pointage » de l'employé et colonne « Points » côté RH (section « Calcul des points »). Le calcul est dans `src/Service/Pointage/CalculPointage.php` (`pointsHeures`), testé dans `tests/Unit/Service/CalculPointageTest.php`.

Reste : tablette partagée, contrôle Wi-Fi, branchement des points sur les primes et la paie.

Sur le serveur, ajouter au cron (toutes les heures) :

```
0 * * * * cd /chemin/du/site && php bin/console app:pointage:entretien
```

Il prévient la RH une fois par jour des départs non pointés après l'heure d'alerte et supprime les photos arrivées à échéance.
