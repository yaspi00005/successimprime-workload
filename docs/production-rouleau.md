# Production au rouleau

Du fichier validé au rouleau qui tourne sur la machine, d'après les maquettes PD01 à PD04 et PD07 (« Production », 20/10/2026). Menu **Atelier › Production**, puis la barre du haut : « Parcours d'un fichier », « Rouleaux », « Impression en cours », « Machines et dossiers ». Au local seulement : en ligne, toutes ces pages répondent « page introuvable » (`AtelierHorsLigneSubscriber`).

| Écran | Adresse | Pour qui |
| --- | --- | --- |
| Parcours d'un fichier (PD01) | `/atelier/production/parcours` | droit « Production » |
| Rouleaux, Nouveau rouleau | `/atelier/production/rouleaux`, `/rouleaux/nouveau` | voir : « Production » ; préparer : voir « Qui peut agir » |
| Imposition au rouleau (PD02) | `/atelier/production/rouleau/{id}/imposition` | idem ; le coût seulement avec « Coûts et marges » |
| Fiche de production (PD03) | `/atelier/production/rouleau/{id}`, `/imprimer` (A4) | idem |
| Impression en cours (PD04, PD07) | `/atelier/production/rouleau/{id}/en-cours`, `/en-cours/etat` (lu toutes les 20 s) | idem |
| Mon travail › Impression en cours | `/atelier/operateur/en-cours` | droit « Production » |
| Machines et dossiers | `/atelier/production/machines` | voir : « Production » ; modifier : chef d'atelier |
| Règles de nommage | `/atelier/production/nommage` | voir : « Production » ; modifier : chef d'atelier |

