# Colis, étiquettes et contrôle final

D'après les maquettes PD05 (« Étiquettes à coller »), PD06 (« Contrôle final ») et l'écran de droite de PD07 (« Scanner une étiquette »), demande de Yaya du 20/10/2026. Menu **Atelier › Production**, onglets « Étiquettes » et « Contrôle final ». ERP local seulement : en ligne, ces adresses répondent « page introuvable » (`AtelierHorsLigneSubscriber`).

Le parcours d'une commande, une fois ses travaux terminés :

1. **Étiquettes** : une étiquette 100 × 60 mm par colis, avec un QR ; on la colle sur le colis.
2. **Contrôle final** : on compare la pièce au BAT, on la photographie, on coche les vérifications de chaque ligne.
3. **Scan** : chaque étiquette collée est scannée (téléphone, douchette ou code tapé).
4. **Prête** : au dernier de ces gestes (contrôle validé et tous les colis scannés), la commande passe « Prête ».
5. **Après** : le message WhatsApp pour le client est préparé, le bureau ajoute la livraison à une tournée.

Le code : `src/Controller/ProductionColisController.php` (étiquettes, QR, scan, scanner du téléphone), `src/Controller/ControleFinalController.php` (contrôle final), `src/Service/Production/ColisCommandes.php` (colis, scan, passage à « Prête »), `src/Service/Production/ControlesFinaux.php` (page du contrôle, photo, « Refaire une pièce », tournée, WhatsApp), `src/Service/Livraisons/PreparationLivraison.php` (bon de livraison créé, validé et planifié : le même chemin que les écrans Livraisons), règles pures `ColisSuggeres`, `ReglesControleFinal`, `MessagePrete`, `QuantiteLisible`, `NomsProduits`. Pages : `templates/production_colis/`, styles `public/css/fabrication-colis.css` et `public/css/etiquette-colis.css`.

## Étiquettes à coller (PD05)

Adresse : `/atelier/production/etiquettes`.

- **Travaux terminés · sans étiquette** : une carte par **commande** (pas par ligne) qui a au moins un travail terminé hors de ses colis. « CMD-000183 · Awa Diarra / 2 bâches roulées · 2 colis ». Les commandes entièrement terminées d'abord, puis la livraison la plus proche. Une commande dont un travail tourne encore est signalée (« 1 travail encore en production ») : ses étiquettes seront refaites quand il sera terminé.
- **Combien de colis ?** (− et +) : le nombre proposé suit la règle `ColisSuggeres` (testée) :
  - bâche, banderole, vinyle, toile (roulés) : 1 colis par pièce ;
  - kakémono, roll-up (dans leur housse) : 1 colis pour 2 ;
  - papier (flyers, cartes, affiches, brochures…) : des cartons de 500 ;
  - le reste : 1 colis.
  Le contenu se répartit entre les colis (« Bâche 300 × 100 cm × 1 · ourlet + œillets »).
- **Où coller**, selon l'emballage : bâche roulée « sur le film, à 10 cm du bord, côté visible » ; carton « sur le dessus, jamais sur une ouverture » ; kakémono « sur la housse ».
- **L'aperçu** montre chaque étiquette à sa taille réelle (100 × 60 mm) : logo, numéro de commande, « COLIS 1/2 », CLIENT (nom, puis la société ou le nom commercial), CONTENU, QR avec le code court dessous (« CMD-000183-C2 » ; « CMD-000183-S2-C2 » pour une série réimprimée avec un autre nombre de colis), LIVRAISON « mer. 21/10 » et le quartier, ou « RETRAIT à l'imprimerie », « NE PAS PLIER » sur ce qui est roulé. **Ni montant ni téléphone** sur l'étiquette.
- **Imprimer N étiquettes** ouvre une page d'impression à part (une étiquette par page, `@page 100mm 60mm`, sans marge) et lance l'impression du navigateur.
- **Réimprimer les mêmes** redonne les étiquettes en cours (même QR, même code). Imprimer une nouvelle série (autre nombre de colis) **remplace** l'ancienne : un message le demande d'abord ; les anciennes étiquettes ne se scannent plus (« étiquette remplacée »).

