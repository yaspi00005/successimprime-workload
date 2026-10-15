# Studio textile (atelier 3D) et animations du site

Demandé par Yaya le 10/10/2026, avec un prototype : `/mnt/project-files/maquettes/atelier-3d/atelier-3d-prototype.html` (three.js r128). L'atelier est intégré au site public et à l'espace client depuis le 14/10/2026 ; les animations sont ajoutées sur tout le site. Le 15/10/2026, l'atelier devient le **« Studio textile »**, d'après la maquette `/mnt/project-files/maquettes/atelier-3d/studio-textile.png` (fond bleu nuit, bleu Succès, accent orange). Les adresses, le menu (« Atelier 3D »), les créations et les droits ne changent pas.

## En bref

Le client choisit un t-shirt, un polo ou une casquette et sa couleur. Il charge son logo, choisit sa position (poitrine gauche, dos haut…), ajoute un texte, et indique les quantités par taille. Il voit chaque article sous trois angles (face, dos, côté), rendus depuis le modèle 3D, ou à plat sur un téléphone modeste. Il peut tourner l'article à 360°, télécharger une maquette PNG et voir le prix calculé par le serveur, puis il clique « Valider mon modèle » :

- **visiteur sans compte** : une demande de devis du site (comme « Demander un devis »). Au local, elle crée le prospect « Site » et un devis brouillon au prix public ;
- **client connecté** : une commande « à confirmer ». Dans une entreprise, un demandeur envoie une demande que son valideur accepte avant qu'elle parte à l'atelier ;
- dans les deux cas, les vues de face et de dos et les images d'origine montent au local. Elles sont jointes comme **maquette du client** : le BAT reste à préparer par le graphiste.

## Où le trouver

| Qui | Où | Ce qu'il voit |
|---|---|---|
| Visiteur | Menu du site « Atelier 3D » (`/atelier-3d`), bande « Nouveau · Atelier 3D » de l'accueil | L'atelier, le prix public, « Envoyer ma demande de devis » |
| Client (particulier, titulaire, payeur, valideur) | Espace client : carte en haut de « Nouvelle commande », puce « Mes créations » de la page Commandes (téléphone), menu de gauche « Mes créations » | Son prix (grille, prix B2B, promotions), « Commander » |
| Demandeur d'une entreprise | Les mêmes entrées | Aucun montant (« Prix confirmé par votre valideur »), « Envoyer la demande » |
| Compte « Lecture · suivi » | — | Ni l'atelier ni « Mes créations » : renvoyé au tableau de bord avec le message « lecture seule » (règle de l'espace) ; un envoi reçoit 403 |
| Graphiste, caisse-commandes, direction (rôle Devis ou Commandes) | Ventes › Créations Atelier 3D (local) | Vues, réglages, images d'origine à télécharger (noté au journal), lien vers la commande ou la demande. Pas de prix |
| Direction (ROLE_ADMIN) | Réglages › Paramètres › Atelier 3D | Paliers de remise, seconde face, articles proposés |

Un employé sans le rôle Devis ni Commandes (production, livreur) reçoit 403 sur la liste, une fiche et chaque fichier ; sans session, c'est la page de connexion. Un client ne voit que les créations de son compte client (entreprise : celles de tous ses comptes) ; l'adresse d'une création d'un autre client donne 404.

## La page du studio

**En-tête** : logo du site, « Studio textile · Personnalisez vos vêtements », onglets T-shirt / Polo / Casquette (l'onglet choisit l'article et montre sa rangée), « Votre style, notre passion ! ».

**Étapes, à gauche** :

