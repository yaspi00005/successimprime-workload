# Atelier 3D et animations du site

Demandé par Yaya le 10/10/2026, avec un prototype : `/mnt/project-files/maquettes/atelier-3d/atelier-3d-prototype.html` (three.js r128). L'atelier est intégré au site public et à l'espace client depuis le 14/10/2026 ; les animations sont ajoutées sur tout le site.

## En bref

Le client choisit un t-shirt, un polo ou une casquette et sa couleur. Il place son logo et son texte devant et dos, et indique les quantités par taille. Il voit le modèle en 3D (ou à plat sur un téléphone modeste) et le prix calculé par le serveur, puis il clique « Valider mon modèle » :

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
- **Réencodées sans leurs métadonnées** (EXIF, position GPS) : PNG gardé en PNG (transparence), photo en JPEG ; réduites à 5 000 px. Une photo de téléphone prise de côté est remise droite quand le serveur lit l'EXIF.
- Gardées **hors de `public/`** : d'abord dans `var/uploads/atelier`, au nom de la session qui les a envoyées (effacées après un jour si le modèle n'est pas validé), puis à la validation dans `var/uploads/commandes` comme fichier du client, avec leur empreinte. Elles ne sont servies que par des contrôleurs qui vérifient les droits.
- **Résolution** à la taille imprimée (zone de l'article × taille choisie, moins la place du texte) : alerte dans la page sous 150 dpi (« Image un peu juste ») et sous 100 dpi (« basse résolution, risque de flou »). Le même calcul est fait par le serveur et joint à l'image (pastilles dans Ventes › Créations).
- Les vues rendues par la page (face et dos, 1 024 px) sont relues et réencodées en PNG ; rien d'autre ne passe.

## « Mes créations »

- Chaque modèle validé est une **création** (numéro `W-CR-0001`), gardée avec ses réglages : article, couleur (et visière), tailles, images, textes.
- Espace client › Mes créations : les vues, les réglages, la commande liée ; **« Rouvrir dans l'atelier »** (tout est repris, images comprises) et **« Commander à nouveau »** (mêmes réglages et quantités, au prix du jour, nouvelle commande « à confirmer » ; « Redemander » pour un demandeur).

## Côté technique

- **three.js 0.186.0** (licence MIT, fichier `LICENSE` gardé) copié dans `public/vendor/three/` : seuls les fichiers utilisés (`three.module.min.js`, `three.core.min.js`, et pour un modèle GLB `GLTFLoader`, `BufferGeometryUtils`, `SkeletonUtils`). Chargé seulement sur la page de l'atelier, par une carte d'import dans cette page.
- **Polices** : Archivo Black, Bebas Neue, Pacifico et Figtree (licence OFL, fichiers de licence gardés) dans `public/vendor/polices-atelier/`.
- **Affichage progressif** : la page s'affiche d'abord avec la vue à plat (dessin 2D, face et dos, mêmes réglages). La 3D est chargée ensuite, quand le navigateur est libre, si l'appareil s'y prête : carte d'import et WebGL disponibles, pas d'économie de données, pas de connexion 2G, au moins 2 Go de mémoire et 3 cœurs, et un WebGL sans « performances dégradées ». Si l'animation tombe sous 20 images par seconde, la page revient à plat et le bouton « Voir en 3D » reste là. Le choix du client est retenu sur son appareil.
- **Couleur** : corrigée. La gestion des couleurs sRGB de three.js est active, sans compensation de tons, et l'éclairage totalise environ π sur la face avant. Le tissu est un matériau mat (peu de reflet, léger velours). Mesuré le 14/10/2026 au bas du buste, pastille → tissu : Bleu Succès #0F4590 → #154690 (le prototype le rendait bleu clair), Noir #1F2026 → #232429, Rouge #DE1028 → #DA152A, Blanc #F4F4F1 → #EEEEEB, Jaune #FDC82A → #F6C22B, Vert #1F8A4C → #228A4D, Ciel #6FB3E6 → #6DAFE1.
- **Téléphone** : les pastilles du haut passent à la ligne au lieu de se chevaucher, et l'aide « Glissez pour tourner » disparaît au premier geste ou après 6 secondes (elle couvrait le col). Le doigt fait tourner le modèle et la page défile toujours à la verticale.
- **Modèles 3D** : le t-shirt, le polo et la casquette sont dessinés par la page (formes simples). **Un vrai modèle se branche sans code** : déposer `public/modeles-3d/tshirt.glb`, `polo.glb` ou `casquette.glb`. Le modèle est mis à l'échelle tout seul. Ses pièces doivent porter dans leur nom (maillage ou matériau) `tissu` (prend la couleur choisie), `visiere` (casquette) et `zone_devant` / `zone_dos` (reçoivent le marquage, avec des coordonnées UV qui couvrent la zone d'impression).
- Données : tables `atelier_modele` et `creation_atelier`, colonne `demande_devis.creation_id` (migration `Version20261014220000`).

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
| Atelier 3D | Bandes du logo qui défilent, cartes qui montent, entrée du modèle, prix qui défile, fenêtre de validation. |
| Espace client, suivi de commande | La frise des étapes se remplit l'une après l'autre ; quand la commande est prête, quelques confettis autour de « Prête », une seule fois par appareil. |