**L'imprimante** : « Imprimante : étiquettes 100 × 60 mm » est une indication. Dans le pilote de l'imprimante thermique, régler le papier sur 100 × 60 mm ; à l'impression, choisir cette imprimante, marges « Aucune », échelle 100 %.

Une seule sorte d'étiquette de colis : celle-ci. L'ancienne étiquette 100 × 70 mm par travail du poste machiniste (OP05) n'est plus imprimée ; les anciennes étiquettes déjà collées (`/livraisons/tournee/colis/{numéro}`) marchent toujours pour le livreur.

## Le QR d'un colis et le scan

- Le QR porte l'adresse `/c/{jeton}` (64 caractères tirés au hasard, propres au colis). Sous le QR, un **code court** à taper ou à lire à la douchette : `<numéro de commande>-C<rang>` (avec `-S<série>` à partir de la 2ᵉ série).
- **À l'atelier** (droit « Statuts de production ») : le QR ouvre la fiche du colis (« CMD-000183 · colis 2/2 · Awa Diarra ») avec le bouton « Colis collé et scanné ». **Ouvrir le lien n'enregistre rien** : seul le bouton le fait.
- **Le livreur** : le QR le mène à la remise de sa livraison quand le bon de livraison est à lui ; sinon à sa tournée, avec « Colis de la commande … : il n'est pas dans votre tournée ». Le bureau (droit « Commandes ») arrive sur la livraison de la commande.
- **Les autres** : accès refusé.
- Scanner deux fois ne change rien : « Déjà scanné à 11:02 ». Une étiquette remplacée est refusée. Sur la page du contrôle d'une commande, le colis d'une autre commande est refusé.

### Scanner les étiquettes au téléphone (PD07)

Adresse : `/atelier/operateur/scanner` (lien « Scanner une étiquette de colis » dans Atelier › Mon travail, et sur les pages Étiquettes et Contrôle final).

- La caméra arrière lit le QR (détecteur de codes du navigateur, sans bibliothèque en plus) et n'accepte que nos propres QR de colis. Le champ « Code sous le QR » marche aussi avec une douchette.
- Résultat : « CMD-000183 · colis 2/2 », le client et le contenu ; puis, quand c'est le dernier geste, « 2 colis sur 2 scannés : commande prête, client prévenu ». « Scanner le suivant » relance la caméra.
- Sur iPhone (pas de détecteur dans Safari) : ouvrir l'appareil photo et viser le QR ; le lien ouvre la fiche du colis, puis « Colis collé et scanné ».

## Contrôle final (PD06)

La liste : `/atelier/production/controle`. Les commandes dont tous les travaux sont terminés et qui ne sont pas encore prêtes, la livraison la plus proche d'abord, avec l'état des étiquettes (« sans étiquette », « 1/2 colis scanné ») et du contrôle. En dessous : les commandes passées « Prête » aujourd'hui, avec leur tournée ou « livraison à planifier ».

La page d'une commande : `/atelier/production/controle/{commande}`, « Contrôle final · CMD-000183 · Awa Diarra ».

- **Comparer au BAT** : l'image du BAT validé (« BAT validé · V1 ») à côté de la **photo de la pièce imprimée** (« prise à 10:58 »). « Photographier la pièce » ouvre l'appareil photo du téléphone ; sur ordinateur, « Caméra de la page » quand le navigateur le permet. La photo est vérifiée (15 Mo au plus, une image), **réencodée sans ses métadonnées** (EXIF, position GPS) et rangée hors de `public/`, dans `var/uploads/production/controles/`. Elle ne s'ouvre que par `/atelier/production/controle/{commande}/photo`, avec le droit « Voir la production ». Une nouvelle photo remplace l'ancienne (le fichier est effacé).
- **Les cases, par ligne** de la commande (toutes doivent être cochées) :
  - textes et numéro identiques au BAT ;
  - couleurs : la couleur à surveiller quand une note de la ligne, du BAT ou de la commande en cite une (« rouge conforme », « Pantone 186 C conforme »), sinon « Couleurs conformes au BAT » ;
  - taille mesurée : largeur × hauteur en cm, préremplies avec la taille commandée ; un écart de plus de 1 cm (ou de 1 % sur une grande pièce) se voit en orange et demande une confirmation. Sans taille (papier) : « Format A5 respecté » ;
  - finitions, avec le nombre d'œillets calculé (un tous les 50 cm, ou l'espacement écrit dans le nom de la finition) : « Œillets : 16 par bâche, bien sertis · ourlet conforme » ;
  - aucune trace, rayure ou bande.
  Qui a validé et à quelle heure sont gardés.
