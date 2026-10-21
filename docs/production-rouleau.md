# Production au rouleau

Du fichier validé au rouleau qui tourne sur la machine, d'après les maquettes PD01 à PD03 (« Production », 20/10/2026). Menu **Atelier › Production**, puis la barre du haut : « Parcours d'un fichier », « Rouleaux », « Machines et dossiers ». Au local seulement : en ligne, toutes ces pages répondent « page introuvable » (`AtelierHorsLigneSubscriber`).

| Écran | Adresse | Pour qui |
| --- | --- | --- |
| Parcours d'un fichier (PD01) | `/atelier/production/parcours` | droit « Production » |
| Rouleaux, Nouveau rouleau | `/atelier/production/rouleaux`, `/rouleaux/nouveau` | voir : « Production » ; préparer : voir « Qui peut agir » |
| Imposition au rouleau (PD02) | `/atelier/production/rouleau/{id}/imposition` | idem ; le coût seulement avec « Coûts et marges » |
| Fiche de production (PD03) | `/atelier/production/rouleau/{id}`, `/imprimer` (A4) | idem |
| Machines et dossiers | `/atelier/production/machines` | voir : « Production » ; modifier : chef d'atelier |
| Règles de nommage | `/atelier/production/nommage` | voir : « Production » ; modifier : chef d'atelier |

Le code : `src/Controller/ProductionRouleauController.php`, `src/Controller/ProductionMachinesController.php`, `src/Service/Production/` (`NomFichierProduction` et `ImpositionRouleau` purs et testés, `FichierProduction`, `TiragesRouleau`, `ControlesTirage`, `EnvoiRip`, `ParcoursFichier`, `ProfilsImpression`), `src/Entity/TirageRouleau.php`, `src/Entity/PieceTirage.php`, `templates/production_rouleau/`, `public/css/fabrication-rouleau.css`, `public/js/production-rouleau.js`. Les pages suivent le gabarit commun `templates/fabrication/_base.html.twig` (`FabricationExtension`).

Voir aussi `docs/atelier-production.md` (files, planning, encre) et `docs/poste-machiniste.md` (Ma file : la suite après « Démarrer »).

## Qui peut agir

- **Voir** toutes ces pages : droit « Production » (`ROLE_PRODUCTION_VOIR`). **Aucun montant**, sauf la ligne « Coût matière + encre » de l'imposition, avec « Coûts et marges » (`ROLE_COUT_MARGE`).
- **Agir sur un rouleau** (préparer, placer, envoyer au RIP, cocher, scanner, test de buses, encre, Démarrer, annuler) : « Statuts de production » (`ROLE_PRODUCTION_GERER`), puis la règle de l'atelier, comme Ma file : chef d'atelier, poste de la machine (adresse IP reconnue), opérateur affecté à la machine ; machine sans opérateur affecté : avoir pointé son arrivée aujourd'hui. Les autres voient la fiche avec la raison, sans bouton.
- **Machines et dossiers**, **Règles de nommage** : « Statuts de production » et « Chef d'atelier » (la direction a tout). Le réglage du dossier du serveur : tuile Réglages › « Production : dossiers et rouleaux », direction seulement.
- Chaque envoi demande le jeton du formulaire ; le droit est revérifié par le serveur, pas seulement par les boutons cachés. La recette des accès le contrôle (`RecetteProductionRouleau`).

## Le nom du fichier de production

`Commande_Ligne_Support_Taille_Quantité_Finitions`, par exemple `CMD-000183_L1_BACHE440_300x100_x2_OURLET-OEIL50.pdf` (`NomFichierProduction`). Le fichier de découpe du traceur : le même nom suivi de `_decoupe` (`…_decoupe.svg`).