**Avant / après, sans réglage** : dans Réglages › Site vitrine › Réalisations, donner à une photo un texte alternatif qui commence par « Avant » et à une autre un texte qui commence par « Après » (avec ou sans accent). Les deux photos sont alors superposées ; les autres restent affichées normalement.

Règles respectées partout :
- avec « réduire les animations » sur l'appareil, rien ne bouge (le curseur avant / après marche quand même) ;
- aucune animation ne bloque un clic (seules des transformations changent, les confettis et les étiquettes laissent passer le doigt) ;
- sans JavaScript, tout reste visible (les photos avant / après l'une sous l'autre, le total sans défilement).

## Rapidité (mesures du 14/10/2026)

Site en ligne en production (`APP_ENV=prod`), serveur PHP intégré sans compression, téléphone 390 px, sans cache :

| Page | « 3G normale » (750 kb/s, 100 ms) | « Fast 3G » (1,4 Mb/s, 562 ms) et processeur ralenti 4 fois |
|---|---|---|
| Accueil, 77 Ko | affichée en 1,1 s, chargée en 1,0 s | 1,6 s |
| Atelier 3D, 121 Ko avant la 3D | premier affichage en 1,1 à 1,3 s, chargée en 1,8 s | 1,7 s, chargée en 2,1 s |
| Atelier 3D, la 3D en plus | +800 Ko sans compression (environ 200 Ko compressés), arrivée vers 10 s | arrivée vers 8,5 s |

La page de l'atelier est utilisable à plat dès son affichage. La 3D arrive ensuite sans bloquer la page ; en production, la compression du serveur (docs/deploiement.md) divise son poids par 4.

## Vrais modèles 3D (recherche du 14/10/2026)

Aucun modèle réaliste de t-shirt, de polo ou de casquette n'a été trouvé libre de toute condition (CC0). Les meilleurs modèles gratuits sont sur Sketchfab, en licence **CC BY 4.0** ([texte de la licence](https://creativecommons.org/licenses/by/4.0/deed.fr)) : usage commercial permis, à condition d'afficher l'auteur, le titre et la licence. Sketchfab demande un compte gratuit pour télécharger : c'est à Yaya de le faire avec son compte (bouton « Download 3D Model », format **glTF binaire, .glb**), puis de transmettre les fichiers pour la préparation. Des copies trouvées ailleurs (GitHub) n'ont pas été reprises : leur auteur et leur licence ne sont pas sûrs. `public/modeles-3d/` est donc encore vide et la page garde ses modèles dessinés.