1. **Modèle** : une carte avec dessin par article proposé.
2. **Couleur du textile** : pastilles. Casquette : couleur de la visière (rouge au départ). Polo : « Bords du col et des manches » (aucun au départ, ou « Comme le polo », ou une couleur de l'article ; gardé dans la seconde couleur de la création, comme la visière).
3. **Logo** : zone de dépôt (PNG, JPG, SVG, WebP). Un logo d'exemple « VOTRE LOGO » est posé au départ avec le message « Logo d'exemple : chargez le vôtre, ou retirez-le, avant de valider » ; il ne part jamais à la validation.
4. **Position du logo** : une position par face, touchée de nouveau pour l'enlever ; un logo devant et un au dos comptent la seconde face. Curseur « Taille du logo » (15 à 100 %).
5. **Texte** (optionnel) : devant ou dos, 28 caractères, 5 polices, bouton Gras, couleur (automatique : noir sur un tissu clair, blanc sur un tissu foncé, jusqu'à ce que le client en choisisse une), sous le logo ou au-dessus.
6. **Aperçu / Export** : « Télécharger la maquette ».
7. **Quantités et devis / commande** : tailles, prix, « Valider mon modèle ». La maquette n'a que six étapes : cette septième garde ce que l'atelier faisait déjà (quantités, prix du serveur, validation).

**Positions** (la taille imprimée la plus grande est bornée par la zone d'impression de l'article) :

| Article | Devant | Dos |
|---|---|---|
| T-shirt, polo | Poitrine gauche, Poitrine droite (10 × 10 cm), Devant centre (toute la zone) | Dos haut (10 cm de haut), Dos centre (toute la zone) |
| Casquette | Devant (toute la zone) | Côté (6 × 4 cm) ou Arrière (8 × 4 cm) |

« Devant centre » n'est pas sur la maquette : c'est la place des créations faites avant le studio (logo centré), et une demande courante. Une création d'avant le studio est relue avec la première position de chaque face (Devant centre, Dos centre, Arrière).

**Vues, à droite** : une rangée par article, avec trois vues à caméra fixe du même modèle 3D (t-shirt et polo : Face, Dos, Côté ; casquette : Face, Angle, Côté). L'article choisi est encadré. La vue Côté montre le côté du logo (côté droit pour « Poitrine droite »), et la troisième vue de la casquette devient « Arrière » quand l'arrière est marqué. À côté : une colonne de miniatures (une vue en grand), puis 360°, zoom +, zoom −, plein écran (Échap pour sortir). Logo, texte et couleurs s'appliquent tout de suite à toutes les rangées.

**Maquette** (« Télécharger la maquette ») : une image PNG faite **dans le navigateur**, sans rien envoyer au serveur. Elle porte le logo du site, « Studio textile », l'article et sa couleur, les trois vues de l'article choisi, « Succès Imprim » et « Aperçu indicatif : couleurs et proportions confirmées sur le BAT ». Il n'y a ni prix ni nom du client. Fichier `maquette-<forme>-<couleur>.png`.

**Téléphone** : les étapes passent au-dessus des vues. La rangée de l'article choisi vient en premier, ses trois vues défilent au doigt (la vue suivante dépasse au bord), et les boutons font 46 px. La page suit la largeur du studio (requêtes de conteneur) et non celle de l'écran, car l'espace client a un menu à gauche.

## Produits, couleurs, tailles

- **Les articles viennent du catalogue.** Un article de l'atelier est un produit du catalogue (une `ProduitConfiguration` commandable en ligne). Il garde son prix public, son prix B2B, ses quantités minimum et maximum et ses finitions. Le réglage de l'atelier y ajoute seulement ce que le catalogue n'a pas : le modèle 3D (t-shirt, polo, casquette), les couleurs (« Nom #RRGGBB » par ligne), les tailles et la zone d'impression en cm (qui sert à juger la résolution).
- Réglages › Paramètres › Atelier 3D › bouton « Articles » (`/reglages/atelier-3d`, Direction seulement) : la liste des articles, le bouton « Produits textile du catalogue sans article » (crée les articles manquants avec les valeurs du prototype : forme devinée d'après le nom, 11 couleurs, S à XXL), un formulaire « Nouvel article ».
- Un article n'est proposé en ligne que s'il est **actif**, qu'il a au moins une couleur et que son produit est **commandable en ligne** (publié, avec un prix).
- Les articles descendent sur le site par la synchronisation (type `atelier_modele`, liste blanche ci-dessous). En ligne, la page des articles est en lecture seule.

## Prix

- Toujours **calculé par le serveur** (`/atelier-3d/prix` et `/espace/atelier-3d/prix`, JSON). Le prix éventuellement envoyé par la page est ignoré : à la validation, le serveur relit le modèle, les couleurs, les tailles et les faces, et la ligne de commande ou de devis est recalculée comme dans « Nouvelle commande ».
- Prix d'une pièce = prix du catalogue (prix public, ou prix B2B pour un revendeur) + « seconde face » si le dos est marqué, moins le palier de quantité.
- **Réglages › Paramètres › Atelier 3D** (Direction) : premier palier (25 pièces, −10 %), second palier (50 pièces, −15 %), seconde face (1 500 F par pièce), « Paliers aussi pour les revendeurs » (éteint : un revendeur garde son prix B2B, sans palier). Ces valeurs descendent sur le site (liste blanche `atelier_3d`).
- **Promotions** du client (docs/promotions.md) : affichées en indication (« Promotion … : −29 250 F, déduite par l'atelier en confirmant la commande »). Elles sont appliquées par l'atelier à la confirmation, comme pour une commande en ligne.
- Visiteur : prix public, sans promotion de client. Demandeur : aucun montant (ni dans la page, ni dans le JSON, ni dans la fenêtre de validation). Payeur et valideur : les montants.
- Exemple vérifié le 14/10/2026 (t-shirt démo à 5 000 F, devant + dos) : 30 pièces → 5 850 F la pièce (−10 %), 175 500 F ; 42 pièces → 245 700 F et « Plus que 8 pièces pour −15 % » ; revendeur Salon Espoir → 5 700 F la pièce sans palier.

## Images du client

- Envoyées dès qu'elles sont choisies (`/atelier-3d/image`, jeton CSRF, 40 envois par heure et par adresse IP) et contrôlées par le serveur : vrai PNG, JPG ou WebP, 15 Mo au plus, 36 millions de pixels au plus.
- **SVG** : accepté par la page seulement. Le navigateur le dessine comme une image (ses scripts ne s'exécutent pas) en PNG de 2 400 px sur le grand côté, et c'est ce PNG qui part. Un SVG de plus de 2 Mo, sans taille ni `viewBox`, ou illisible est refusé dans la page. Le serveur refuse toujours un SVG envoyé tel quel.
- **Réencodées sans leurs métadonnées** (EXIF, position GPS) : PNG gardé en PNG (transparence), photo en JPEG ; réduites à 5 000 px. Une photo de téléphone prise de côté est remise droite quand le serveur lit l'EXIF.
- Gardées **hors de `public/`** : d'abord dans `var/uploads/atelier`, au nom de la session qui les a envoyées (effacées après un jour si le modèle n'est pas validé), puis à la validation dans `var/uploads/commandes` comme fichier du client, avec leur empreinte. Elles ne sont servies que par des contrôleurs qui vérifient les droits.
- **Résolution** à la taille imprimée (zone de la position choisie, bornée par la zone de l'article, × taille choisie, moins la place du texte) : alerte dans la page sous 150 dpi (« Image un peu juste ») et sous 100 dpi (« basse résolution, risque de flou »). Le même calcul est fait par le serveur et joint à l'image (pastilles dans Ventes › Créations). Un logo de poitrine (10 cm) demande donc moins de pixels qu'un dos entier.
- Les vues rendues par la page (face et dos, 1 024 px) sont relues et réencodées en PNG ; rien d'autre ne passe.

## « Mes créations »

- Chaque modèle validé est une **création** (numéro `W-CR-0001`), gardée avec ses réglages : article, couleur (et visière ou bords du polo), tailles, images, et par face la position, la taille, le texte, la police, le gras, la couleur et la place du texte. Le résumé dit « Poitrine droite : image « logo.png » à 80 % » au lieu de « Devant ».
- Espace client › Mes créations : les vues, les réglages, la commande liée ; **« Rouvrir dans l'atelier »** (tout est repris, images comprises) et **« Commander à nouveau »** (mêmes réglages et quantités, au prix du jour, nouvelle commande « à confirmer » ; « Redemander » pour un demandeur).

## Côté technique

- Fichiers : `templates/atelier/_outil.html.twig` (la page, commune au site et à l'espace), `public/js/atelier-3d.js` (réglages, vues à plat, maquette, prix, validation), `public/js/atelier-3d-scene.js` (scène three.js), `public/css/atelier-3d.css`.
- **three.js 0.186.0** (licence MIT, fichier `LICENSE` gardé) copié dans `public/vendor/three/` : seuls les fichiers utilisés (`three.module.min.js`, `three.core.min.js`, `DecalGeometry` pour poser le marquage, `RoomEnvironment` pour l'éclairage, et pour un modèle GLB `GLTFLoader`, `BufferGeometryUtils`, `SkeletonUtils`). Chargé seulement sur la page de l'atelier, par une carte d'import dans cette page.
- **Polices** : Montserrat (400 et 700, celle du studio), Archivo Black, Bebas Neue, Pacifico et Figtree, toutes sous licence SIL Open Font License, avec leur fichier de licence, dans `public/vendor/polices-atelier/`. Elles ne sont chargées que par cette page : la police choisie (Montserrat d'office, 2 × 19 Ko) après le premier affichage, les autres quand le client les choisit. Le slogan « Votre style, notre passion ! » est en Pacifico, sans attente (`font-display: optional`) et seulement sur un écran assez large pour l'afficher. Une police à une seule graisse est épaissie par un contour quand « Gras » est choisi.
- **Rendu à la demande** : un seul moteur WebGL, hors de la page. Chaque vue fixe est rendue quand elle change (couleur, logo, texte, taille de la vue), puis recopiée dans son canevas : rien ne tourne en boucle. Seule la vue 360° s'anime, et seulement tant qu'elle est ouverte. La rangée de l'article choisi passe d'abord, les autres juste après, sans dépasser 12 ms de travail par image.
- **Affichage progressif** : la page s'affiche d'abord avec les vues à plat (dessin 2D, mêmes angles et mêmes réglages). La 3D est chargée ensuite, quand le navigateur est libre, si l'appareil s'y prête : carte d'import et WebGL disponibles, pas d'économie de données, pas de connexion 2G, au moins 2 Go de mémoire et 3 cœurs, et un WebGL sans « performances dégradées ». Sinon le bouton « Voir en 3D » reste là, et le choix du client est retenu sur son appareil. Si le navigateur perd la 3D (contexte WebGL perdu), les vues repassent à plat.
- **Réduire les animations** : la vue 360° ne tourne pas seule (le client la tourne au doigt ou à la souris), et les transitions de la page sont coupées.
- **Couleur** : la gestion des couleurs sRGB de three.js est active, sans compensation de tons (la compensation « neutre » noircissait les tissus foncés). L'éclairage de studio est réglé pour qu'un tissu vu de face garde la couleur de sa pastille ; le tissu est mat, avec un léger velours. Mesuré le 15/10/2026 au milieu du buste, pastille → tissu : Bleu Succès #0F4590 → #174A97, Rouge #DE1028 → #E4182D.
- **Marquage** : le logo et le texte sont dessinés dans une image (une par face), puis projetés sur le tissu (`DecalGeometry`) à la position choisie, en suivant les plis. La projection se fait sur le modèle au repos, quel que soit l'angle de la dernière vue.
- **Modèles dessinés** : t-shirt (buste aux plis légers, manches montées, col en côtes), polo (col rabattu qui suit les épaules, patte et boutons, bords qui peuvent prendre une autre couleur), casquette (six panneaux, bouton, visière en croissant, coutures). Le modèle GLB, quand il est déposé, les remplace (ci-dessous).
- Données : tables `atelier_modele` et `creation_atelier`, colonne `demande_devis.creation_id` (migration `Version20261014220000`). Le studio n'ajoute ni table, ni colonne, ni route : la position, le gras et les bords du polo sont dans les réglages JSON de la création.

## Modèle 3D acheté (GLB)

**Un vrai modèle se branche sans code** : déposer `public/modeles-3d/tshirt.glb`, `polo.glb` ou `casquette.glb` (et, si possible, `public/modeles-3d/modeles.json`). Sans fichier, la page garde ses modèles dessinés.

Ce que la page fait d'un GLB :

- elle le met à l'échelle (2,2 unités sur sa plus grande dimension, largeur ou hauteur) et le centre ;
- les pièces sont reconnues à un mot contenu dans le nom du maillage ou du matériau (minuscules) :
  - `tissu` prend la couleur choisie et reçoit le marquage ;
  - `visiere` (casquette) et `bords` (polo) prennent la seconde couleur ;
  - le reste garde son matériau ;
- le marquage est projeté sur `tissu` aux repères de `modeles.json`, ou, sans repère, à des points déduits de la taille du modèle ;
- si le modèle a des pièces `zone_devant` / `zone_dos`, le marquage est posé sur leurs coordonnées de texture (UV) au lieu d'être projeté ;
- sous les vues, la ligne « Modèle 3D : titre, auteur, licence » s'affiche avec ses liens quand `modeles.json` donne des crédits.

**`public/modeles-3d/modeles.json`** (lu par le serveur, 256 Ko au plus, sans le croire : seulement des nombres et du texte court, des liens http(s)) :

```json
{
  "tshirt": {
    "devant": [0, 0, 1],
    "poitrine": [0, 0.30, 0.12],
    "dos": [0, 0.31, -0.11],
    "hauteurCm": 72,
    "credits": {
      "titre": "T-shirt col rond",
      "auteur": "Nom de l'auteur",
      "licence": "CC BY 4.0",
      "licenceUrl": "https://creativecommons.org/licenses/by/4.0/",
      "sourceUrl": "https://adresse-de-la-page-du-modele"
    }
  },
  "casquette": { "face": [0, 0.04, 0.09], "cote": [0.09, 0.03, -0.01], "arriere": [0, 0.05, -0.09], "largeurCm": 20 }
}
```

- Clés en français ou en anglais (`front`, `chest`, `back`, `side`, `rear`, `heightCm`, `widthCm`, `title`, `author`, `license`, `licenseUrl`, `source`), par forme (`tshirt`) ou par fichier (`tshirt.glb`).
- Les points sont en **unités du GLB** (mètres), dans le repère du fichier ; la page leur applique la même échelle et le même centrage qu'au modèle. La profondeur peut être approchée : le point est reposé sur le tissu par un rayon venu de l'extérieur.
- `devant` : direction de l'avant (par défaut +Z). `poitrine` : milieu du devant à hauteur des aisselles (les logos de poitrine sont 9 cm de chaque côté et 2 cm plus haut ; « Devant centre » commence 5 cm plus haut, 4 cm plus bas sur un polo à cause de la patte). `dos` : milieu du dos à la même hauteur (« Dos haut » commence 9,5 cm plus haut). Casquette : `face` (ou `poitrine`) au milieu du devant de la couronne, `cote` au milieu du côté gauche, `arriere` (ou `dos`) au-dessus de la fermeture.
- `hauteurCm` (vêtement) ou `largeurCm` (casquette) : taille réelle du modèle, si le GLB n'est pas en mètres.

**Cahier des charges pour l'achat** (t-shirt, polo, casquette) :

| Point | Exigence |
|---|---|
| Format | glTF 2.0 binaire (`.glb`), un fichier par article, plus les fichiers source (Blender ou FBX) et les textures. |
| Poids | 1,5 Mo au plus par fichier, textures comprises (1 Mo conseillé : la page doit rester légère sur téléphone). Environ 20 000 à 40 000 triangles pour un vêtement, 15 000 pour une casquette. |
| Position | Mètres, taille réelle (t-shirt M environ 72 cm de haut), centré à l'origine, debout (+Y en haut), devant vers +Z. Pas d'animation, pas de squelette. |
| Forme | Vêtement seul, porté sans mannequin visible (effet « mannequin invisible »), col ouvert, plis légers et naturels. Le devant et le dos doivent rester assez lisses pour recevoir un logo. |
| Pièces | Noms de maillage ou de matériau contenant `tissu` (tout le tissu teint : buste, manches, col d'un t-shirt, couronne d'une casquette), `visiere` (casquette), `bords` (polo : col et bords des manches, s'ils sont séparés). Optionnel : `zone_devant` / `zone_dos`, plaques collées au tissu dont les UV couvrent la zone d'impression. Boutons, étiquettes et coutures à part, avec leur propre matériau. |
| Matériaux | PBR métal / rugosité. **Couleur de base neutre** (blanc ou gris très clair, sans teinte ni ombre cuite) : la page la multiplie par la couleur choisie. Le relief du tricot et des plis dans une carte de normales ; occlusion ambiante permise ; `KHR_materials_sheen` accepté. |
| Textures | PNG, JPEG ou WebP, 2 048 px au plus (1 024 conseillé). UV sans chevauchement sur le tissu. |
| Interdit | Compression Draco ou meshopt, textures KTX2 / Basis : la page n'embarque pas leurs décodeurs. |
| Licence | Usage commercial permis, sur un site web où le modèle est affiché dans le navigateur (le fichier y est donc téléchargeable). Par exemple CC0, CC BY 4.0 (avec crédits, affichés par la page) ou une licence commerciale qui autorise l'affichage web en temps réel. Fournir titre, auteur, licence et liens pour `modeles.json`. |
| Livraison | Les trois GLB, la fiche `modeles.json` avec les repères, une capture de face et de dos. |

## Synchronisation

| Donnée | Sens | Règle |
|---|---|---|
| Articles de l'atelier (`atelier_modele`) : forme, couleurs, tailles, zone, actif, ordre, produit | local → site | Le prix reste celui du produit. |
| Créations (`atelier_creation`) : numéro, auteur, désignation, forme, couleurs, tailles, quantité, réglages, nombre de commandes, dates, client, produit, commande, vues et images | site → local seulement | Le numéro (`W-CR-…`) et la dernière commande (« Commander à nouveau ») sont repris en ligne. Un produit retiré entre-temps ne bloque pas. |
| Demande du site : lien vers la création | site → local | La demande et son devis brouillon sont préparés au local, au prix public de l'atelier (seconde face et palier). |
| Réglage `atelier_3d` | local → site | Liste blanche : paliers, seconde face, paliers revendeur. |

Les vues et les images sont des fichiers du client (`origine = client`, avec empreinte) : le local les rapatrie comme les autres (docs/synchronisation.md, **Fichiers**).

## Animations du site

| Où | Animation |
|---|---|
| Accueil | Les blocs (univers, bande Atelier 3D) glissent en place au défilement. Le t-shirt du bandeau se retourne de temps en temps (devant « TEAM 2026 », dos « DIALLO 10 »). La dernière réalisation qui a une paire avant / après s'affiche avec un curseur à faire glisser. |
| Réalisations | Dans la visionneuse, les photos avant / après se comparent avec un curseur (souris, doigt ou flèches du clavier). |
| Produits | Légère inclinaison des cartes sous la souris (pas sur téléphone). |
| Calculateur | Le total défile jusqu'au nouveau montant (0,45 s). |
| Studio textile | Étapes et rangées qui montent à l'arrivée, barre d'envoi du logo, prix qui défile, fenêtre de validation. La vue 360° tourne seule tant qu'on ne la touche pas. |
| Espace client, suivi de commande | La frise des étapes se remplit l'une après l'autre ; quand la commande est prête, quelques confettis autour de « Prête », une seule fois par appareil. |

**Avant / après, sans réglage** : dans Réglages › Site vitrine › Réalisations, donner à une photo un texte alternatif qui commence par « Avant » et à une autre un texte qui commence par « Après » (avec ou sans accent). Les deux photos sont alors superposées ; les autres restent affichées normalement.

Règles respectées partout :
- avec « réduire les animations » sur l'appareil, rien ne bouge (le curseur avant / après marche quand même) ;
- aucune animation ne bloque un clic (seules des transformations changent, les confettis et les étiquettes laissent passer le doigt) ;
- sans JavaScript, tout reste visible (les photos avant / après l'une sous l'autre, le total sans défilement).

## Rapidité (mesures du 15/10/2026, studio)

Site en ligne en production (`APP_ENV=prod`), serveur PHP intégré sans compression, téléphone 390 px, sans cache :

| Page | « 3G normale » (750 kb/s, 100 ms) | « Fast 3G » (1,4 Mb/s, 562 ms) et processeur ralenti 4 fois |
|---|---|---|
| Accueil, 77 Ko | affichée en 1,1 s, chargée en 1,0 s | 1,6 s |
| Studio, 172 Ko avant la 3D (page 34 Ko, styles 47 Ko, logo 21 Ko, script 72 Ko) | premier et plus grand affichage en 1,3 à 1,5 s, chargée en 2,2 à 2,3 s | 1,8 à 1,9 s, chargée en 2,3 s |
| Studio, la 3D en plus | +860 Ko sans compression (environ un quart une fois compressé), arrivée vers 12 s | arrivée vers 10 s |

Le studio est utilisable à plat dès son affichage : les vues à plat, les étapes et le message du logo d'exemple (écrit dans la page) s'affichent sans attendre la 3D. La 3D arrive ensuite sans bloquer la page ; en production, la compression du serveur (docs/deploiement.md) divise son poids par 4.

## Ce qui reste à faire

- **Vrais modèles 3D** (t-shirt, polo et casquette, format GLB, selon le cahier des charges ci-dessus), à acheter ou à faire faire par un modélisateur 3D. Le reste fonctionne déjà avec les modèles dessinés.
- Photos avant / après des vraies réalisations, à saisir dans l'admin.
- Questions ouvertes pour Yaya :
  - faut-il les paliers pour les revendeurs ? (éteint par défaut) ;
  - un demandeur voit le total dans « Nouvelle commande » mais pas dans l'atelier : faut-il aligner ?
  - les promotions restent indicatives dans l'atelier et sont appliquées par l'atelier à la confirmation : est-ce bien ce qui est voulu ?
  - polo : propose-t-on vraiment des bords (col et manches) d'une autre couleur ? Le studio le permet (aucun par défaut) ;
  - le menu du site dit toujours « Atelier 3D » : faut-il l'appeler « Studio textile » ?