- L'ERP le compose quand le prépresse transmet le travail à la production (contrôle validé, fichier corrigé revenu de Ma file, commande d'un produit du client) et le garde sur l'ordre de production. Personne ne le tape.
- Code d'un support ou d'une finition : celui de « Règles de nommage », sinon le premier mot et le premier nombre de son nom, sans accent (« Bâche 440 g roll-up » → `BACHE440`, « Œillets tous les 50 cm » → `OEIL50`). 16 caractères par code au plus.
- Lettres sans accent, chiffres, `-` et `_` ; 150 caractères au plus : au-delà, la fin (les finitions) est coupée.
- Un code changé vaut pour les prochains travaux ; les fichiers déjà nommés gardent leur nom.

## Le dossier de chaque machine

Chaque machine a son dossier de dépôt `<racine>\<CODE>\IN`, que son RIP surveille (`SOLVANT-320\IN`, `DTF-60\IN`, `DECOUPE\IN` pour le traceur). La racine se règle dans Réglages › « Production : dossiers et rouleaux » : le chemin vu du serveur de l'ERP (là où il écrit) et le même chemin vu des postes (celui qu'on montre à l'équipe).

- **Le fichier du client n'est jamais déplacé ni renommé.** L'ERP en copie un exemplaire, sous son nom de production, jamais par-dessus un fichier existant (« -2 », « -3 » sont ajoutés).
- La copie se fait **à l'envoi au RIP** (le PDF du rouleau, ou les fichiers séparés) et sur demande avec « Copier dans le dossier de la machine » (Parcours d'un fichier). Rien n'est copié à la transmission du prépresse.
- Racine vide, inaccessible depuis le serveur, ou placée dans le dossier de l'application (`public/` serait servi, `var/uploads/` relu) : rien n'est copié ; le fichier reste dans l'ERP et se télécharge sous son nom de production (« Télécharger sous ce nom »). Jamais dans `var/uploads/dossier_partage` : le dossier partagé relirait la copie comme un fichier reçu.
- Sur la base d'essai, la racine n'est pas réglée : les pages le disent (« Dossier du serveur pas encore réglé »).

## Parcours d'un fichier (PD01)

Chercher une commande ou un client ; sans recherche, le dernier fichier parti au RIP. La frise en 7 étapes (BAT validé, fichier de production, imposition, dossier de la machine, impression, finition, étiquette et contrôle) avec, à chaque étape, qui et quand. Puis le nom du fichier de production découpé en parties, « Télécharger sous ce nom », le rouleau qui le porte, et les dossiers de chaque machine.

## Rouleaux et imposition (PD02)

