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

- **Arrivée et départ automatiques** : le premier pointage du jour est l'arrivée, le dernier est le départ. L'employé n'a rien à choisir.
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

## Les points de ponctualité

Proposition de Yaya, à confirmer : chaque jour travaillé rapporte ou retire des points.

| Situation | Points |
|---|---|
| À l'heure (dans la tolérance de 10 min) | + 10 |
| En retard | − 10 |
| En retard de plus d'une heure | − 30 |
| Absence non justifiée (journée entière) | − 100 |
| Congé, absence validée, jour férié | 0, et la journée ne compte pas |

Les valeurs se règlent dans Q06.

**Note de ponctualité sur 100** pour l'évaluation (N02) : points du mois ÷ (10 × jours travaillés) × 100, ramenée entre 0 et 100.
- 22 jours, tous à l'heure : 220 ÷ 220 = **100**.
- 21 à l'heure, 1 retard : 200 ÷ 220 = **91**.
- 21 à l'heure, 1 absence non justifiée : 110 ÷ 220 = **50**.

Cette note est le critère « Ponctualité et présence » du barème des primes (`docs/immobilisations-primes.md`, poids 10 % pour la caisse et le commercial). Avant la mise en service du pointage, ce critère n'a pas de données : son poids est réparti sur les autres critères.

Les heures supplémentaires ne donnent pas de points : elles vont à la paie (sommes dues de l'espace employé), après validation par le responsable.

## Lien avec la paie

La feuille de présence du mois donne, par employé : jours présents, absences (justifiées ou non), retards, heures normales et heures supplémentaires. La paie et les sommes dues (`docs/espace-employe.md`) la reprennent ; rien n'est payé automatiquement.

## Confidentialité et conditions

- **HTTPS obligatoire** : sans HTTPS, le téléphone refuse d'ouvrir la caméra. Chaque téléphone demande une fois l'autorisation d'utiliser la caméra.
- **Photos** : visibles seulement avec le droit « administrateur RH » (nouveau droit à ajouter dans `src/Security/Droits.php`). Elles sont supprimées automatiquement après 90 jours ; les heures restent dans la feuille de présence. L'employé ne voit que ses propres pointages.
- **Information** : les employés sont informés à l'embauche (pointage, photo, durée de conservation). La durée de 90 jours est à confirmer avec le conseil juridique.
- **Tablette partagée** (option, désactivée par défaut) : pour les employés sans smartphone, une tablette à l'entrée ; on choisit son nom, puis la photo est prise.

## Ordre de construction

1. Points de pointage, affiche QR, pointage depuis le téléphone (scan, photo, heure serveur), écran de confirmation.
2. Pointages du jour, fiche du mois, corrections avec motif, feuille de présence PDF.
3. Contrôles en option (téléphone enregistré, GPS, Wi-Fi), tablette partagée, suppression des photos à 90 jours.
4. Points de ponctualité et note sur 100, branchés sur l'évaluation des primes et sur la paie.

Le calcul (arrivée/départ, pause, retard, points, note) est une classe sans base de données, testée seule.
