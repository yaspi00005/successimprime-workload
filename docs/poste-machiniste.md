# Poste machiniste

Le poste du machiniste, d'après les maquettes OP01 à OP06 (« Poste machiniste »). Menu **Atelier › Ma file machine** (`/atelier/ma-file`). Sur ordinateur et sur téléphone.

Le code : `src/Controller/MaFileController.php`, `src/Service/Atelier/PosteMachiniste.php` (ce que montrent les pages), `src/Service/Atelier/TempsEstime.php` (les calculs, testés dans `tests/Unit/Service/Atelier/TempsEstimeTest.php`), `src/Service/Atelier/ActionsOrdre.php` (démarrer, pause, terminer : le même chemin que l'atelier et le poste de production), `src/Entity/RetourPrepresse.php`, `templates/ma_file/`, `public/css/poste-machiniste.css`, `public/js/poste-machiniste.js`.

Les travaux au rouleau (bâches, kakémonos, vinyle) se regroupent d'abord sur un rouleau : imposition, envoi au RIP et fiche de production, dont « Démarrer » fait passer tous les travaux « en cours » ici (`docs/production-rouleau.md`).

## Le parcours d'un travail

1. **À vérifier** : le prépresse a envoyé le fichier en production. Le machiniste ouvre le fichier et le vérifie.
2. **Prêt à démarrer** : fichier conforme. Le machiniste choisit la machine et estime le temps.
3. **En cours** : démarré sur la machine. Chronomètre et avancement estimé.
4. **Terminé, à étiqueter** : le machiniste imprime une étiquette par colis (Production › Étiquettes), puis fait le contrôle final et scanne les colis (`docs/production-colis.md`).
5. **Prêt pour livraison** : au contrôle final validé et au dernier colis scanné ; le livreur (ou le bureau) et le client sont prévenus.

Un fichier qui ne va pas repart au prépresse (« Retourné au prépresse ») et revient « À vérifier » quand le graphiste l'a corrigé.

## La file (OP01)

- Cinq colonnes : À vérifier, Prêt à démarrer, En cours, Terminé à étiqueter, Prêt pour livraison. Quatre cartes par colonne, puis « Voir N autres ».
- Chaque carte : numéro de commande, date promise (en rouge si elle est passée), client, travail et taille. Puis selon la colonne : les m², le temps estimé et la machine, l'heure de démarrage et l'avancement, l'heure de fin, le nombre de colis et le livreur.
- En haut : les puces des machines. « Mes machines » regroupe les machines où la personne est affectée (Atelier › Files d'attente › Affecter les opérateurs), puis celles où elle a travaillé ces 60 derniers jours. Sur le poste d'une machine (adresse IP reconnue), « Ce poste » est choisi d'office.
- Le bandeau « Retourné au prépresse » montre les travaux partis en correction : motif, heure du renvoi, graphiste, et depuis combien de temps.
- « Étiquettes » (avec le nombre de travaux à étiqueter) et « Scanner un bon » : on tape ou on scanne le numéro de commande, d'ordre ou d'étiquette (une douchette marche comme un clavier). Le code d'une étiquette de colis (« CMD-…-C2 ») ouvre la fiche du colis pour le scanner.
- Les travaux terminés il y a plus de 7 jours ne sont plus montrés.

## Vérifier le fichier (OP02)

- L'aperçu du fichier, à l'échelle de la commande : cotes en millimètres, zone de sécurité (5 cm du bord), œillets tous les 50 cm si la finition est commandée. Un PDF s'affiche dans la page ; un fichier de travail (PSD, AI…) se télécharge avec « Ouvrir le fichier ».
- Quatre tuiles : dimensions, résolution, couleurs, version. Elles viennent de l'analyse du fichier qui existe déjà au prépresse.
- À droite : le client, le produit, la taille, la machine prévue, les finitions et les observations (celle du client, celle du graphiste).
- Le contrôle avant impression : dimensions, textes dans la zone de sécurité, résolution, matière en stock (obligatoires), et la consigne du client quand il y en a une. Les cases que le prépresse a déjà validées sont cochées d'avance, sauf si l'analyse du fichier les trouve en erreur.
- « Fichier conforme » enregistre qui a vérifié et à quelle heure, puis ouvre le choix de la machine.
- Pour un fichier lié depuis « Mes produits » (`docs/mes-produits.md`), l'empreinte du fichier est revérifiée : un fichier modifié ou introuvable bloque « Fichier conforme ».

## Machine et temps estimé (OP03)

- Les machines du même procédé, avec leur laize, leurs têtes, leur vitesse et l'heure où elles se libèrent. Une machine trop étroite pour le plus petit côté du travail est marquée « impossible ». La machine recommandée est la première libre qui convient.
- Matière et réglages : le rouleau, la consommation prévue (« 2 × 4,1 m = 8,2 m de rouleau ») et le sens de pose.
- **Le temps estimé par le machiniste**, étape par étape. L'outil propose :
  - impression : m² ÷ vitesse de la machine ;
  - séchage avant finition : 30 min, seulement quand il y a des finitions ;
  - finitions : 10 min par finition et par exemplaire en grand format (pièce de plus de 0,25 m²), sinon 15 min par lot de 100 ;
  - contrôle et emballage : 10 min.
  Chaque durée est arrondie aux 5 minutes. Le machiniste corrige ce qu'il veut (« 1 h 30 », « 1h30 », « 90 », « 45 min ») et peut noter la raison de l'écart. Le total et l'heure de fin se recalculent pendant la saisie.
- Début : maintenant (arrondi aux 5 minutes) ou l'heure où la machine se libère. La fin prévue est comparée à la date promise.
- « Vos estimations récentes » : les trois derniers travaux terminés avec l'estimé et le réel, et l'écart moyen du mois. Ce n'est pas une sanction : cela sert à mieux proposer la prochaine fois.
- « Démarrer à … » quand la machine est libre ; sinon « Prévoir à … ». L'estimation remplit le planning de l'atelier (durée, début et fin prévus).

## Retourner au prépresse (OP04)

- Une fenêtre sur la page de vérification. À gauche, le fichier : on entoure, on pose une flèche ou un texte sur le problème. À droite : le motif (dimensions, fond perdu, texte trop près du bord, résolution, couleur, mauvaise version, autre), un commentaire (obligatoire avec « Autre »), et le graphiste qui recevra le retour.
- À l'envoi : le travail sort de la file et passe dans le bandeau « Retourné au prépresse » ; le contrôle prépresse repasse « correction requise » avec le motif ; le graphiste qui a préparé le fichier est prévenu (sinon tout le prépresse) ; la frise de la conception note le retour.
- Le temps d'attente est compté au prépresse, pas au machiniste.
- Quand le graphiste renvoie le fichier corrigé en production (« Valider et envoyer en production »), le travail revient « À vérifier » avec le nouveau fichier et le machiniste est prévenu. Le bandeau montre le temps passé en correction.

## Colis et livraison (OP05)

**Une seule étiquette de colis** : celle de Production › Étiquettes (PD05), 100 × 60 mm, une par colis et par commande, avec son QR (`docs/production-colis.md`). L'ancienne étiquette 100 × 70 mm par travail n'est plus imprimée ici. Régler le pilote de l'imprimante d'étiquettes sur 100 × 60 mm.

- À gauche, le suivi de production : fichier vérifié, retours éventuels, démarré, terminé (estimé et réel), étiquettes, prêt pour livraison. Puis le bilan (pièces bonnes, gâche, observation).
- Au milieu, les colis de la commande : « Colis 1/2 · CMD-…-C1 », leur contenu, scanné ou à scanner ; puis « 1 colis sur 2 scannés · contrôle final à faire ».
- À droite : « Étiqueter les colis » (Production › Étiquettes, la commande choisie) et « Contrôle final », puis la livraison : mode, date, livreur. Le reste à encaisser n'est affiché qu'avec le droit « Paiements » ; sinon seulement « à encaisser à la remise » ou « réglé ».
- « Terminer » mène directement à Production › Étiquettes pour la commande du travail.
- La commande passe « Prête » au contrôle final, quand tous ses colis sont scannés : la ligne passe « prête », le client le voit dans son espace, le livreur du bon (ou l'équipe commandes s'il n'y a pas encore de bon) est prévenu. Il n'y a plus de bouton « Prêt pour livraison » sur cette page.
- Les anciennes étiquettes déjà collées (QR `/livraisons/tournee/colis/{numéro d'étiquette}`) marchent toujours : pour le livreur du bon, la remise au client ; pour le bureau, la commande.

## Le travail sur la machine (OP06)

- La carte de la machine : statut, barre d'avancement, heure de démarrage, fin estimée, temps passé (chronomètre).
- De gros boutons, l'un sous l'autre : Démarré (ou Démarrer), Terminé (pièces imprimées gâche comprise, gâche, cause de la gâche, observation), Pause / Reprendre, Étiquettes, Prêt pour livraison (mène au contrôle final une fois le travail terminé), Signaler un problème (panne, gâche, têtes nettoyées, réimpression).
- Sur téléphone, la vérification du fichier garde l'aperçu, la fiche et deux boutons en bas : « Prépresse » et « Conforme ».

## Qui peut agir

- Voir la file : le droit « Voir la production ». Vérifier, estimer, démarrer, terminer, étiqueter, contrôler : le droit « Statuts de production ».
- Démarrer, mettre en pause, terminer et signaler suivent la règle de l'atelier (`docs/atelier-production.md`) : le chef d'atelier, le poste de la machine (adresse IP reconnue), ou un opérateur affecté à la machine. Pour une machine sans opérateur affecté, une personne qui a pointé son arrivée aujourd'hui peut aussi agir depuis son téléphone.
- **Aucun montant** pour le machiniste. Le reste à encaisser n'apparaît qu'avec le droit « Paiements ».

## Données

- `ordre_production` : `fichier_verifie_le` et `fichier_verifie_par_id`, `controle_machiniste` (cases cochées), `estimation` (étapes proposées et estimées, raison), `nombre_colis` et `etiquettes_imprimees_le` (tenus à jour par les étiquettes de colis), `pret_livraison_le` et `pret_livraison_par_id`. Les colis eux-mêmes : `colis_production` (`docs/production-colis.md`). Les ordres déjà démarrés au moment de la migration sont marqués « fichier vérifié ».
- `retour_prepresse` : un retour par renvoi, avec les motifs, le commentaire, le dessin (formes en coordonnées 0 à 1), le destinataire et l'heure de correction.

## Démonstration

`php bin/console app:poste-machiniste:demo` (lancé aussi par `app:demo`) crée huit travaux `CMD-PM…` dans la file de `demo.oumar` : deux à vérifier (dont la bâche Clinique Kayes avec son aperçu), un prêt à démarrer, un en cours, un terminé à étiqueter, deux prêts pour livraison (une livraison de 3 colis, un retrait ; préparés avant les étiquettes de colis, sans colis scannés) et un kakémono retourné au prépresse avec un dessin. Il pointe l'arrivée d'Oumar à 07:52 s'il ne l'a pas fait. `--purger` les enlève.