**Nouveau rouleau** : choisir la machine (machines au rouleau : laize d'au moins 0,30 m, pas à la feuille, pas le traceur, active) et le support. Les travaux transmis qui attendent ce support sur cette machine sont proposés par urgence et cochés d'office jusqu'à la longueur maximale du rouleau. « Placer sur le rouleau » crée le tirage `R-MMJJ-NN`.

**Placement automatique** (`ImpositionRouleau`) : par rangées (étagères), le plus urgent en premier, chaque pièce tournée si elle tient mieux ; marge de chaque côté et espace entre les pièces des Réglages. Une pièce trop large pour la laize utile, sans dimensions ou qui dépasserait la longueur maximale est refusée, avec la raison.

**Placement manuel** : « Manuel », puis glisser une pièce (souris ou doigt), flèches du clavier (Maj : 10 cm), R pour la tourner, « Enregistrer le placement ». Le serveur recontrôle tout : chevauchement, marges, laize ; en cas de refus, la raison s'affiche et rien ne change. « Automatique » ou « Recalculer » revient au placement calculé. L'ordre d'impression se change avec les flèches de la légende.

**Bilan** : m² imprimés, mètres et m² de rouleau consommés, % utilisé, chute. **Estimation** : durée (vitesse du profil de la machine pour ce support), séchage avant finition, encre (ml/m² du profil). **Coût matière + encre** (« Coûts et marges » seulement) : m² consommés × coût unitaire en stock de l'article du rouleau + m² imprimés × coût de l'encre au m² de la machine ; « coûts à régler » si l'un manque. « Pourquoi la 3,20 m ? » explique le choix de la machine.

**Annuler le rouleau** : les travaux reviennent en attente ; les fichiers déjà envoyés restent dans l'historique.

## Envoyer au RIP

Un seul PDF pour tout le rouleau, monté **dans le navigateur** (pdf-lib, `public/vendor/montage/`) : chaque pièce à sa place à l'échelle 1:1, repères de coupe à 3 mm des coins, numéro de commande et nom du client dans la marge de gauche. Au-delà de 5 m, le PDF a plusieurs pages, coupées entre deux rangées.

- Le PDF part par morceaux (5 Mo, moins si PHP n'en accepte pas autant), 400 Mo au plus. Le serveur vérifie la taille et l'en-tête `%PDF-`, le range sous `var/uploads/production/rouleaux/` (jamais écrasé, jamais servi en direct), le copie dans le dossier de la machine et le note : V1, V2… avec qui et quand. Les morceaux attendent dans `var/uploads/production/tmp/`, effacés après l'envoi ou au bout de 24 heures.
- **Fichiers séparés** : si une pièce n'est ni PDF, ni PNG, ni JPEG (PSD, AI, TIFF…), si un fichier manque ou si le tout dépasse 400 Mo, la page propose « Envoyer les fichiers séparés » : chaque fichier sous son nom de production et une feuille de placement en PDF (plan à l'échelle et positions).
- Les fichiers de découpe partent dans le dossier `DECOUPE`.
- **Placement changé après l'envoi** (manuel, recalcul, ordre d'impression, travail retiré) : le fichier du RIP n'est plus le bon. Le rouleau redevient « Préparé », la fiche et l'imposition le disent en rouge et « Démarrer » attend le nouvel envoi (V2…). Un recalcul qui ne bouge aucune pièce ne change rien.
- **Les couleurs ne sont pas converties par l'ERP** : le serveur ne fait ni CMJN ni PDF/X. Le RIP de la machine convertit avec le profil du support (Machines et dossiers).
- « PDF du rouleau » et « Feuille de placement » se téléchargent depuis la fiche, avec le droit « Production ».

## Fiche de production (PD03)

« Avant d'appuyer sur Imprimer » : les points à vérifier, en vert (bon), bleu (à faire), orange (à surveiller) ou rouge (bloquant) (`ControlesTirage`).