- **Scanner les étiquettes collées** : les colis et leur heure de scan (« Colis 1/2 scanné 11:02 »), le champ de scan et le lien vers le scanner du téléphone.
- **Temps réel vs estimé** : par travail puis pour la commande, chaque étape estimée et réelle (« Impression 28 min / 26 min, Séchage 45 / 45, Finition 40 / 52, Contrôle 10 / 12, Total 2 h 03 / 2 h 15 ») ; vert : dans l'estimé, orange : jusqu'à 10 % de plus, rouge au-delà ; « – » pour une étape pas encore mesurée. « Ces temps améliorent les prochaines estimations de délai. » Voir « Temps réels par étape » plus bas.
- **Consommé** : ce qui est sorti du stock pour les lignes de la commande (reprises comprises), en unités lisibles : « Bâche 510 g · laize 3,20 m 6,5 m² (dont 0,5 de chute) », « Encre 66 ml », « Œillets laiton 32 » (le nombre d'œillets, jamais « 0,064 boîte »). Sans article d'encre pour la machine : « … n'a pas d'article d'encre en stock : l'encre n'est pas sortie. » **Quantités seulement, jamais de prix ni de coût.** « Déduit du stock automatiquement. »
- **Avant « Prête »** : ce qui manque encore (contrôle validé, colis scannés : 1 sur 2).

### Refaire une pièce

Le bouton « Refaire une pièce » (avec une confirmation) demande le travail, le nombre de pièces et la raison. Alors :

- le travail repart en production pour ces pièces : il revient dans Ma file, colonne « Prêt » (bouton « Refaire 1 pièce sur … ») ; ses colis gardent leurs étiquettes, mais ceux qui le contiennent ne comptent plus comme scannés : une fois la pièce refaite et remise dans son colis, on le rescanne (« Réimprimer les mêmes » si l'étiquette était collée sur la pièce rejetée) ;
- le contrôle final de la commande est rouvert (les cases sont à recocher) ;
- une **réimpression** est notée au journal de la machine, au nom de l'opérateur du travail (elle compte dans ses points, comme une réimpression signalée au poste machiniste) ;
- le chef d'atelier est prévenu ;
- la matière de la reprise sort du stock sous sa propre référence (`PROD-000123-R1`), sans toucher à la première sortie.

Même règle que l'atelier pour agir sur un travail : le chef d'atelier, le poste de la machine, un opérateur affecté à la machine, ou une personne qui a pointé aujourd'hui (`docs/atelier-production.md`). Impossible quand la commande est déjà prête.

## Temps réels par étape

Chaque travail garde ses temps réels (`ordre_production.temps_reels`, ERP local) :

| Étape | Mesurée de … à … |
|---|---|
| Impression | sur un rouleau : de sa première pièce commencée à sa dernière pièce imprimée, pauses du rouleau retirées au prorata ; imprimé seul (Ma file) : Démarré → Terminé, pauses retirées |
| Séchage | fin de l'impression → début de la finition (heures de l'horloge) |
| Finition | « Commencer la finition » (impression en cours PD04, ou étiquettes PD05 ; sans appui : fin du séchage du profil) → étiquettes imprimées pour sa commande (heures d'ouverture, 8 h – 19 h, dimanche fermé) |
| Contrôle | étiquettes imprimées → contrôle final validé (heures d'ouverture) |

À la validation du contrôle, les temps de chaque étape sont gardés sur le travail avec le temps proposé. Une pièce refaite ajoute son passage à l'impression et marque l'étape « reprise ».

**Les estimations apprennent** (`FacteursEstimation` et `CorrectionsEstimation`, testés) : par machine et par étape, le facteur est la médiane de réel ÷ proposé sur les 20 derniers travaux terminés qui ont les deux, avec au moins 5 travaux, borné entre 0,5 et 2. Il corrige les temps **proposés** de Ma file (« Modifier l'estimation » : « corrigé d'après les 12 derniers travaux (× 1,1) ») et la durée affichée de l'imposition d'un rouleau (PD02). Les travaux avec une pièce refaite ne comptent pas. L'estimation que tape l'opérateur et les points (comparés à sa propre estimation) ne changent pas ; le temps proposé avant correction est gardé, pour que l'apprentissage ne se nourrisse pas de sa propre correction.

## Sorties du stock du contrôle final

À la validation du contrôle, les articles posés avec les finitions sortent du stock (`FIN-<n° du travail>`, `FIN-…-R1` pour les pièces d'une reprise, idempotent) : œillets = le nombre compté pour le contrôle × pièces (16 par bâche 300 × 100 × 2 bâches = 32), autre finition = un par pièce, convertis dans l'unité de l'article (une boîte de 500 : 32 œillets = 0,064 boîte). Le lien entre une finition et son article se règle dans les options partagées du catalogue, sur la finition (« Article du stock posé », direction, ERP local). La chute du rouleau et l'encre sortent à « Impression terminée » (`docs/production-rouleau.md`).

## « Prête »

Règle de Yaya : une commande passe **« Prête »** quand son **contrôle final est validé** et que **tous ses colis sont scannés** (au moins un), qu'il ne reste rien en production et que tous ses travaux terminés sont dans des colis. Cela se fait au dernier de ces gestes, depuis n'importe quel écran (contrôle final, scanner du téléphone, fiche du QR). Chaque ligne passe alors « prête pour la livraison » par le chemin du poste machiniste : le client le voit dans son espace, le livreur du bon (ou le bureau, « À planifier ») est prévenu, et les équipes livraison et commerciale reçoivent « Commande CMD-… prête » (une fois par commande, plus à la fin de la production : `docs/notifications-equipe.md`).

Une ligne seulement « terminée » n'est plus montrée « Prête » nulle part (tableau de bord, suivi commercial, espace client) : elle reste en « Finition / contrôle » jusqu'au contrôle final.

## Après « Prête » : tournée et WhatsApp

- La carte **Prête pour la livraison** donne le livreur, le jour et le créneau (« Bakary · mer. 21/10 · 10 h – 12 h »), puis « Ajoutée à la tournée de Bakary · 3 arrêts demain matin ». Pour un retrait : « Retrait à l'imprimerie ».
- **Ajouter à la tournée** (bureau seulement : droit « Livraisons » et, en plus, la direction ou le droit « Commandes », la règle des écrans Livraisons, pas élargie) : livreur, jour et créneau sont proposés d'après la livraison programmée par le client, sinon le prochain jour ouvré (pas le dimanche) de 10 h à 12 h. Un bouton crée le bon de livraison des lignes prêtes, le valide et le place en dernier dans la tournée du livreur, qui est prévenu. Pour le machiniste : « Le bureau l'ajoute à une tournée ».
- **Prévenir sur WhatsApp** : le message est préparé par le serveur (`MessagePrete`, testé), **jamais avec un montant** :
  - livraison : « Vos 2 bâches sont prêtes. Livraison demain entre 10 h et 12 h. Votre code de réception : 4827. » (le code vient du bon de livraison, une fois le bon créé) ;
  - retrait : « Vos 2 bâches sont prêtes. Vous pouvez les retirer à l'imprimerie. »
  Le bouton note « message préparé à 11:04 par Oumar » au journal de la commande (sans le texte), puis ouvre WhatsApp (`wa.me`) avec le message ; la personne l'envoie elle-même. Pas d'API WhatsApp.
- **Le code de réception** ne s'imprime jamais sur une étiquette et ne se montre jamais au livreur de ce bon : pour lui, ni le message ni le code.
- **Fiche de démonstration** (code client `DEMO-…`) : le message se lit sur la page, mais aucun lien : « Fiche de démonstration : aucun message. »

## Qui peut quoi

| Écran ou geste | Droit |
|---|---|
| Étiquettes, Contrôle final, photo du contrôle, scanner du téléphone : voir | « Voir la production » |
| Imprimer et réimprimer les étiquettes, scanner, photographier, cocher et valider, Prévenir sur WhatsApp | « Statuts de production » |
| Refaire une pièce | « Statuts de production » et la règle de l'atelier pour la machine du travail |
| Ajouter à la tournée | « Livraisons » et (direction ou « Commandes ») |
| QR d'un colis `/c/{jeton}` | « Statuts de production » (fiche du colis) ou « Livraisons » (remise de sa livraison) |

Aucun montant sur ces écrans ni sur les étiquettes. Le livreur seul (`LivreurSeul`) n'ouvre que le QR (vers sa remise) ; les pages de l'atelier lui sont refusées. Les numéros des commandes sont chiffrés dans les adresses (`docs/ids-chiffres.md`) ; le jeton du QR n'est pas un numéro.

## Données (ERP local, jamais synchronisées)

- `colis_production` : une ligne par colis : commande, série, rang et total (« 1/2 »), jeton du QR, code court, contenu (travaux et quantités), emballage (`rouleau`, `carton`, `housse`, `autre`), imprimé le / par, scanné le / par, remplacé le (`annule_le`).
- `controle_final` : un par commande : cases cochées par ligne (et tailles mesurées), validé le / par, photo (nom du fichier) prise le / par, nombre de reprises.
- `ordre_production.pieces_a_refaire` et `reimpressions` : la reprise en cours et leur nombre.
- `commandes_details.prete_livraison_le` : l'heure où la ligne est passée « prête » (synchronisée avec le statut de la ligne pour l'espace client).
- `ordre_production.temps_reels` (JSON) : début et fin d'impression, début de la finition, pièces de chaque reprise, et par étape le temps réel et le temps proposé.
- `finition.article_stock_id` : l'article de stock posé avec la finition (œillets, attaches).

## Démonstration

`php bin/console app:production-colis:demo` (lancé aussi par `app:demo`), clients `DEMO-PC…`, commandes `CMD-PC…`, relançable ; `--purger` enlève tout :

- à étiqueter : CMD-PC0183 Awa Diarra (2 bâches 300 × 100, ourlet et œillets → 2 colis), CMD-PC0430 Moussa Koita (1 kakémono, retrait), CMD-PC0186 Clinique Pasteur (2 kakémonos → 1 colis), CMD-PC0181 Awa Diarra (1 000 flyers → 2 cartons) ;
- à contrôler : CMD-PC0179 Pharmacie du Fleuve (2 banderoles imprimées sur un rouleau, étiquettes imprimées, 1 colis sur 2 scanné, photo prise ; temps de la maquette : impression 28 / 26 min, séchage 45 / 45, finition 40 / 52 ; chute et encre sorties) ;
- prêtes, dans la tournée de demo.bakary demain de 10 h à 12 h : CMD-PC0177 (Awa Diarra, 2 bâches, photo ; temps, chute, encre et 32 œillets sortis), CMD-PC0175 et CMD-PC0174, passées par les vrais services (étiquettes, scan, contrôle, tournée) ;
- Ma file : CMD-PC0172 Pharmacie du Fleuve, une bâche sur 3 refaite au contrôle (« Bande claire sur la 3e bâche ») et sa reprise démarrée : la barre, la fin estimée et le temps passé comptent la reprise seule.

La finition « Œillets tous les 50 cm » de la démonstration est liée à l'article « Œillets laiton (boîte de 500) ».

## Ce qui reste à décider

- La finition se mesure jusqu'aux étiquettes imprimées : un travail étiqueté longtemps après la fin de sa finition allonge son temps de finition. Un appui « Finition terminée » serait plus juste, au prix d'un geste de plus.
- Les autres finitions qu'œillets sortent un article par pièce (une attache, une barre) ; un nombre par pièce réglable sur la finition reste à prévoir si un article en demande plusieurs.
