# Outil de montage d'impression (PAO)

Yaya a envoyé le 11/10/2026 une page autonome « Montage Succès Imprim », à intégrer pour les infographistes. Copie : `/mnt/project-files/outils/montage-succes-imprim.html`.

## Ce que fait la page

Tout se passe dans le navigateur, sans serveur :
1. **Feuille** : A4, A3, SRA3 ou format perso, orientation, marge non imprimable de la machine.
2. **Fichier** : PDF (vectoriel conservé, TrimBox et fond perdu détectés), JPG ou PNG ; format fini ; verso éventuel (retournement bord long).
3. **Retouche** : adapter au format, échelle, rotation, décalage, fond perdu créé s'il manque, miroir (transfert, sublimation, DTF).
4. **Pose et coupe** : fond perdu, nombre de poses, mode de coupe, espace, rotation des poses si ça en loge plus, traits de coupe.
5. **Production** : quantité, gâche, faisabilité, nombre de feuilles, comparaison A4 / A3 / SRA3.
6. **Export** : PDF du montage, ou fichier redimensionné seul.

Bibliothèques : pdf-lib 1.17.1 et pdf.js 4.10.38, chargées depuis des CDN.

## Intégration prévue

- **Où** : Atelier › PAO › Montage, réservé aux graphistes, à la production et à l'admin.
- **Bibliothèques servies par l'application** (via l'importmap Symfony), pas par un CDN : l'outil marche alors sur le serveur local de l'atelier même sans Internet.
- **Depuis une commande** : un bouton « Monter » sur une ligne ouvre l'outil avec le fichier validé (BAT), le format fini et la quantité déjà remplis.
- **Enregistrement** : le PDF du montage est rangé dans les fichiers de la commande, avec sa version, comme fichier de production. Il apparaît dans la frise de la conception (W02, `docs/suivi-metiers.md`) : « Envoyé en production ».
- **Feuilles et coût** : le nombre de feuilles et la gâche calculés sont gardés sur la ligne. Ils servent au coût de revient (papier) et aux compteurs de la Canon, où une feuille A3 compte pour 2 A4 (`docs/immobilisations-primes.md`).
- **Réglages machines** : la marge non imprimable et les formats acceptés (SRA3 ou non) viennent de la fiche de chaque machine, au lieu d'être tapés à chaque fois.
- **Points** : le montage fait partie du temps de conception du graphiste ; il ne donne pas de points à part.

## Ordre

Après le tableau du graphiste (W01, W02) : d'abord l'outil tel quel dans l'admin avec les bibliothèques locales, puis le lien avec la commande et l'enregistrement du PDF, puis les réglages par machine.

## État (11/10/2026)

Fait :

- L'outil est à `/atelier/montage` pour l'admin, la prépresse et le responsable de production. pdf-lib et pdf.js sont servis depuis `public/vendor/montage/` (avec leurs licences) : il marche sans Internet.
- Le bouton « Monter » est sur l'écran de contrôle prépresse et sur l'évolution d'un travail (W02). L'outil charge la dernière maquette de la ligne (pas un montage déjà rangé), le format fini et la quantité.
- La marge, le fond perdu et les traits de coupe par défaut viennent de la tuile Montage des paramètres.
- « Ranger dans la commande » enregistre le PDF dans les fichiers de la ligne (`var/uploads/commandes`, non public), en nouvelle version, origine interne, état traité, avec le nombre de poses et la feuille. La frise de la conception note « Montage d'impression enregistré ».

Reste :

- Garder les feuilles et la gâche sur la ligne pour le coût de revient et les compteurs.
- Lire la marge et les formats acceptés depuis la fiche de chaque machine.