| Point | Vert quand | Rouge quand |
| --- | --- | --- |
| Support chargé = support commandé | rouleau scanné (le code doit être la référence de l'article) ou coché après lecture de l'étiquette | — (un mauvais code est refusé : « changez de rouleau ») |
| Profil couleur, Mode | cochés | aucun profil de la machine pour ce support |
| Test de buses | « Test fait » aujourd'hui sur cette machine, 0 buse bouchée (noté au journal de la machine) | — (buses bouchées : orange, nettoyer et refaire) |
| Encres | toutes au-dessus du seuil (20 % par défaut) | une encre sous le seuil : la remplacer et relever les niveaux |
| Séchage | coché (chauffe et séchage du profil) | pas de chauffe réglée |
| Sens et miroir | coché (miroir pour un film de transfert) | — |
| Échelle et taille, Couleurs sensibles | toujours orange : à surveiller pendant l'impression (couleurs : mots des observations, « rouge Awa Mode ») | — |

**Démarrer** se débloque quand il n'y a plus ni rouge ni bleu, sur un rouleau envoyé au RIP. Il démarre chaque travail du rouleau comme Ma file (`ActionsOrdre::demarrer`, sur la machine du rouleau), note qui et quand, et renvoie à « Ma file » pour la suite (pause, terminer, étiquettes). Le placement ne change plus.

À côté : les travaux dans l'ordre d'impression (aperçu, finitions, livraison promise), « Après l'impression » (séchage, coupe, finition, roulage), l'envoi au RIP, la machine (état, niveaux d'encre, « Relever les niveaux »), le machiniste et le QR du rouleau. « Imprimer la fiche » donne la fiche A4 à poser sur la machine, sans montant.

## Machines et dossiers, Règles de nommage

Une carte par machine : code du dossier (un dossier par machine), tête d'impression, usages, miroir par défaut et jusqu'à 12 profils d'impression (support, profil ICC, mode, m²/h, ml/m², chauffe, séchage, miroir). Le support d'un profil se reconnaît par l'identifiant ou le code de l'article, ou un mot de son nom (« 440 »). Le chef d'atelier modifie avec « Modifier ».

Règles de nommage : la règle, un exemple découpé, et les codes des supports et des finitions (« tiré du nom » quand aucun n'est réglé).

## Réglages

Tuile « Production : dossiers et rouleaux » (famille Atelier, direction) : dossier de production vu du serveur, le même vu des postes, marge (10 cm), espace entre les pièces (4 cm), longueur maximale d'un rouleau (30 m), seuil d'encre bloquant (20 %), séchage par défaut (45 min). ERP local seulement : ces réglages ne partent pas vers le site.

## Tables

`tirage_rouleau` (le rouleau : machine, support, laize, marge, espace, mode, statut, bilan, envois au RIP, points cochés, démarrage), `piece_tirage` (chaque exemplaire placé : position, taille, rotation, rang), et des colonnes sur `atelier_machine` (dossier, tête, usages, miroir, profils), `supports` et `finition` (`code_production`), `ordre_production` (nom du fichier de production et de découpe, tirage, copie dans le dossier de la machine). Migration `Version20261020231700`. Rien ne part vers le site (pas dans la synchronisation).

## Démonstration

`php bin/console app:production-rouleau:demo` (lancée par `app:demo` après le poste machiniste ; jamais en production ni en ligne ; rejouable, elle efface d'abord ce qu'elle a créé) : dossiers et profils des machines (SOLVANT-320, SOLVANT-180, DTF-60 en miroir, CANON-A3, DECOUPE avec un traceur de découpe 1,30 m), l'article « Bâche 440 g frontlit · laize 3,20 m » (`D-MAT-006`), quatre commandes `CMD-PR…` de clients `DEMO-PR01` à `DEMO-PR04` avec leurs fichiers dessinés et leur BAT validé (Moussa Koita urgent pour 14 h, Clinique Pasteur ×2, Awa Diarra ×2 avec « rouge Awa Mode », Pharmacie du Fleuve en bâche 510 g qui attend). Le rouleau `R-MMJJ-03` de la Solvant 3,20 m : 5 pièces, 90 % utilisé, envoyé au RIP (V1), points cochés, test de buses fait ; le jaune à 18 % bloque « Démarrer » jusqu'au relevé des niveaux. Comptes : `demo.oumar` (opérateur de la Solvant), `demo.ibrahim` (chef d'atelier), `demo.seydou` (graphiste : voit, ne peut pas agir), `demo.kadiatou` (direction, voit le coût), mot de passe `demo1234`.

## Ce qui reste à décider

- Les rouleaux physiques n'ont pas encore de code en stock : le scan compare le code lu à la référence de l'article (`D-MAT-006`). Un vrai code par rouleau (étiquette du fournisseur, numéro de lot) reste à choisir.
- La copie dans le dossier de la machine se fait à l'envoi au RIP (et sur demande), pas dès la transmission du prépresse.
- La racine réelle du dossier de production (`\\ATELIER\Production` ?) est à régler sur le serveur de l'imprimerie.
- Le coût affiché vient du coût en stock de l'article et du coût d'encre de la machine ; la maquette montrait « 21 800 F » pour le même rouleau, la démonstration calcule 15 504 F : la formule (main-d'œuvre, chute comptée ou non) est à confirmer.
- L'article du rouleau est trouvé par le code tiré de sa désignation ; un code réglé à la main sur le support n'est pas encore pris en compte pour ce choix.