| Article | Modèles conseillés, le meilleur d'abord | Pourquoi |
|---|---|---|
| T-shirt | 1. [Basic T-Shirt](https://sketchfab.com/3d-models/basic-t-shirt-71bdf5940b5d41b8b46628a615e9b0ed), MadeByYeshe | Coupe classique, plis réalistes, maillage léger (7 200 triangles) ; textures à réduire (18 Mo téléchargés, 2 à 3 Mo une fois préparé). |
| | 2. [T Shirt](https://sketchfab.com/3d-models/t-shirt-c1a3e5eb9b5445f4b7d4be82f1127eba), funlab117 | Blanc, le plus proche de la maquette, mais très lourd (238 000 triangles, 6,8 Mo) : à alléger. |
| | 3. [Men Regular Apparel Fit Sporty T-Shirt](https://sketchfab.com/3d-models/men-regular-apparel-fit-sporty-t-shirt-4d055bb8c1e04549a4b2dac7b27ebb2c), BINARYCLOTH | Très propre, mais manches raglan et dessin « RUN » à retirer ; prendre la version 2K (4,4 Mo). |
| Polo | 1. [T-shirt Polo Lengan Pendek](https://sketchfab.com/3d-models/t-shirt-polo-lengan-pendek-74c33d16b31346f1a46a55c3ec8e8a78), Ikal.Ichwan | Col, patte à boutons, poche ; col et bords de manches d'une autre couleur (ils deviennent les `bords`) ; 54 000 triangles, 1,6 Mo. |
| | 2. [T-shirt](https://sketchfab.com/3d-models/t-shirt-7c8a4dede482474fa5a74f24c6fc5e81), Hyperrealitystudio | En fait un polo de rugby, réaliste ; rayures à retirer ; 54 000 triangles, 2,5 Mo. |
| | 3. [T-shirt Polo Lengan Pendek (Model 2)](https://sketchfab.com/3d-models/t-shirt-polo-lengan-pendek-model-2-0ca5cefbaecd4ece83e8b2fe9d011225), Ikal.Ichwan | Variante du premier ; 56 000 triangles, 1,7 Mo. |
| Casquette | 1. [Baseball Cap 01 Package](https://sketchfab.com/3d-models/baseball-cap-01-package-823e79f5e5ee41668abce9d8755c4926), dennismfrancisart | Six panneaux, œillets, visière, textures 2 048 px ; léger (14 700 triangles, 1,8 Mo). |
| | 2. [Baseball Cap](https://sketchfab.com/3d-models/baseball-cap-75d11d363e1c4884a714a776049ea4a0), jomalon | Blanche, panneaux et visière nets, sans texture ; 93 000 triangles, 3 Mo. |
| | 3. [Baseball Cap](https://sketchfab.com/3d-models/baseball-cap-1c1d34d73fd94e6b9e8f82b1eb7194a0), Scott VanArsdale | La plus réaliste (coutures, œillets, bride), mais 151 Mo : textures 8K à réduire. |

**Préparation, une fois les fichiers reçus** : un GLB de moins de 3 Mo (6 Mo au plus), textures de 2 048 px au plus, sans compression Draco ni meshopt (la page n'a pas leur décodeur). Le modèle est centré, debout, le devant vers +Z, à sa taille réelle en mètres (t-shirt 72 cm de haut, polo 74 cm, casquette 20 cm de large). Les pièces s'appellent `tissu` (prend la couleur choisie et reçoit le marquage ; couleur de base blanche, relief du tissu gardé), `visiere` et `bords` (col et bords de manches du polo). `public/modeles-3d/modeles.json` donne pour chaque article le devant, les points de marquage (`poitrine`, `dos`, et pour la casquette `cote` et `arriere`), la taille réelle et les crédits (titre, auteur, licence, liens) ; `public/modeles-3d/LICENCES.md` reprend une ligne par modèle (auteur, licence, source).

## Ce qui reste à faire

- **Vrais modèles 3D** : gratuits en licence CC BY 4.0 sur Sketchfab, à télécharger par Yaya avec son compte (choix et marche à suivre dans « Vrais modèles 3D » ci-dessus), puis à préparer. Le reste fonctionne déjà avec les modèles dessinés.
- Photos avant / après des vraies réalisations, à saisir dans l'admin.
- Questions ouvertes pour Yaya :
  - faut-il les paliers pour les revendeurs ? (éteint par défaut) ;
  - un demandeur voit le total dans « Nouvelle commande » mais pas dans l'atelier : faut-il aligner ?
  - les promotions restent indicatives dans l'atelier et sont appliquées par l'atelier à la confirmation : est-ce bien ce qui est voulu ?