Le code : `src/Controller/ProductionRouleauController.php`, `src/Controller/ProductionMachinesController.php`, `src/Service/Production/` (`NomFichierProduction` et `ImpositionRouleau` purs et testés, `FichierProduction`, `TiragesRouleau`, `ControlesTirage`, `EnvoiRip`, `ParcoursFichier`, `ProfilsImpression` ; pour l'impression en cours `ImpressionEnCours`, `ProgressionRouleau` et `ConsommationProduction` purs et testés, `SortiesProduction`), `src/Controller/ImpressionEnCoursController.php`, `src/Entity/TirageRouleau.php`, `src/Entity/PieceTirage.php`, `templates/production_rouleau/`, `public/css/fabrication-rouleau.css`, `public/js/production-rouleau.js`. Les pages suivent le gabarit commun `templates/fabrication/_base.html.twig` (`FabricationExtension`).

Voir aussi `docs/atelier-production.md` (files, planning, encre) et `docs/poste-machiniste.md` (Ma file : la suite après « Démarrer »).

## Qui peut agir

- **Voir** toutes ces pages : droit « Production » (`ROLE_PRODUCTION_VOIR`). **Aucun montant**, sauf la ligne « Coût matière + encre » de l'imposition, avec « Coûts et marges » (`ROLE_COUT_MARGE`).
- **Agir sur un rouleau** (préparer, placer, envoyer au RIP, cocher, scanner, test de buses, encre, Démarrer, annuler ; pendant l'impression : Pièce imprimée, Pause, Reprendre, Signaler un problème, Impression terminée, Commencer la finition) : « Statuts de production » (`ROLE_PRODUCTION_GERER`), puis la règle de l'atelier, comme Ma file : chef d'atelier, poste de la machine (adresse IP reconnue), opérateur affecté à la machine ; machine sans opérateur affecté : avoir pointé son arrivée aujourd'hui. Les autres voient la fiche avec la raison, sans bouton.
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
- Sur la base d'essai, la racine n'est pas réglée : les pages le disent (« Dossier de la machine non réglé : le fichier reste dans l'ERP »).

## Parcours d'un fichier (PD01)

Chercher une commande ou un client ; sans recherche, le dernier fichier parti au RIP. La frise en 7 étapes (BAT validé, fichier de production, imposition, dossier de la machine, impression, finition, étiquette et contrôle) avec, à chaque étape, qui et quand ; la première étape pas encore faite est marquée « à faire ». L'étape « Fichier de production » reprend ce que l'analyse du prépresse a trouvé, tel quel : type, format de page d'un PDF, résolution réelle d'une image, fonds perdus, couleurs (« RVB : conversion aux couleurs d'impression au RIP »). Puis le nom du fichier de production découpé en parties, « Télécharger sous ce nom », le rouleau qui le porte, et les dossiers de chaque machine (les plus larges d'abord, le traceur à la fin). « Règles de nommage » s'ouvre avec le nom de ce travail en exemple.

## Rouleaux et imposition (PD02)

**Nouveau rouleau** : choisir la machine (machines au rouleau : laize d'au moins 0,30 m, pas à la feuille, pas le traceur, active) et le support. Les travaux transmis qui attendent ce support sur cette machine sont proposés par urgence et cochés d'office jusqu'à la longueur maximale du rouleau. « Placer sur le rouleau » crée le tirage `R-MMJJ-NN`.

**Placement automatique** (`ImpositionRouleau`) : par rangées (étagères), le plus urgent en premier, chaque pièce tournée si elle tient mieux ; marge de chaque côté et espace entre les pièces des Réglages. Une pièce trop large pour la laize utile, sans dimensions ou qui dépasserait la longueur maximale est refusée, avec la raison.

**Étiquette de chaque pièce** sur le plan : le produit et sa taille (« Kakémono 120 × 200 », « ↻ » si elle est tournée), puis le numéro de commande, le client, « 1/2 » quand la commande compte plusieurs exemplaires et « ⏱ 14:00 » pour une livraison promise aujourd'hui. Sur une pièce étroite, la deuxième ligne passe sur plusieurs lignes.

**Placement manuel** : « Manuel », puis glisser une pièce (souris ou doigt), flèches du clavier (Maj : 10 cm), R pour la tourner, « Enregistrer le placement ». Le serveur recontrôle tout : chevauchement, marges, laize ; en cas de refus, la raison s'affiche et rien ne change. « Automatique » ou « Recalculer » revient au placement calculé. L'ordre d'impression se change avec les flèches de la légende.

**Bilan** : m² imprimés, mètres et m² de rouleau consommés, % utilisé, chute. **Estimation** : durée (vitesse du profil de la machine pour ce support, corrigée par les temps réels de la machine : « corrigé d'après les 12 derniers travaux (× 1,1) », voir `docs/production-colis.md`), séchage avant finition, encre (ml/m² du profil). La durée gardée sur le rouleau reste celle du profil ; seule la durée affichée est corrigée. **Coût matière + encre** (« Coûts et marges » seulement) : m² consommés × coût du m² de l'article du rouleau + m² imprimés × coût de l'encre au m² de la machine ; « coûts à régler » si l'un manque (jamais de total à moitié). Le coût en stock de l'article est ramené au m² selon son unité : au m² tel quel, au mètre divisé par la laize, au rouleau divisé par longueur × laize ; une autre unité donne « coûts à régler ». « Pourquoi la 3,20 m ? » explique le choix de la machine.

**Annuler le rouleau** : les travaux reviennent en attente ; les fichiers déjà envoyés restent dans l'historique.

**Deux rouleaux en même temps** : un travail ne peut être que sur un rouleau. Le serveur refuse de le mettre sur un deuxième rouleau tant que le premier n'est pas annulé (« CMD-000183 est déjà sur le rouleau R-1022-03. »). Si deux personnes ont créé leur rouleau au même moment, le dernier enregistré garde le travail ; l'autre refuse ensuite de placer, d'enregistrer le placement ou de démarrer (« … est passé sur le rouleau R-1022-04. Retirez-le de ce rouleau ou annulez celui-ci. »).

## Envoyer au RIP

Un seul PDF pour tout le rouleau, monté **dans le navigateur** (pdf-lib, `public/vendor/montage/`) : chaque pièce à sa place à l'échelle 1:1, repères de coupe à 3 mm des coins, numéro de commande et nom du client dans la marge de gauche. Pour un PDF, c'est le format fini (TrimBox, celle que posent les outils du prépresse ; sinon la page visible) qui remplit la pièce : le fond perdu n'est jamais rétréci dedans, il en reste 3 mm au plus autour (moins si l'espace entre les pièces est plus petit), pour que les repères restent dehors. Le type de chaque fichier (PDF, PNG, JPEG) est lu dans son contenu, fichiers de « Mes produits » compris. Au-delà de 5 m, le PDF a plusieurs pages, coupées entre deux rangées.

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

**Démarrer** se débloque quand il n'y a plus ni rouge ni bleu, sur un rouleau envoyé au RIP. Il démarre chaque travail du rouleau comme Ma file (`ActionsOrdre::demarrer`, sur la machine du rouleau) ; si l'un refuse, aucun ne démarre et la raison s'affiche. « Démarrer » ouvre ensuite l'impression en cours (PD04) ; la fiche d'un rouleau démarré affiche « Impression démarrée à 10:24 par Oumar » et le bouton « Impression en cours » (« Suivi de l'impression » une fois imprimé). Le placement ne change plus. Dans Ma file, un travail d'un rouleau lancé n'a plus ses boutons Terminé et Pause : « Rouleau R-1022-03 : suivre l'impression » y mène.

À côté : les travaux dans l'ordre d'impression (aperçu, finitions, livraison promise), « Après l'impression » (séchage, coupe, finition, roulage), l'envoi au RIP (fichiers séparés : chacun se télécharge sous son nom de production), la machine (état, niveaux d'encre, « Relever les niveaux » pour le chef d'atelier, le poste de la machine et ses opérateurs, comme sur l'atelier), le machiniste et le QR du rouleau. « Imprimer la fiche » donne la fiche A4 à poser sur la machine, sans montant.

## Impression en cours (PD04) et suivi au téléphone (PD07)

La machine n'a pas d'API : l'avancement vient des gestes de l'opérateur et s'estime entre deux (`ProgressionRouleau`, pur et testé). Ce qui est estimé est écrit « estimé » ou « ≈ ».

- **La carte de la machine** : « EN IMPRESSION » ou « EN PAUSE », « Pièce 2 sur 5 », la pièce en cours (« Kakémono 85 × 200 · Clinique Pasteur »), « 4,9 m² sur 11,8 (estimé) », « fin vers 10:52 ».
- **Pièce imprimée** : la pièce en cours est confirmée maintenant, la suivante commence. Entre deux appuis, les m² de la pièce en cours = temps effectif depuis son début (pauses retirées) × vitesse du profil, jamais plus que sa surface.
- **Les chiffres** : démarré à et par qui ; vitesse réelle = m² des pièces confirmées ÷ temps effectif (celle du profil tant qu'aucune pièce n'est confirmée), avec « prévu 25 » ; encre utilisée ≈ m² imprimés × ml/m² du profil, « sur 142 estimés » ; pièces terminées « 1 / 5 » et ce qui sèche. Fin = maintenant + m² restants ÷ vitesse réelle.
- **Les pièces du rouleau** : imprimée à 10:31 · séchage jusqu'à 11:16, en cours, à venir.
- **Encres** : niveaux de la machine et heure du dernier relevé, la couleur sous le seuil en rouge, « Relever les niveaux ».
- **Rappel** : le point « Couleurs sensibles » de la fiche (« Comparer le rouge Awa Mode au BAT imprimé sur la 1re pièce avant de lancer la 2e »).
- **Ensuite sur cette machine** : les rouleaux envoyés ou préparés, puis les travaux de la file qui ne sont sur aucun rouleau (dans l'ordre de la file, 4 au plus), avec leur heure prévue et « même rouleau » ou « changer de rouleau ».
- **Pause / Reprendre** : le rouleau et ses travaux en cours passent en pause par `ActionsOrdre` ; la pause ne compte ni dans la vitesse ni dans les temps.
- **Signaler un problème** : pièce ratée, bourrage, tête bouchée, couleur hors BAT, encre vide, autre, et quelques mots. C'est un incident de la machine (le chef d'atelier est prévenu, comme depuis l'atelier). « Pièce ratée » la compte en gâche de son travail ; « La machine est à l'arrêt » met le rouleau en pause.
- La page relit son état toutes les 20 secondes (rien quand l'onglet est caché) ; sans JavaScript, tout marche en rechargeant.

**Au téléphone (PD07)** : la même adresse à 390 px montre la carte compacte de la maquette (« SOLVANT 3,20 M · EN IMPRESSION », la pièce, la barre, la fin), le rappel, et en bas les gros boutons Pause, Problème et « Pièce imprimée ». **Mon travail › Impression en cours** (`/atelier/operateur/en-cours`, lien à côté du scanner d'étiquettes) mène au rouleau qui s'imprime : celui de la machine de l'opérateur (opérateur affecté, ou machine du poste reconnu par son adresse), ou le seul rouleau lancé pour le chef d'atelier ; avec plusieurs rouleaux, ou pour quelqu'un sans machine, la liste des rouleaux en impression ; sinon « Aucun rouleau en impression », avec Ma file et le scanner. Le lien « Suivre l'impression en cours » est sur la page Mon travail, sous le scanner.

**La liste des rouleaux** montre d'abord ceux en impression (« Suivre », pièce 2 sur 5, fin vers), puis préparés, envoyés au RIP, imprimés en finition, terminés ou annulés du jour.

## Impression terminée

« Impression terminée » confirme les pièces restantes, puis pour chaque travail du rouleau : `ActionsOrdre::terminer` avec ses pièces (les ratées réimprimées se saisissent par travail, 0 par défaut, ou reprennent celles notées pendant l'impression), et son temps d'impression mesuré (de sa première pièce commencée à sa dernière imprimée, pauses du rouleau retirées au prorata). Le rouleau passe « Imprimé ». **La machine est libre dès la fin de l'impression** : la file de l'atelier fait partir le travail suivant à cette heure-là, le séchage se fait à côté.

Ensuite, sur la même page, chaque travail sèche (« sec à 11:16 ») puis **Commencer la finition** (aussi sur les étiquettes, PD05). Sans appui, la finition compte à partir de la fin du séchage du profil. Le rouleau passe « Terminé » quand tous ses travaux ont passé leur contrôle final (ou que leur commande est « Prête »).

**Sorties du stock** (`SortiesProduction`, origine production, idempotentes, quantités seulement) :

- **Chute du rouleau** (`ROUL-<numéro>`) : pour chaque ligne du rouleau, m² consommés du rouleau × part de ses m² imprimés, moins ce qui est déjà sorti pour cette ligne et cet article à « Terminé » (la recette du produit, `PROD-…`). Les parts sont arrondies à 3 décimales, la dernière ligne prend le reste : le total tombe juste. La chute ne va jamais au journal de gâche de la machine (elle coûterait à l'opérateur le point « gâche sous 3 % »).
- **Encre** (`ROUL-<numéro>-ENCRE`) : m² imprimés × ml/m² du profil, partagée à parts égales entre les articles d'encre de la machine (articles en litres liés à la machine, comme le relevé d'encre du mois). Sans article d'encre, rien ne sort et l'écran le dit. Un travail imprimé seul depuis Ma file sort son encre à « Terminé » (`PROD-…-ENCRE`) quand la machine a un profil pour son support.
- Les œillets et attaches sortent au contrôle final (`docs/production-colis.md`).

## Machines et dossiers, Règles de nommage

Une carte par machine : code du dossier (un dossier par machine), tête d'impression, usages, miroir par défaut et jusqu'à 12 profils d'impression (support, profil ICC, mode, m²/h, ml/m², chauffe, séchage, miroir). Le support d'un profil se reconnaît par l'identifiant ou le code de l'article, ou un mot de son nom (« 440 »). Le chef d'atelier modifie avec « Modifier ».

Règles de nommage : la règle, un exemple découpé, et les codes des supports et des finitions (« tiré du nom » quand aucun n'est réglé).

## Réglages

Tuile « Production : dossiers et rouleaux » (famille Atelier, direction) : dossier de production vu du serveur, le même vu des postes, marge (10 cm), espace entre les pièces (4 cm), longueur maximale d'un rouleau (30 m), seuil d'encre bloquant (20 %), séchage par défaut (45 min). ERP local seulement : ces réglages ne partent pas vers le site.

## Tables

`tirage_rouleau` (le rouleau : machine, support, laize, marge, espace, mode, statut, bilan, envois au RIP, points cochés, démarrage), `piece_tirage` (chaque exemplaire placé : position, taille, rotation, rang), et des colonnes sur `atelier_machine` (dossier, tête, usages, miroir, profils), `supports` et `finition` (`code_production`), `ordre_production` (nom du fichier de production et de découpe, tirage, copie dans le dossier de la machine). Migration `Version20261020231700`. Puis (`Version20261021230000`) : sur `tirage_rouleau` les pauses, les pièces ratées par travail, imprimé le / par et terminé le ; sur `piece_tirage` l'heure où la pièce a commencé ; sur `ordre_production` les temps réels (`temps_reels`) ; sur `finition` l'article de stock posé (`article_stock_id`). Rien ne part vers le site (pas dans la synchronisation).

## Démonstration

`php bin/console app:production-rouleau:demo` (lancée par `app:demo` après le poste machiniste ; jamais en production ni en ligne ; rejouable, elle efface d'abord ce qu'elle a créé) : dossiers et profils des machines (SOLVANT-320, SOLVANT-180, DTF-60 en miroir, CANON-A3, DECOUPE avec un traceur de découpe 1,30 m), l'article « Bâche 440 g frontlit · laize 3,20 m » (`D-MAT-006`), six commandes `CMD-PR…` de clients `DEMO-PR01` à `DEMO-PR06` avec leurs fichiers dessinés et leur BAT validé (Moussa Koita urgent pour 14 h, Clinique Pasteur ×2, Awa Diarra ×2 avec « rouge Awa Mode », Pharmacie du Fleuve en bâche 510 g et Hôtel Niger en bâche 440 g qui attendent, Lycée Askia sur la Solvant 1,80 m).

- **En impression (PD04, PD07)** : le rouleau `R-MMJJ-03` de la Solvant 3,20 m (Koita, Pasteur, Awa : 5 pièces, 90 % utilisé), envoyé au RIP, jaune remplacé 4 minutes avant, démarré il y a 10 minutes par Oumar : la 1re pièce sèche, la 2e s'imprime, 3 à venir. « Ensuite » : l'Hôtel Niger (même rouleau) puis la Pharmacie du Fleuve (changer de rouleau), mis en tête de la file par le chef.
- **Bloqué (PD03)** : le rouleau `R-MMJJ-04` de la Solvant 1,80 m (Lycée Askia, « bleu du logo » à surveiller), envoyé au RIP, points cochés, test de buses fait ; le jaune à 18 % bloque « Démarrer » jusqu'au relevé des niveaux.
- La démonstration des colis (`app:production-colis:demo`) ajoute des rouleaux imprimés pour ses commandes : temps réels, chute et encre sorties. Comptes : `demo.oumar` (opérateur de la Solvant), `demo.ibrahim` (chef d'atelier), `demo.seydou` (graphiste : voit, ne peut pas agir), `demo.kadiatou` (direction, voit le coût), mot de passe `demo1234`.

## Ce qui reste à décider

- Les rouleaux physiques n'ont pas encore de code en stock : le scan compare le code lu à la référence de l'article (`D-MAT-006`). Un vrai code par rouleau (étiquette du fournisseur, numéro de lot) reste à choisir.
- La copie dans le dossier de la machine se fait à l'envoi au RIP (et sur demande), pas dès la transmission du prépresse.
- La racine réelle du dossier de production (`\\ATELIER\Production` ?) est à régler sur le serveur de l'imprimerie.
- Le coût affiché vient du coût en stock de l'article et du coût d'encre de la machine ; la maquette montrait « 21 800 F » pour le même rouleau, la démonstration calcule 15 504 F : la formule (main-d'œuvre, chute comptée ou non) est à confirmer.
- L'article du rouleau est trouvé par le code tiré de sa désignation, ou par celui du support quand un code y est réglé à la main.
- La vitesse et la fin de l'impression viennent des appuis sur « Pièce imprimée » : sans branchement à la machine, un oubli d'appui ralentit la vitesse affichée jusqu'à l'appui suivant.
- L'encre estimée est partagée à parts égales entre les cartouches CMJN de la machine ; une vraie part par couleur demanderait le relevé des niveaux avant et après chaque rouleau.
- Les PDF envoyés au RIP restent dans `var/uploads/production/rouleaux` (toutes les versions) et partent donc dans la sauvegarde des fichiers : combien de temps les garder reste à décider.
