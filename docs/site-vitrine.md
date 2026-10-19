# Site vitrine (maquettes 01 à 12)

Jalon J6. Le site public de Succès Imprim, servi par la même application que l'espace client.

## Adresses

| Page | Maquette | Adresse |
|---|---|---|
| Accueil | 01 | « / » en ligne pour un visiteur ; /site sur l'ERP local (aperçu) |
| Produits | 02 | /nos-produits |
| Calculateur | 03 | /calculateur |
| Atelier 3D | prototype du 10/10/2026 | /atelier-3d (docs/atelier-3d.md) |
| Réalisations | 04 | /realisations |
| Nos équipements | 05 | /equipements |
| Préparer son fichier | 06 | /guide-fichiers |
| Qui sommes-nous ? | 07 | /a-propos |
| Nos engagements | 08, EC01 et EC02 | /nos-engagements |
| Questions fréquentes | 09 | /faq |
| Suivre ma commande | 10 | /suivre-ma-commande |
| Contact, demande de devis | 11, EC04 à EC06 | /contact, /demander-un-devis |
| Comment venir | EC07 | /comment-venir |
| Suivre une réclamation | EC03 | /suivre-ma-reclamation (`noindex`) |
| Conseils | 12 | /conseils |
| Nos actions sociales | demande du 16/10/2026 | /nos-actions-sociales (introuvable tant qu'aucune action n'est montrée, voir plus bas) |
| QR code « Nous soutenir » | demande du 17/10/2026 | /nous-soutenir/qr-code.svg (introuvable sans lien ou sans QR) |

Ces adresses sont publiques (`config/packages/security.yaml`). Une page pas encore construite n'apparaît ni dans le menu ni dans le pied de page : `site_lien()` renvoie null tant que sa route n'existe pas.

Sur « / », un membre du personnel connecté voit toujours le tableau de bord. Un visiteur voit l'accueil du site en ligne et la page de connexion sur l'ERP local.

## Gabarit

- `templates/site/_site.html.twig` : en-tête (menu, « Espace client », « Demander un devis »), pied de page, bouton WhatsApp flottant. Une page définit `title`, `description`, `contenu` et la variable `menu_actif`.
- `templates/site/_bandeau.html.twig` : bandeau bleu des pages intérieures (fil d'Ariane, titre, accroche).
- `public/css/site.css` : une seule feuille, sans police ni bibliothèque externe, écrite dans la page par `site_styles()` (docs/referencement.md).
- `App\Twig\SiteExtension` : menu, pied de page, entreprise affichée (code `mdg_success`), lien WhatsApp.
- `App\Twig\ReferencementExtension` : titre, description, adresse canonique, balises de partage, données structurées, codes de vérification, réseaux sociaux (docs/referencement.md).
- Menu à sept entrées (avec « Atelier 3D ») : sur une ligne au-dessus de 1 280 px ; entre 1 141 et 1 280 px, il se resserre et « Accueil » laisse la place au logo (qui y mène) ; en dessous, le menu à trois traits.
- Animations (`public/js/site-animations.js`, docs/atelier-3d.md) : apparition au défilement (`data-revele`), inclinaison des cartes produit (`data-incline`), comparaison avant / après des réalisations. Coupées avec « réduire les animations ».

## Entreprise affichée

Le pied de page affiche « Succès Imprim est un nom commercial de Madial Group SARL », le RCCM, le NIF, l'adresse et le téléphone de l'entreprise `mdg_success` (Réglages › Entreprises). Ces coordonnées publiques descendent au site par la synchronisation ; la banque, la numérotation, les logos et la signature restent au local.

## Rapidité (objectif : lisible en moins de 2 s en 3G)

- Logo en WebP (21 Ko au lieu de 107 Ko). Photos en WebP à plusieurs largeurs, chargées après le texte (voir « Photos du site »).
- Feuilles de style écrites dans la page (`site_styles()`, docs/referencement.md) : le texte s'affiche dès l'arrivée du HTML, sans attendre un aller-retour de plus. HTML compressé de 8 à 12 Ko par page (17 Ko pour le studio textile).
- `public/.htaccess` : texte compressé (y compris les scripts, servis en `text/javascript` par un Apache récent), CSS et JS gardés 7 jours, images, polices et modèles 3D 30 jours.
- Pages sans formulaire : `Cache-Control: public, max-age=300`. « / » : `no-cache` avec ETag (réponse 304 si rien n'a changé), car la même adresse sert le tableau de bord après connexion.
- Une page qui démarre la session (jeton CSRF d'un formulaire) reste privée : `SiteController::cache()` ne la rend pas publique.

## Référencement

Règles complètes pour une nouvelle page : **docs/referencement.md**.

- Adresse du site : champ « Site » de l'entité Succès Imprim (Réglages › Entités et en-têtes). Adresses canoniques, plan du site, robots.txt et images de partage l'utilisent, jamais l'adresse de l'ERP local.
- /sitemap.xml (serveur en ligne seulement) : l'accueil, les pages du menu et du pied de page qui existent, la demande de devis, les pages de contenu publiées, les conseils, « Nos actions sociales » quand une action est montrée (sans ses photos), avec la date de dernière modification quand elle est connue et les photos (réalisations, conseils). Une page à contenu variable s'y ajoute en écrivant un service qui implémente `App\Service\Site\SourcePlanSite`.
- /robots.txt : en ligne, tout est permis sauf l'espace client, la connexion, l'API, l'administration et les liens privés ; sur l'ERP local, tout est interdit. En-tête `X-Robots-Tag: noindex` sur tout l'ERP local et, en ligne, sur tout ce qui n'est pas une page publique du site (`ReferencementSubscriber`).
- Chaque page a son titre (60 caractères au plus), sa description, un seul `<h1>`, son adresse canonique sans paramètres et ses balises de partage (Open Graph, Twitter) avec une image 1 200 × 630. Données structurées : fiche de l'imprimerie (accueil, contact, à propos), fil d'Ariane, produits avec leur prix de départ, questions fréquentes, conseils.
- Réglages › Site vitrine › Référencement et réseaux : codes de vérification Google Search Console et Bing, fiche Google, réseaux sociaux (Facebook, Instagram, TikTok, WhatsApp, LinkedIn, YouTube, X : icônes dans le pied de page, sur la page Contact et dans l'espace client, docs/referencement.md), et la liste de ce qui manque encore pour Google.
- Page introuvable : statut 404, en français, avec les liens utiles.
- Mesure du 13/10/2026, accueil en ligne sur un téléphone en « 3G normale » (750 kb/s, 100 ms), sans cache : 30 Ko, premier affichage en 0,5 s, page chargée en 0,6 s.
- Mesure du 14/10/2026 en production (APP_ENV=prod), téléphone en « Fast 3G » des outils Chrome (1,4 Mb/s, 562 ms) et processeur ralenti 4 fois, sans cache : accueil 69 Ko, affiché en 1,4 s ; réalisations 79 Ko, 1,6 s ; produits et calculateur 65 à 67 Ko, 1,4 s. En revenant sur une page : 0,8 à 1,4 s.
- Mesure du 14/10/2026 au soir, après l'Atelier 3D et les animations (même méthode) : accueil 77 Ko, affiché en 1,6 s (1,1 s en « 3G normale ») ; atelier 121 Ko, affiché en 1,7 s (1,1 à 1,3 s en « 3G normale »), la 3D arrivant ensuite sans bloquer la page. Détails dans docs/atelier-3d.md.
- Mesure du 15/10/2026 (production derrière Apache comme chez LWS, téléphone 390 px, processeur ralenti 4 fois, sans cache, médiane de 3 passages) : en « Fast 3G », pages affichées en 0,7 à 0,8 s au lieu de 1,4 s, 32 à 39 Ko au lieu de 54 à 60 Ko ; studio textile 0,8 s au lieu de 1,5 s, 121 Ko au lieu de 245 Ko. En « 3G lente » des outils Chrome (2 s d'attente par requête), 2,2 s au lieu de 4,5 s : le minimum possible, la page n'ayant plus qu'une requête avant d'être lisible. Détails : /mnt/project-files/referencement/audit-2026-10-15.md.

## Ce que le site n'affiche jamais

Montants d'un client, noms de clients sans accord, coûts, marges, prix B2B. Pas de note Google inventée : la bande d'avis n'apparaîtra qu'avec le lien de la vraie fiche.

## Photos du site

Dix photos envoyées par Yaya le 15/10/2026 « pour le site » (originaux dans /mnt/project-files/uploads/hearth/, décrits dans /mnt/project-files/.notes/inputs.md). Ce sont des **visuels de présentation générés par intelligence artificielle pour la marque**, pas des photos de travaux livrés ni de clients : elles n'entrent ni dans Réalisations ni parmi les partenaires, et aucun texte ne les présente comme un travail fait pour un client. S'y ajoutent trois **vraies photos du local** (17/10/2026), décrites plus bas.

### Où elles sont

| Photo (nom du fichier) | Page et endroit |
|---|---|
| Façade et enseigne (`enseigne-succes-imprim-bamako`) | Accueil, en haut à côté de « Nous imprimons vos succès ! » ; image de partage (Facebook, WhatsApp). Sur « Qui sommes-nous ? », seulement si la vraie façade manque (voir « Vraies photos du local ») |
| Bannière aux couleurs, sans logo (`banniere-grand-format-couleurs`) | Accueil, carte « Grand format » |
| Dépliants et BAT sur le téléphone, recadrés (`depliants-imprimes-succes-imprim`) | Accueil, carte « Impression numérique » (petit format) |
| T-shirts DTF (`t-shirts-dtf-succes-imprim`) | Accueil, carte « Textile » (si aucune carte ne la montre : bande Studio textile) ; « Nos équipements », carte textile |
| Porte et vitrine en vinyle (`vitrophanie-porte-vitrine`) | Accueil, carte « Signalétique » ; « Nos équipements », carte « Roll-up, vitrines et adhésifs » |
| Rubans d'encre (`rubans-encre-couleurs`) | Accueil, fond de la bande « Un projet à imprimer ? » (décoratif ; le texte est sur un cadre blanc, lisible quel que soit le recadrage) |
| Atelier, opérateur à l'imprimante (`atelier-impression-grand-format`) | « Qui sommes-nous ? », à côté du texte, tant qu'aucune image n'est choisie pour cette page |
| Bannière au logo sur l'imprimante (`banniere-vinyle-succes-imprim`) | « Nos équipements », carte grand format |
| BAT validé sur le téléphone (`bat-valide-sur-telephone`) | « Nos engagements », avant la frise « Comment se passe votre commande » |
| Livreur à moto (`livraison-moto-bamako`) | « Nos engagements », à côté de la précédente |
| Fourgon habillé (`fourgon-publicitaire-succes-imprim`) | « Nos produits », encadré « Un autre support, une autre taille ? » |

Une photo ne revient jamais deux fois dans une page. Les cartes produit ont leur propre photo depuis le 18/10/2026 (« Photos des produits » plus bas) ; les réalisations sans photo, le guide, la FAQ et les conseils gardent leurs dessins.

Choix automatiques (`App\Service\Site\PhotosSite`) :
- **Cartes de l'accueil** : d'après la famille de l'univers (`PhotosSite::FAMILLES` : grand format, petit format, textile, signalétique). Un univers sans photo libre garde son icône.
- **Cartes de « Nos équipements »** : d'après les mots du titre de la carte, puis de son étiquette et de son texte (`PhotosSite::MOTS` : « textile », « DTF » → t-shirts ; « vitrine », « adhésif », « roll-up » → vitrine ; « grand format », « solvant », « bâche » → imprimantes). Exemple : « Imprimante solvant 3,20 m », « Imprimante solvant 1,80 m » et « Imprimante DTF » prennent les deux imprimantes puis les t-shirts. Une machine sans mot reconnu garde son dessin.

### Fichiers

- `public/images/site/photos/<nom>-<largeur>.webp` : WebP sans aucune métadonnée (ni EXIF ni profil), en 320 (vignettes de l'accueil sur téléphone), 480, 800, 1 200 ou 1 600 px de large selon l'usage (liste dans `PhotosSite::PHOTOS`), 200 Ko au plus chacun. Image de partage : `public/images/site/partage-enseigne-succes-imprim.jpg`, la façade recadrée en 1 200 × 630 (148 Ko, JPEG pour WhatsApp et Facebook).
- Dans la page, `templates/site/_photo.html.twig` : `srcset` et `sizes` (le navigateur prend la plus petite largeur qui suffit), largeur et hauteur écrites (rien ne bouge à l'arrivée), texte alternatif qui décrit la scène (vide pour les rubans, décoratifs). Chargement différé, sauf la photo du haut de l'accueil (prioritaire, `fetchpriority="high"`) et la première photo d'une page intérieure. Sur un téléphone, la photo du haut de l'accueil ne dépasse pas 800 px (57 Ko ; 27 Ko en 480 px).
- Une photo dont le fichier manque n'est pas affichée : la page garde son dessin ou son icône. Sans la façade, l'image de partage revient au logo sur fond blanc (`partage-succes-imprim.jpg`).
- `tests/Unit/Service/Site/PhotosSiteTest.php` vérifie les largeurs, les proportions, le poids, l'absence de métadonnée et le choix sans doublon.

### Remplacer une photo

- **Le plus simple : une image choisie dans Réglages › Site vitrine** (champ image de la page). Elle passe toujours avant la photo livrée et devient l'image de partage de la page. Aujourd'hui, seule « Qui sommes-nous ? » affiche l'image de la page à côté du texte ; « Nos équipements » la montre en tête.
- **Un autre fichier, sous le même nom** : refaire chaque largeur de la liste, aux mêmes proportions, sans métadonnée, par exemple avec `cwebp` (libwebp) : `cwebp -q 64 -resize 800 0 -metadata none nouvelle.png -o enseigne-succes-imprim-bamako-800.webp`, et de même pour les autres largeurs (qualité 70 en 320 px, 68 en 480, 64 en 800, 60 en 1 200, 58 en 1 600). Lancer ensuite le test `PhotosSiteTest`. Les navigateurs gardent une image 30 jours (`public/.htaccess`) : pour un changement immédiat, prendre un nouveau nom et le changer dans `PhotosSite`.
- **Changer d'endroit** : les noms sont dans `SiteController` (accueil : `PHOTO_ACCROCHE`, `PHOTO_PROJET`, `PHOTO_ATELIER`), dans `PhotosSite::FAMILLES` et `MOTS`, dans `PhotosSite::FACADE`, `ALLEE` et `LOCAL` (vraies photos du local), et dans les gabarits `a_propos`, `engagements` et `produits`.
- **Si la vraie imprimerie (accueil ou atelier) ne ressemble pas aux visuels**, remplacer d'abord la façade (accueil, « Qui sommes-nous ? », partage) et l'atelier par de vraies photos. Depuis le 18/10/2026, la vraie façade est sur l'accueil (« Venez nous voir »), sur « Qui sommes-nous ? » et dans la fiche pour Google ; le visuel de l'enseigne reste en haut de l'accueil et en image de partage, au choix de Yaya.

### Vraies photos du local (17/10/2026)

Photos du local envoyées par Yaya le 17/10/2026 (appareil DJI, 4 000 × 2 256), choisies avec lui le 18/10 : la façade au balcon rond, l'allée pavée qui mène à l'entrée, le jardin et l'entrée. Les trois autres photos (reflet du soleil) et la vidéo (1 s) ne sont pas utilisées. Les originaux ne sont ni dans le dépôt ni en ligne.

| Photo (nom du fichier) | Page et endroit |
|---|---|
| Façade au balcon rond (`facade-local-succes-imprim-bamako`, `PhotosSite::FACADE`) | Accueil, bandeau « Venez nous voir » sous l'accroche ; « Qui sommes-nous ? », à côté du mot du gérant (sinon bloc « Venez nous voir ») ; Contact, galerie « Notre local » ; fiche de l'imprimerie pour Google |
| Allée pavée vers l'entrée (`allee-entree-succes-imprim`, `PhotosSite::ALLEE`) | Contact, en grand au-dessus de la carte ; galerie « Notre local » |
| Jardin et entrée (`jardin-entree-succes-imprim`) | Contact, galerie « Notre local » seulement |

**Préparation** (une fois, hors du dépôt) : chaque personne floutée (tête très floue sur une zone large, corps flou moyen : personne n'est reconnaissable, même à la taille réelle), comme les plaques et l'arrière des motos ; ombres éclaircies doucement ; recadrage 16:9 (la façade sans le guidon de moto du premier plan) ; seuls les pixels sont gardés, **sans EXIF ni position GPS** (les originaux DJI en ont) ; WebP simple en 320, 480, 800 et 1 200 px (11 à 127 Ko). Sur la façade, le visage du modèle de l'affiche en vitrine est aussi flouté. Pour refaire une photo : flouter d'abord, puis mêmes noms, mêmes largeurs, mêmes proportions, et lancer `PhotosSiteTest`.

**Accueil, « Venez nous voir »** (section `si-visite` de `templates/site/accueil.html.twig`, maquette SW02) : la ville, le repère saisi (Réglages › « Site : contact et accueil »), les horaires du réglage Horaires avec « Ouvert maintenant… » ou « Fermé, ouvre… », le téléphone, et les boutons « Itinéraire », « Appeler » et « WhatsApp ». Avec une position réglée, le plan ou la carte occupe la droite et la façade est posée en vignette dans un coin ; sans position, la façade seule. L'itinéraire suit le réglage de la position (« Carte de l'imprimerie ») ; s'il est vide, une recherche Google Maps sur le nom et l'adresse (`ContactSite::itineraire`). Données : `site_visite()` (`App\Twig\SiteVisiteExtension`) ; photo en chargement différé.

**Contact** : l'allée en grand au-dessus de la carte, avec sa légende (première photo de la page : chargée tout de suite, sans chargement différé) ; sous le formulaire et les coordonnées, la galerie « Notre local » (`templates/site/_notre_local.html.twig`, `site_photos_local()`) : trois vignettes légendées ; chacune ouvre la photo en grand dans une fenêtre `<dialog>` comme la visionneuse des réalisations (Échap, clic à côté ou « × » la ferment ; sans JavaScript, le lien `#local-…` l'affiche quand même). Les grandes photos ne se chargent qu'à l'ouverture. Aucune bibliothèque ajoutée.

**Qui sommes-nous ?** : quand « Le mot du gérant » s'affiche, la vraie façade est à côté (légende « Notre imprimerie à Bamako » ; côte à côte dès 1 100 px, l'une sous l'autre en dessous) et le bloc « Venez nous voir » prend la photo de l'allée. Sans mot du gérant, le bloc « Venez nous voir » montre la façade. Le visuel de l'enseigne ne revient que si les fichiers manquent.

**Google** : la fiche de l'imprimerie (LocalBusiness, accueil, contact, à propos) donne la vraie façade en 16:9, 4:3 et 1:1 (`ReferencementExtension::IMAGES_FICHE`, recadrages 4:3 et 1:1 dans `public/images/site/fiche/`), Google choisissant la proportion qui lui convient ; l'image de partage reste le visuel de l'enseigne. Le plan du site (/sitemap.xml) cite la façade sur l'accueil et les trois photos sur Contact (`PlanPhotosLocal`). Ces photos sont aussi à mettre sur la fiche Google de l'imprimerie (Google Business Profile), où elles comptent le plus.

**Poids** (mesure du 18/10/2026, téléphone 390 px, page parcourue jusqu'en bas) : la façade ajoute 24 Ko à l'accueil (écran ×1 ; 54 Ko en ×2), chargée après le texte et la photo du haut ; sur Contact, l'allée pèse 29 Ko (69 Ko en ×2) et les trois vignettes 41 Ko en tout. Les grandes photos de la visionneuse (800 ou 1 200 px) ne viennent qu'à l'ouverture.

### Poids (mesure du 15/10/2026)

Site en ligne en production (APP_ENV=prod, HTML compressé), téléphone 390 px, sans cache, somme des réponses (Playwright) :

| Page | Écran normal | Écran ×2 |
|---|---|---|
| Accueil | 172 Ko (photos 113 Ko) | 201 Ko (photos 143 Ko) |
| Qui sommes-nous ? | 79 Ko | 125 Ko |
| Nos équipements | 119 Ko | 210 Ko |
| Nos engagements | 94 Ko | 156 Ko |
| Nos produits | 52 Ko | 95 Ko |

Accueil avant les photos : environ 60 Ko. Ces chiffres comptent la feuille `site-contenu.css` non compressée par le serveur d'essai (24 Ko, environ 5 Ko compressée chez LWS). Le texte s'affiche toujours au premier aller-retour : premier affichage de l'accueil en 0,8 s en « Fast 3G » et en 2,2 s en « 3G lente » (le minimum, 2 s d'attente par requête, comme avant les photos) ; la photo du haut arrive ensuite (2,0 s en Fast 3G, 7,2 s en 3G lente) sans retarder la lecture.

### Photos des produits (envoyées le 16/10/2026)

Douze visuels envoyés par Yaya le 16/10/2026 (« images des produits sur le site à remplacer », décrits dans /mnt/project-files/.notes/inputs.md). Ce sont des **maquettes de présentation sur fond blanc**, aux logos Succès Imprim ou Madial Group (« MDG », la société de Yaya), pas des travaux de clients. Les PNG envoyés ne sont pas dans le dépôt : seules les versions WebP le sont.

| Photo (`public/images/site/produits/`) | Produits qui la prennent (mots du nom) | Produit de la démonstration |
|---|---|---|
| `vitrine-micro-perforee` | micro-perforé, vitrophanie | Micro-perforé vitrine |
| `kakemono` | kakémono | Kakémono 80 × 180 cm |
| `roll-up` | roll-up | Roll-up 85 × 200 cm |
| `panneau-akylux` | Akylux, panneau, Forex, Dibond | Panneau Akylux |
| `casquette` | casquette | Casquette personnalisée |
| `polo` | polo | Polo personnalisé |
| `t-shirt` | t-shirt, tee-shirt, DTF | T-shirt personnalisé DTF |
| `brochure` | brochure, catalogue, livret, magazine, dépliant | Brochure A4 8 pages |
| `affiches` | affiche, poster | Affiches A3 |
| `cartes-de-visite` | carte(s) de visite | Cartes de visite |
| `flyers` | flyer, prospectus, tract | Flyers A5 |
| `vinyle-adhesif` | vinyle, adhésif, autocollant, sticker | Vinyle adhésif |

- **Choix** : `App\Service\Site\PhotosProduits` lit le nom du produit (mot pris en début de mot) et prend la première photo de la liste dont un mot y est : la vitrine micro-perforée passe avant le rouleau de vinyle, le kakémono avant le roll-up. Un produit sans mot reconnu garde son dessin : dans la démonstration, **« Bâche imprimée » n'a pas de photo** (ni X-banner, Door Shape ou banderole s'ils sont créés). Un nouveau produit prend la photo dès que son nom contient un des mots.
- **Où** : cartes de « Nos produits » (photo à la place du dessin, sur fond blanc ; les dessins ne sont écrits dans la page que si un produit montré n'a pas de photo) et vignettes de « Mes produits » dans l'espace client quand le produit n'a pas encore de BAT à montrer. Les cartes des univers de l'accueil gardent leurs photos de scène (« Où elles sont » plus haut).
- **Fichiers** : carrés, en 240, 360 et 480 px, WebP simple sans aucune métadonnée, 2 à 32 Ko chacun. Dans la page : `srcset` et `sizes` (`site/_photo.html.twig`), largeur et hauteur écrites, chargement différé sauf les quatre premières cartes. Texte alternatif en français qui décrit la maquette (`PhotosProduits::PHOTOS`).
- **Remplacer** : refaire les trois largeurs sous le même nom (par exemple `cwebp -q 80 -resize 480 480 -metadata none nouvelle.png -o flyers-480.webp`, de même en 360 et 240), puis lancer `PhotosProduitsTest` (largeurs, carré, poids, aucune métadonnée).

## Produits et calculateur (maquettes 02 et 03)

Code : `SiteProduitsController`, `App\Service\Site\CatalogueSite` et `App\Service\Site\Calculateur`, gabarits `site/produits.html.twig` et `site/calculateur.html.twig`, feuille `public/css/site-produits.css`.

**Nos produits** (`app_site_produits`, /nos-produits)
- Catégories publiées, avec leurs produits publiés et actifs. Une catégorie et sa copie de démonstration « (démo) » ne font qu'un filtre, comme sur l'accueil ; `?categorie=<clé>` (ex. `?categorie=grand-format`, voir « Adresses des produits ») filtre sans JavaScript.
- Prix de départ : la configuration la moins chère au prix normal, avec son unité (le m², l'unité...). Un produit sans configuration chiffrable affiche « Prix sur devis ».
- Chaque carte montre le nom commercial du produit d'abord, sa désignation à côté en plus petit (docs/noms-commerciaux.md) ; sans nom commercial, la désignation seule.
- Recherche « Chercher un produit » (`?q=`, 80 caractères, sans JavaScript) : les produits dont le nom commercial, la désignation ou l'univers contient le texte, sans tenir compte des accents, des majuscules ni du pluriel (« autocollants » trouve « Vinyle adhésif »). Le nombre de résultats s'affiche, avec un lien vers tous les produits.
- Photo du produit d'après son nom commercial ou sa désignation (« Photos des produits » plus haut) ; sans photo, un dessin SVG choisi d'après le nom du produit (`CatalogueSite::ILLUSTRATIONS`), sinon d'après sa famille. En bas de page, l'encadré « Un autre support, une autre taille ? » porte la photo du fourgon habillé (« Photos du site »).

**Calculateur** (`app_site_calculateur`, /calculateur)
- Paramètres : `produit` (jeton de la configuration), `categorie` (clé de l'univers), `largeur` et `hauteur` en cm, `quantite`, `finitions[]` (jetons des finitions). Voir « Adresses des produits ».
- Mêmes produits que la commande en ligne (`NouvelleCommande::produits`), même calcul (`NouvelleCommande::estimer(..., b2b: false)`).
- Le formulaire part en GET et la page revient avec le prix : il marche sans JavaScript. Le script de la page demande le prix à /calculateur/estimation (`app_site_calculateur_estimation`, JSON) à chaque changement ; il ne calcule rien lui-même.
- Bornes : côtés de 0,01 à 5 000 cm (au-delà, « demandez un devis »), quantité jusqu'à 100 000, 30 finitions au plus. Une taille ou une quantité illisible est refusée avec un message, sans lien « Commander ».
- Le JSON donne le total, le détail (impression, finitions), les lignes affichées et les liens. Jamais le prix B2B, un coût ni une marge.
- « Commander » ouvre /espace/commandes/nouvelle avec les mêmes paramètres : connexion d'abord si besoin, puis la commande pré-remplie. « Demander un devis » mène à `app_site_devis` avec les mêmes paramètres ; le bouton apparaît quand cette page existe. « Recevoir le devis sur WhatsApp » envoie le choix et l'estimation en premier message.
- Délai : aucun délai de fabrication n'est saisi par produit. Le calculateur affiche « Annoncé à la validation du BAT ».
- Sur téléphone, le total reste en bas de l'écran pendant la saisie.

Ces pages et le JSON n'ouvrent pas de session : `Cache-Control: public, max-age=300`.

### Adresses des produits (demande du 18/10/2026)

Aucune adresse publique du site ne montre un identifiant de la base (« faut crypter id (url) dans produit ») :

| Lien | Avant | Maintenant |
|---|---|---|
| Filtre de Nos produits | /nos-produits?categorie=900003 | /nos-produits?categorie=textile |
| Carte d'un univers (accueil) | /calculateur?categorie=900001 | /calculateur?categorie=grand-format |
| « Calculer mon prix » | /calculateur?produit=900011 | /calculateur?produit=7qJnP1C3LvE |
| Calculateur rempli | ?produit=900011&precedent=900011&finitions[]=900021 | ?produit=…&precedent=…&finitions[]=… (jetons) |
| « Demander un devis » | /demander-un-devis?produit=900011&quantite=20 | /demander-un-devis?produit=7qJnP1C3LvE&quantite=20 |
| « Commander » (vers l'espace client) | /espace/commandes/nouvelle?produit=900011 | /espace/commandes/nouvelle?produit=7qJnP1C3LvE |

(Les jetons ci-dessus sont des exemples : ils dépendent de `APP_SECRET`.)

- **Univers** : sa clé est son nom affiché, en minuscules, sans accents (`CatalogueSite::cle`). Lisible et bon pour le référencement.
- **Produit (configuration) et finitions** : un jeton de 11 caractères (`App\Service\Site\JetonsSite`). Il est calculé, sans colonne en base : 4 octets de sceau (HMAC de l'identifiant) et l'identifiant masqué, avec une clé tirée de `APP_SECRET`. Toujours le même jeton pour le même produit ; impossible d'en deviner un autre ; un jeton modifié ou inventé est refusé (produit inconnu : 422 pour le JSON, « Choisissez un produit de la liste » pour le devis, premier produit pour le calculateur). Les jetons d'un produit n'ouvrent pas une finition, et inversement.
- **Dans les gabarits** : `site_lien()` met tout seul en jetons `produit`, `precedent` et `finitions` des pages du site (`JetonsSite::PARAMETRES_PRODUIT`) ; `site_jeton('configuration', id)` ou `site_jeton('finition', id)` pour la valeur d'un champ. Les listes du calculateur et du devis, les cases des finitions et les fiches lues par leurs scripts portent des jetons. Le JSON de l'estimation donne `produit` en jeton.
- **À l'entrée** : `SiteProduitsController` et `SiteDemandeController` décodent les jetons (`JetonsSite::decoderParametres`) avant la lecture habituelle. Le formulaire de devis envoyé (POST) accepte encore un identifiant en chiffres ; l'espace client (/espace/commandes/nouvelle) accepte le jeton du bouton « Commander » et ses propres liens en identifiants, inchangés.
- **Anciennes adresses** : un lien en chiffres (`?produit=900011`, `?categorie=900003`, `finitions[]=900021`) est redirigé en **301** vers la même adresse avec jetons et clé : les liens déjà partagés et les moteurs suivent. Le JSON de l'estimation, lui, n'accepte que les jetons.
- **Référencement** : l'adresse canonique reste le chemin sans paramètres (/nos-produits, /calculateur), le plan du site ne liste que ces chemins et les données structurées (catalogue) ne portent aucune adresse de produit : rien à changer de ce côté.
- **Attention** : changer `APP_SECRET` change tous les jetons. Les anciens liens à jetons ouvrent alors la page sans produit choisi (pas d'erreur).
- **Contrôle** : `app:recette:acces` en ligne vérifie qu'aucun lien ni champ de Nos produits, du calculateur et du devis ne porte un identifiant, que l'ancienne adresse est redirigée en 301 et qu'un jeton inventé n'ouvre rien.

## Suivi, contact et demande de devis (maquettes 10 et 11)

Code : `SiteSuiviController`, `SiteDemandeController`, `App\Service\Site\{SuiviSite, ContactSite, FormulaireDemande, DemandesSite, PreparationDemande}`, `App\Service\EspaceClient\SuiviSansCompte`, entité `DemandeDevis` (migration `Version20261013225222`), gabarits `site/suivi`, `site/contact`, `site/devis`, `site/demande_envoyee`, feuille `public/css/site-formulaires.css`. Au local : `DemandesSiteController` (Ventes › Demandes du site).

**Suivre ma commande** (`app_site_suivi`, /suivre-ma-commande)
- Numéro de commande (complet, ou ses chiffres : « 0765 ») et téléphone de la commande. Même recherche que /espace/suivre (`SuiviSansCompte`) : jeton CSRF, 20 recherches par heure et par adresse IP, et **la même réponse** si la commande n'existe pas ou n'est pas à ce téléphone.
- Résultat : désignation, pastille (« En impression · prête vendredi », « BAT à valider »...) et les 6 étapes avec leur heure. Jamais de montant, de nom de client, de note ni de nom d'agent. Le détail, les factures et les BAT à valider renvoient à l'espace client.
- `noindex` (en-tête `X-Robots-Tag` et balise), `Cache-Control: private, no-store`, absent du plan du site.

**Contact** (`app_site_contact`, /contact) : voir « Contact, rappel, engagements et Comment venir » plus bas.

**Demander un devis** (`app_site_devis`, /demander-un-devis), sans compte
- Produit parmi le catalogue publié (mêmes produits que le calculateur, groupés par catégorie, sans prix), taille en cm si le produit en demande une, quantité, finitions proposées pour ce produit, message, coordonnées, fichier facultatif.
- Pré-remplissage depuis le calculateur : `produit` (identifiant de configuration), `largeur` et `hauteur` (cm), `quantite`, `finitions[]` (ou une liste séparée par des virgules). Une valeur inconnue ou hors bornes est ignorée, jamais affichée telle quelle.
- Quantité en pièces ou en m² (pièces au m², [docs/saisie-devis-commandes.md](saisie-devis-commandes.md)) : pour un produit au m² dont le visiteur donne la taille, « Quantité en pièces / m² ». La page dit en direct combien de pièces tiennent sur 1 m² avec l'espacement du réglage (« 1 000 pièces = 2,78 m² · 361 pièces au m² (19 × 19, espacement 1 mm) ») ; en m², le visiteur tape la surface et le nombre de pièces se remplit tout seul. Pièce plus grande que 1 m : quantité en pièces seulement. Aucun prix. Le serveur recompte les pièces depuis la surface (le nombre envoyé par la page est ignoré) et refuse une surface hors de 0,01 à 10 000 m², moins d'une pièce, plus de 100 000 pièces ou moins que le minimum du produit. La demande garde la surface tapée (`quantiteM2`, « (3,00 m² demandés) » dans son résumé).
- Fichier : PDF, AI, EPS, PSD, JPG, PNG ou TIFF, 20 Mo au plus (moins si le serveur n'accepte pas plus ; le formulaire affiche la limite réelle). Le contenu est vérifié (un faux PNG est refusé), puis le fichier est rangé hors de `public/` dans `var/uploads/commandes` (droits 0640), avec son empreinte SHA-256 et le contrôle automatique, comme « Mes fichiers ». Au-delà, le visiteur est invité à l'envoyer par WhatsApp.
- Confirmation (/demander-un-devis/envoyee) : numéro de la demande (W-DD-0005), résumé et prochaines étapes, **jamais de prix**. Montrée une seule fois, `noindex`. Celle du contact (/contact/envoye) est décrite plus bas.

**Anti-spam** (contact et devis) : jeton CSRF ; champ piège `site_web` caché (rempli : fausse confirmation sans numéro, rien d'enregistré) ; jeton signé de l'heure d'affichage (envoi refusé avant 4 s, page expirée après 12 h) ; 6 demandes valides par heure et par adresse IP (`site_demande_ip`) ; tout est revérifié côté serveur. Une erreur renvoie la page en 422 avec la saisie gardée.

**Accusé de réception** : un SMS sans montant (« nous avons bien reçu votre demande de devis W-DD-0005 ») part seulement si le service SMS est réglé, au plus 3 fois par jour vers un même numéro (`site_accuse_telephone`), jamais vers le numéro d'une fiche de démonstration ni d'un client qui a coupé les SMS. Si le site n'a pas pu l'envoyer, le local l'envoie à la réception, dans les 6 heures. Pas d'accusé WhatsApp pour l'instant (il faudrait un modèle de message approuvé).

**Au local** : la demande monte par la synchronisation (type `demande_devis`, sens ascendant). Le site ne la montre plus : aucune liste des demandes en ligne. À la réception, `DemandesSite::preparer` :
- demande de devis d'un numéro connu : rattachée à ce client, devis brouillon à son nom ;
- d'un numéro inconnu : un prospect source « Site » (montant estimé, relance le jour même) et une fiche client minimale (origine « site », à compléter) car un devis exige un client ;
- devis brouillon au tarif public (même calcul que le calculateur), avec la demande du visiteur en observation ; un produit hors bornes reste « à chiffrer » ; produit au m² dont le visiteur donne la taille : la ligne est en pièces au m² avec l'espacement du réglage du local, que la quantité soit en pièces ou en m² (réponse de Yaya du 18/10 ; une pièce plus grande que 1 m reste au calcul habituel) ;
- demande venue de l'Atelier 3D : même devis brouillon, au prix public de l'atelier (seconde face, palier de quantité) avec la désignation de la création ; ses vues et images sont dans Ventes › Créations Studio textile (docs/atelier-3d.md) ;
- un message est rattaché au client connu ; un message « demande de devis » d'un inconnu devient un prospect ;
- les personnes qui ont le droit Devis (et les administrateurs) sont prévenues.

Rien n'est confirmé ni envoyé au client sans geste de l'équipe : le devis brouillon n'est pas visible dans l'espace client. Badge « Site » dans Devis et commandes, Ventes et Prospects ; la fiche du devis rappelle la demande d'origine. Ventes › Demandes du site (droit Devis) liste les demandes à traiter et traitées ; la fiche montre le détail, le fichier à télécharger (téléchargement noté au journal d'activité) et « Marquer traitée ».

**Limites connues**
- Les réglages (horaires, lien du plan) ne descendent pas au site : en ligne, les horaires par défaut s'affichent et le plan reste caché tant qu'une synchronisation des réglages n'existe pas.
- Les demandes restent dans la base en ligne après leur montée (pas de purge automatique).

## Contact, rappel, engagements et Comment venir (maquettes EC01 à EC13)

Textes du gérant (dossier `textes-site`) et maquettes `engagements-contact` du 19/10/2026. Règle commune : **aucun chiffre inventé**. Un chiffre n'est montré que s'il est mesuré dans l'ERP et, pour les engagements, validé par la direction.

Code : `SiteDemandeController` (contact, rappel, confirmation), `SiteReclamationController` (signaler, suivre une réclamation), `SitePagesController::engagements` et `venir`, `App\Service\Site\{ContactSite, RappelsSite, EngagementsSite, TableauEngagements, AtelierDirect}`, `ReclamationsClients::creerDepuisSite` et `duTelephone`. Gabarits : `site/contact`, `site/contact_envoye`, `site/engagements`, `site/engagement_signale`, `site/reclamation_suivi`, `site/comment_venir`. Feuilles : `site-contact.css`, `site-engagements.css`, `site-venir.css`. Migration `Version20261019150000` (sur `demande_devis` : n° de commande cité, heure du rappel, conversation ouverte, rappel fait, alerte ; sur `conversation_client` : origine).

**Contact ouvert** (EC04) : titre « Parlons de votre projet. » et pastille « Ouvert maintenant, jusqu'à 18 h » (ou « Fermé, ouvre… »). Viennent ensuite trois canaux : WhatsApp, Appeler, Venir à l'atelier (« Voir l'entrée en photos » mène à Comment venir). Le formulaire « Écrivez-nous » propose les motifs en pastilles (Un devis, Suivre une commande, Conseil fichier, Réclamation, Autre ; `?objet=commande` en présélectionne un). Le champ « N° de commande » ne s'affiche que pour une commande ou une réclamation. Le fichier est facultatif (mêmes règles que le devis) et chaque champ obligatoire porte un astérisque. À droite : l'allée en grand, le plan ou la carte avec « Itinéraire », les horaires de la semaine (jour courant en bleu, jour férié dit fermé, `ContactSite::semaine`) et les coordonnées (RCCM et NIF de l'entreprise, « Lire nos avis Google »). En bas, la galerie « Notre local ». Le délai sous « Écrivez-nous » est le texte « Délai de réponse affiché » de la tuile « Site : contact et accueil ».

**Atelier fermé : « Demander un rappel »** (EC05, tuile « Site : engagements et rappels », **éteint par défaut**)
- L'écran sombre « L'atelier dort. Votre demande, non. » remplace l'en-tête de Contact seulement quand l'atelier est fermé (hors horaires, jour férié, fermeture exceptionnelle) et que « Activer l'écran de rappel » est coché. N'activez cet écran que si quelqu'un rappelle vraiment à l'heure promise.
- Les créneaux sont pris de « Premier créneau » à « Dernier créneau » (8 h à 11 h par défaut), avec au plus « Rappels par créneau » demandes par heure (3 par défaut). L'heure la plus proche est aujourd'hui si elle est à plus de 30 minutes, sinon le jour ouvré suivant. En fermeture exceptionnelle, rien avant demain. Un créneau plein est barré et le premier créneau libre est coché. Le bouton dit « Me rappeler à 8 h » (`RappelsSite::creneaux`).
- Le serveur revérifie le créneau au moment de l'envoi (« Ce créneau vient d'être pris »). Le message est facultatif.
- Au local, le rappel ouvre une conversation dans la messagerie clients et entre dans la liste « À rappeler ». `app:messagerie:delais` (chaque minute) prévient la direction une seule fois quand un rappel n'est pas fait 15 minutes après l'heure promise.

**Confirmation** (EC06, /contact/envoye) : « Message reçu, Awa. », le numéro de la demande, trois étapes (Reçu à telle heure, Pris en charge, Réponse avant telle heure, calculée en heures ouvrées avec le délai de première réponse des Règles de réponse de la messagerie), le message envoyé, « Suivre ma commande » et « Continuer sur WhatsApp » (lien wa.me, sans intégration). Quand le visiteur a cité un numéro de commande qui correspond à son téléphone (même recherche que le suivi sans compte), une carte « Votre commande » montre les étapes et la pastille, sans montant. Sinon rien n'est dit sur la commande. La page est montrée une seule fois, `noindex`.

**Au local, Clients › Demandes de contact** (EC08, `/clients/messages/site`, droit de la boîte de la messagerie, donc caisse comprise) : demandes et rappels des 30 derniers jours avec leur conversation, la personne attribuée, le statut et le délai (en heures ouvrées, imputé à la personne attribuée). Indicateurs : demandes du jour, délai moyen, en retard, rappels à passer. L'onglet « À rappeler » est trié par heure, avec un bouton « Rappel fait ». Chaque demande ouvre une conversation d'origine « site ». Un numéro inconnu reçoit une fiche client « Site » et un prospect (`DemandesSite::preparer`).

**Nos engagements** (EC01 et EC02, /nos-engagements)
- Six promesses, chacune avec « Si on ne tient pas → » (tuile « Site : engagements et rappels », champs e1 à e6). Viennent ensuite le rappel Orange Money (seulement si Orange Money est accepté), la frise « Chaque étape est enregistrée », « Ce que vous voyez dans votre suivi », « Ce que nous ne promettons pas » (points séparés par « | ») et le formulaire « Un engagement non tenu ? ».
- **Chiffres** : l'encadré « Mesuré par notre logiciel d'atelier » n'apparaît qu'après publication par la direction. Sans chiffres, l'en-tête montre le texte seul.
- « Un engagement non tenu ? » (POST /nos-engagements/signaler) : la page est en cache public, donc pas de jeton de session. Le serveur contrôle l'origine de la requête, le champ piège, le jeton de délai et 6 envois par heure et par adresse IP. Si le numéro de commande et le téléphone correspondent, une **réclamation** est créée au nom du client (R-0025, motif tiré de l'engagement cité, auteur « Site · nom »). Elle monte comme celles de l'espace client. Sinon, le message part comme une demande de contact « Réclamation ».

**Suivre une réclamation** (EC03, /suivre-ma-reclamation) : numéro de la réclamation et téléphone, avec jeton CSRF et 20 recherches par heure et par IP (`espace_suivi_ip`). La réponse est la même si la réclamation n'existe pas ou n'est pas à ce numéro. La page montre la frise (envoyée, réponse de la direction, solution appliquée) et le temps de réponse comparé à l'engagement. **Les montants de la réponse sont masqués** (« Avoir de … »). Page privée, `no-store`, `noindex`, absente du plan du site.

**Au local, Clients › Engagements clients** (EC09, `/clients/engagements`, direction seulement) : les chiffres du mois choisi, mesurés dans l'ERP.
- Date tenue : commandes dont la date annoncée est passée et toutes les lignes terminées ce jour-là au plus tard. La liste des retards suit.
- BAT avant impression : contrôles partis en production qui demandaient un BAT.
- Réclamations résolues.
- Réponse dans le délai : supervision de la messagerie.
Un pourcentage est arrondi vers le bas (99,6 % reste 99 %). Sans mesure, le chiffre reste vide, jamais à 100 %. « Publier les chiffres d'octobre 2026 » range les chiffres mesurés dans le réglage du site `engagements_chiffres`, qui descend au site. « Retirer du site » les enlève. Ces boutons sont fermés sur l'ERP en ligne.

**Comment venir** (EC07, /comment-venir, indexée et dans le plan du site) : « Trouvez l'atelier du premier coup. ». Étapes : le quartier (plan ou carte), puis les photos réelles du local. Viennent ensuite les accès « en voiture », « à moto ou en Sotrama » et la livraison, **seulement s'ils sont saisis** dans la tuile « Site : contact et accueil » (vides par défaut : aucun repère n'est inventé). L'astuce « Venez avec votre fichier » termine la page. Boutons « Itinéraire » et « Demander le chemin » (WhatsApp).

**Calculateur** : sous le total, « Prête mer. 21/10 si vous commandez maintenant ». Cette date est calculée avec le délai du produit, les heures de travail et l'heure limite des délais de commande (`DelaisCommande::calculerPretLe`). Sans délai réglé pour le produit, la ligne ne s'affiche pas.

**Accueil, « En direct de l'atelier »** : machines en marche, m² imprimés aujourd'hui, travaux en cours, lus dans l'ERP. L'agent de synchronisation publie l'instantané (`atelier_direct`) seulement s'il a changé, ou au moins toutes les 10 minutes. Le site ne l'affiche que s'il a moins de 20 minutes. Un zéro n'est pas montré, et rien n'est montré quand l'atelier est fermé.

**Animations** (CSS et `public/js/site-animations.js`, sans bibliothèque ni CDN) :
- les blocs apparaissent en décalé (`data-revele`, `.si-revele`) et les compteurs défilent (`data-compte`) ;
- les pastilles « Ouvert » et « en direct » pulsent, les cartes se soulèvent au survol ;
- la frise des engagements se dessine, les étoiles de l'écran fermé scintillent et la confirmation montre une coche animée.
Avec `prefers-reduced-motion`, tout est coupé et le contenu est visible d'emblée. Sans JavaScript, rien n'est caché : la classe `si-anime` n'est posée que si l'animation est permise.

## Contenu du site (pages 04 à 09 et 12)

Réalisations, équipements, guide des fichiers, à propos, engagements, FAQ, conseils, annonces et équipe : le texte se saisit dans **Réglages › Site vitrine**, sur l'ERP local seulement, et descend au site par la synchronisation (voir docs/synchronisation.md). En ligne, l'écran est fermé (« Le contenu du site se modifie à l'atelier »).

### Accès

Droit fin **Site vitrine** (`ROLE_SITE_VITRINE`), donné à la Direction ; il se donne à un autre rôle dans Réglages › Utilisateurs et droits. Tuile « Site vitrine » dans Réglages › Paramètres et entrée du menu Réglages.

### Ce qui se gère

| Onglet | Contenu | Règle de publication |
|---|---|---|
| Pages | Nos équipements, Préparer son fichier, Qui sommes-nous ?, Nos engagements, Questions fréquentes : titre, accroche, description (moteurs de recherche), texte, image | Titre et texte obligatoires. Page non publiée : l'adresse répond 404. |
| Réalisations | Titre, catégorie (filtres de la page), description, 6 photos au plus, client | Le **nom du client** ne s'affiche et ne descend au site qu'avec la case « accord du client ». |
| Avis clients | Nom affiché, texte, note, source, date | Sans « accord du client », l'avis reste non publié ; ni son texte ni son nom ne descendent au site. Affichés sur « À propos ». |
| Conseils | Titre, adresse (créée depuis le titre, unique), rubrique, résumé, texte, image, date de publication | Un conseil daté dans le futur n'apparaît qu'à sa date. Chaque conseil entre dans /sitemap.xml. |
| Partenaires | Nom, logo, lien (http ou https seulement), ordre | Sans « accord obtenu », le partenaire reste masqué. Bande de logos sur l'accueil et « À propos ». |
| Annonces | Texte (200 caractères, une ligne), lien facultatif (page du site ou adresse web) et son texte, style (information, promotion, alerte), où la montrer (site public, espace client, les deux), premier et dernier jour, ordre | Montrée seulement « Active » et dans sa période (dates comprises ; sans date : tout de suite et sans fin). Voir « Bandes d'annonces » ci-dessous. |
| Équipe | Nom affiché, poste, photo, ordre ; pour le gérant : « Gérant », son mot, « aussi dans l'équipe » | Montrée seulement « Visible » **et** avec « La personne a accepté que sa photo et son nom paraissent sur le site ». Sans cet accord, pas de photo gardée. Le mot du gérant ne paraît qu'avec sa photo et son texte. Voir « Notre équipe » et « Le mot du gérant » ci-dessous. |
| Chiffres | Afficher ou non, règle des « projets réalisés », seuil | Voir docs/site-public.md. Un chiffre nul ou sous le seuil n'est pas montré. |
| Référencement et réseaux | Codes Google Search Console et Bing, lien de la fiche Google, pages des réseaux sociaux (Facebook, Instagram, TikTok, WhatsApp, LinkedIn, YouTube, X) | Valeur refusée (mauvais domaine, code illisible) : l'ancienne reste. Un réseau vide n'est montré nulle part. Voir docs/referencement.md. |
| Actions sociales | Titre, date, lieu (facultatif), texte, 1 à 10 photos, ordre | Montrée seulement publiée, avec son texte et au moins une photo. Voir « Nos actions sociales » plus bas. |
| Nous soutenir | Lien « Laisser un avis » de la fiche Google, QR code oui ou non | Lien refusé (pas https, pas Google) : l'ancien reste. Vide : « Nous soutenir » disparaît partout. Voir plus bas. |

Une case « publié » cochée alors qu'une règle manque : la fiche est enregistrée **sans être publiée**, avec le message de ce qui manque.

### Bandes d'annonces

Demande de Yaya du 15/10/2026 : une phrase courte en haut du site public, de l'espace client, ou des deux (fermeture exceptionnelle, promotion du mois, retard de livraison…). Saisie dans l'onglet **Annonces** (même droit `ROLE_SITE_VITRINE`, à l'ERP local), elle descend au site par la synchronisation (type `site_annonce`, docs/synchronisation.md). La liste montre l'état de chacune : Affichée, Programmée (pas encore commencée), Terminée, Inactive ; la fiche a un aperçu.

- **Site public** : une bande fine au-dessus de l'en-tête (`templates/site/_annonces.html.twig`, inclus par `site/_site.html.twig`). **Espace client** : la même bande, arrondie, en haut du contenu de chaque page connectée (`espace/app/_app.html.twig`).
- Plusieurs annonces visibles (5 au plus, dans l'ordre choisi) : une à la fois, la suivante toutes les 7 s ; pause au survol et au clavier ; arrêt après 4 tours ou dès qu'on touche « 1/2 › ». Avec « réduire les animations », pas de défilement ni d'animation.
- « × » ferme les annonces pour la visite (`sessionStorage` du navigateur, rien sur le serveur) ; elles reviennent à la visite suivante.
- La bande est dans le fil de la page : elle ne couvre jamais un bouton ; boutons de 44 px. Sans JavaScript, toutes les annonces sont montrées l'une sous l'autre, sans boutons.
- Sans annonce à montrer, la page n'a ni bande, ni style, ni script en plus. Le style et le petit script sont écrits dans la page seulement quand une annonce est montrée.
- Le lien n'accepte qu'une page du site (« /nos-produits ») ou une adresse http(s) ; une adresse web s'ouvre dans un nouvel onglet. Pas de montant de client dans une annonce : elle est lue par tout le monde.
- Les pages du site restent 5 minutes en cache dans le navigateur : une annonce peut mettre ce temps à paraître ou à partir après la synchronisation.

### Notre équipe

Demande de Yaya du 16/10/2026 : « Ajouter l'équipe au site vitrine », « avec les photos et nom et poste ». Onglet **Équipe** de Réglages › Site vitrine (même droit `ROLE_SITE_VITRINE`, à l'ERP local seulement), une fiche par personne :

- **Nom affiché** (60 caractères) : comme la personne le souhaite ; un prénom, ou prénom et initiale, suffit. **Poste** (80 caractères) : « Graphiste PAO », « Accueil et caisse »… Les deux sont obligatoires. **Ordre** : les plus petits numéros d'abord.
- **Photo** (facultative) : JPG, PNG ou WebP, recadrée en carré (au milieu de la largeur ; dans une photo en hauteur, plutôt vers le haut, là où est le visage), réduite à 480 px et réencodée (WebP, ou PNG), donc **sans EXIF ni position GPS**. Contrairement aux autres images du site, **l'original n'est pas gardé**. Sans photo, le site montre les initiales de la personne dans un rond aux couleurs du site.
- **« La personne a accepté que sa photo et son nom paraissent sur le site »** : obligatoire pour la montrer. Sans cette case, une photo envoyée est refusée (« cochez d'abord… », rien n'est enregistré) ; décocher la case retire la photo déjà enregistrée ; « Visible » coché est enregistré décoché, avec le message de ce qui manque.
- **Visible** : décocher pour masquer quelqu'un sans supprimer sa fiche (départ, congé).
- Rien d'autre n'est demandé ni montré, et la fiche **n'est liée à aucune fiche du personnel** : ni téléphone, ni e-mail, ni salaire, ni compte. Changer le personnel ne change rien ici, et inversement.

**Sur le site**, page « Qui sommes-nous ? » (`templates/site/_equipe.html.twig`) : dès qu'une personne est visible avec son accord, la section « Notre équipe » (ancre `#notre-equipe`) s'affiche en pleine largeur sous le premier bloc et **remplace les cartes Direction, Atelier, PAO** de la section « équipe » du texte de la page (le paragraphe de cette section, s'il y en a un, passe sous le titre). Sans personne affichée, la page reste comme avant.
- Une carte par personne : photo ronde (ou initiales), nom, poste. 2 cartes par ligne sur un téléphone, 3 sur une tablette, 4 puis 6 sur un ordinateur, centrées.
- Photos de 240 px (480 px pour les écrans les plus fins), chargées quand elles approchent de l'écran, largeur et hauteur écrites, texte pour les malvoyants « Photo de Awa, Graphiste PAO ».
- Les photos de l'équipe n'entrent ni dans le plan du site ni dans les données pour Google.

**Synchronisation** (type `site_equipe`, docs/synchronisation.md) : nom, poste, photo, ordre, visible, accord. Sans accord, le nom et le poste partent vides. Masquer une personne ou retirer son accord la retire du site à la synchronisation suivante ; retirer l'accord supprime aussi sa photo, de chaque côté. Supprimer sa fiche la supprime en ligne avec sa photo. Comme le reste, la page peut rester 5 minutes dans le cache du navigateur.

**Démonstration** (`app:demo`, docs/donnees-demo.md) : six personnes fictives aux avatars dessinés par le programme (`App\Service\Site\EquipeDemo`, jamais la photo d'une vraie personne), dont deux sans photo (initiales) et « Bakary C. », « Visible » coché mais sans accord, qui ne doit apparaître nulle part. « Kadiatou T. » est la gérante (son mot, ci-dessous). La recette des accès en ligne le vérifie (docs/recette-par-role.md).

### Le mot du gérant

Demande de Yaya du 17/10/2026 : « Ajouter le mot du gérant avec sa photo, ensuite l'équipe ». Le gérant est **une personne de l'onglet Équipe**, cochée « Gérant » : sa photo, son nom, son poste et son accord sont ceux de sa fiche, avec les mêmes règles (photo carrée réencodée, sans EXIF ni position GPS, original non gardé ; rien sans son accord). Rien n'est saisi deux fois.

- **Saisie** : bouton « Rédiger le mot du gérant » en haut de l'onglet Équipe (nouvelle fiche déjà cochée « Gérant »), ou, si le gérant est déjà dans la liste, case « Cette personne est le gérant (ou la gérante) » de sa fiche. Encart « Le mot du gérant » de la fiche : la case, **son mot** (900 caractères au plus, sans mise en forme, une ligne = un paragraphe ; plus long, rien n'est enregistré) et « Le montrer aussi dans « Notre équipe » » (décoché par défaut). **Pas de prix ni de montant** : un nombre suivi de « F », « FCFA », « francs », « fr » ou « € » dans le mot, le nom ou le poste est refusé, rien n'est enregistré, et le message cite le passage à retirer (une année, un pourcentage ou un numéro de téléphone passent).
- **Un seul gérant** : cocher la case sur une fiche la décoche sur l'autre (son mot reste enregistré mais ne paraît plus), avec un message.
- **Caché tant que ce n'est pas rempli** : le mot ne paraît que si la personne est gérant, visible, avec son accord, **avec une photo et un texte**. L'encart en haut de l'onglet Équipe et la fiche disent ce qui manque (« il manque la photo et son accord »).
- **Sur le site** (`templates/site/_mot_gerant.html.twig`, ancre `#mot-du-gerant`) : juste **avant « Notre équipe »**, sous le premier bloc de « Qui sommes-nous ? » ; titre « Le mot du gérant » (« Le mot de la gérante » quand le poste dit « Gérante »), photo ronde (216 px sur un ordinateur, 152 px sur un téléphone, chargée quand elle approche de l'écran, « Photo de Kadiatou T., Gérante » pour les malvoyants), le texte entre guillemets, signé du nom et du poste.
- **Pas deux fois** : quand son mot paraît, le gérant n'est pas répété dans la grille « Notre équipe », sauf si « aussi dans l'équipe » est coché. Tant que son mot est caché (texte ou photo manquant), il reste dans la grille comme les autres.
- **Synchronisation** : avec la fiche (type `site_equipe`) ; le mot ne part qu'avec l'accord de la personne et si elle est gérant, sinon vide.

### Texte des pages

Markdown simplifié (`App\Service\Site\MarkdownSite`), aide sous chaque champ : « ## » section, « ### » carte (ou question de la FAQ), listes « - », étapes « 1. », encadré « > », **gras**, *italique*, [lien](https://…). Une ligne « Œillets · Ourlets · Découpe » donne une rangée de pastilles ; dans une carte, une première ligne en italique (*Grand format*) devient son étiquette. Tout le texte est échappé : un HTML tapé s'affiche tel quel. Seuls les liens http, https, du site, mailto: et tel: sont gardés. L'icône d'une carte est choisie d'après les mots de son titre (`IconesSite`).

Règles propres à certaines pages :
- **Préparer son fichier** : la section dont le titre contient « gabarit » donne la liste des gabarits. « Nom — formats » ; un élément qui contient un lien affiche « Télécharger », sinon « Demander » (message WhatsApp).
- **Questions fréquentes** : chaque « ### » est une question ; la page porte les données FAQPage pour Google.
- **Conseils** : chaque article porte les données Article (titre, dates, auteur Succès Imprim, image).

Sans page « Demander un devis » ni « Contact », les boutons « Demander un devis » de ces pages ouvrent WhatsApp.

### Images

Une image envoyée (JPG, PNG ou WebP, 15 Mo au plus) est lue par GD, remise droite, réduite, **réencodée** en WebP (en PNG si GD ne sait pas écrire le WebP), donc sans EXIF ni position GPS, et ramenée à **300 Ko au plus**. C'est cette version qui est en base et qui descend au site ; elle est revérifiée à l'arrivée (type, taille, empreinte). L'original reste au local dans `var/uploads/site/originaux` (hors de public/), sauf pour la photo d'une personne de l'équipe : recadrée en carré de 480 px, elle n'est gardée que réencodée (usage « portrait »). Les fichiers servis (`public/uploads/site/`, ignoré par git) sont refaits depuis la base de chaque côté, en deux largeurs, avec largeur et hauteur dans la page et chargement différé.

**Sur le serveur, GD doit avoir WebP et JPEG.** Le conteneur de développement n'a que le PNG : les images y sont en PNG et un JPG est refusé avec un message clair.

### Textes provisoires

`php bin/console app:site:contenu-initial` (sur l'ERP local) crée les cinq pages avec un texte provisoire ; `--demo` ajoute 6 réalisations et 5 conseils marqués « (démo) ». La commande se relance sans risque : ce qui existe déjà n'est jamais modifié. Les exemples « (démo) » gardent la mention sur le site, pour qu'un visiteur ne les prenne pas pour de vrais travaux (elle est seulement retirée du nom des catégories) : à supprimer quand les vraies réalisations sont saisies.

Textes écrits sans information de l'atelier, **à relire et remplacer par Yaya** :
- **Nos équipements** : les trois machines (grand format, roll-up et adhésifs, textile DTF) et leurs points forts, à remplacer par le vrai parc (nom, largeur, usages) ; la liste des finitions, dont « Pose sur site ».
- **Préparer son fichier** : chiffres repris des maquettes, à confirmer par la PAO : 100 à 150 dpi en grand format, 300 dpi en petit format, fonds perdus 3 mm, marge de 5 cm sur une bâche, CMJN et noir riche ; les tailles des gabarits (roll-up 85 × 200 cm, carte 85 × 55 mm, flyer A5, Door Shape 80 × 180 cm, banderole). Aucun gabarit n'a encore de fichier : ajouter le lien quand le PDF est en ligne.
- **Qui sommes-nous ?** : le paragraphe de présentation (clients cités par type : commerçants, entreprises, ONG, institutions), les trois valeurs et les rôles de l'équipe (Direction, Atelier, PAO). Pas d'année de création. Les personnes, avec photo, nom et poste, se saisissent dans l'onglet Équipe (« Notre équipe » ci-dessus) : elles remplacent alors les trois rôles.
- **Nos engagements** : « Prix sans surprise », « Délai annoncé » et les étapes de commande (dont « Paiement de l'acompte » et « Contrôle qualité ») sont à valider ; seuls le BAT, la réimpression si le défaut vient de l'atelier et le paiement Orange Money étaient déjà affirmés.
- **Questions fréquentes** : toutes les réponses, en particulier l'acompte, la quantité minimale, la livraison à Bamako, la pose sur devis et l'entretien d'une bâche.
- **Conseils (démo)** : cinq articles d'exemple (bâche, fichier, DTF ou sérigraphie, roll-up, micro-perforé), à relire ou supprimer.

Aucun chiffre d'activité, aucune note ni aucun nom de client n'a été inventé. Les chiffres affichés sont calculés (docs/site-public.md) ; les avis et partenaires n'apparaissent qu'une fois saisis avec l'accord.

### Fichiers

- `src/Controller/SiteContenuController.php` (admin), `src/Controller/SitePagesController.php` (pages publiques), `src/Service/Site/` (`ContenuSite`, `MarkdownSite`, `ImagesSite`, `ChiffresSite`, `IconesSite`, `PlanConseils`, `ContenuInitial`), `src/Twig/SiteContenuExtension.php`.
- Annonces : `src/Entity/Annonce.php`, `src/Repository/AnnonceRepository.php`, `src/Twig/AnnoncesExtension.php` (`site_annonces()`), `templates/site/_annonces.html.twig`.
- Équipe et mot du gérant : `src/Entity/MembreEquipe.php` (table `site_equipe`, migrations `Version20261016123412` et `Version20261018090000`), `src/Repository/MembreEquipeRepository.php` (`visibles()`, `gerantDe()`, `grille()`), `templates/site/_equipe.html.twig`, `templates/site/_mot_gerant.html.twig`, `src/Service/Site/EquipeDemo.php` (démonstration), tests `tests/Unit/Service/Site/EquipeSiteTest.php`.
- `templates/site_contenu/` (admin), `templates/site/` (`realisations`, `equipements`, `guide`, `a_propos`, `engagements`, `faq`, `conseils`, `conseil`, `_chiffres`, `_partenaires`, `_avis`), `public/css/site-contenu.css`, `public/css/reglages-site.css`.
- Chaque page est servie par `SiteController::cache()` (5 minutes dans le navigateur).

## Nos actions sociales

Demande de Yaya du 16/10/2026 : « Sur le site vitrine, il faut prévoir nos actions sociales. […] Pour un premier temps, ça ne sera pas visible. Mais dès qu'on renseigne les images, les textes, que cette partie soit visible sur notre site. »

- **Saisie** : Réglages › Site vitrine › onglet **Actions sociales** (droit Site vitrine, ERP local seulement), « Nouvelle action » : titre, date, lieu facultatif, texte (Markdown simplifié, comme les pages), ordre (0 en premier ; à égalité, la plus récente), photos, case « Publié sur le site ». L'action descend au site par la synchronisation (type `site_action_sociale`, ses photos avec le type `site_image`).
- **Invisible tant qu'elle est vide** : tant qu'aucune action n'est publiée avec un texte et au moins une photo, `/nos-actions-sociales` répond 404, et il n'y a ni lien dans le pied de page, ni bloc sur l'accueil, ni place dans `/sitemap.xml`, ni données structurées. Dès la première action montrée, la page, le bloc de l'accueil (les trois premières actions) et le lien « Nos actions sociales » du pied de page apparaissent seuls, à la synchronisation suivante (les pages restent 5 minutes dans le cache du navigateur). Le menu principal garde ses sept entrées. Une action cochée « publiée » sans texte ou sans photo est enregistrée **sans être publiée**, avec ce qui manque ; la liste la marque « Incomplète ».
- **Photos** (10 au plus par action, la première est la photo principale) : JPG, PNG ou WebP de 15 Mo au plus, lues par GD, réduites à 1 200 px et **réencodées** : ni EXIF, ni position GPS, ni nom d'appareil. Contrairement aux autres images du site, **l'original envoyé n'est pas gardé** (`ImagesSite::oublierOriginal`) : ce sont souvent des personnes, des enfants. Description, ordre et retrait de chaque photo dans la fiche.
- **Accord** : la fiche et la liste rappellent qu'une personne reconnaissable ne paraît qu'avec son accord, un enfant avec celui de ses parents (sinon une photo de dos, de loin ou floutée), et jamais un nom d'enfant dans le texte.
- **Sur le site** : page « Nos actions sociales » (photos, date, lieu, texte), image de partage du site et non la photo d'une action ; dans le plan du site, la page seule, sans ses photos (pas poussées vers la recherche d'images).
- Code : `App\Entity\ActionSociale`, `ActionSocialeRepository`, `App\Service\Site\ActionsSociales` (partie visible ou non, plan du site), `SiteSocialController`, `App\Twig\SiteSocialExtension` (`site_actions_sociales()`), gabarits `site/actions_sociales`, `site/_accueil_social`, `site_contenu/_onglet_actions`, `_fiche_action`, `_fiche_action_photos`, feuille `public/css/site-social.css` (écrite dans la page seulement quand un bloc est montré). Migration `Version20261018104500`.

## Nous soutenir (avis Google)

Demande de Yaya du 17/10/2026 : « une partie sur le site et espace client pour nous soutenir https://g.page/r/Cb0JM2s-39CFECE/review ».

- **Réglage** : Réglages › Site vitrine › onglet **Nous soutenir** : le lien « Laisser un avis » (par défaut celui envoyé par Yaya) et « Montrer le QR code ». Seul un lien https d'un domaine Google est accepté (g.page, google.com, goo.gl, g.co) ; un lien refusé garde l'ancien. Lien vide : plus rien nulle part. Rangé dans le réglage du site `soutien`, il descend avec les autres réglages du site (type `site_reglage`).
- **Site public** : bloc « Nous soutenir » en bas de l'accueil (texte, bouton « Laisser un avis Google », QR code sur ordinateur), lien « Nous soutenir » dans le pied de page de chaque page, et rappel en bas de « Nos actions sociales ». Chaque lien s'ouvre dans un nouvel onglet (`target="_blank" rel="noopener"`).
- **Espace client** : carte « Nous soutenir » sur le tableau de bord (QR code sur ordinateur) ; « × » la ferme **pour cette personne seulement** et pour toujours (`compte_client.soutien_masque_le`, en ligne, rien ne monte au local ; permis aussi à un compte « Lecture · suivi ») ; sans JavaScript, le formulaire recharge la page. Invitation sur chaque commande livrée ou retirée. Ni montant ni donnée du client.
- **QR code** : fait par le site avec la bibliothèque des QR des factures (endroid/qr-code), en SVG, à `/nous-soutenir/qr-code.svg` ; aucun appel extérieur. Son adresse change avec le lien (`?v=`), le navigateur le garde un jour. L'onglet du réglage le montre, à imprimer pour le comptoir.
- **Pas de suivi** : aucun script de mesure ni compteur de clics sur ces liens. Même texte pour tous, quelle que soit la note donnée à l'atelier : Google interdit de ne solliciter que les clients contents.
- Code : `App\Service\Site\SoutienSite`, `SiteSocialController::qrCode`, `EspaceSoutienController` (`app_espace_soutien_masquer`), `SiteSocialExtension` (`site_soutien()`, `espace_soutien()`, `espace_soutien_commande()`), gabarits `site/_accueil_social`, `site_contenu/_onglet_soutien`, `espace/app/_tableau_soutien`, `espace/app/_soutien_commande`, script `public/js/espace-soutien.js`.

## Carte de l'imprimerie (page Contact)

- **Position** : tuile Réglages « Site : page Contact » (`site_contact.lien_plan`, droits Admin ou Paramètres). Par défaut, le repère de la fiche Google de l'imprimerie, « 12.6517571, -7.9299376 » (lien envoyé par Yaya le 15/10/2026) ; une valeur saisie la remplace et descend au site par la synchronisation des réglages. Vide : ni carte ni bouton.
- **Ce qui se saisit** : « latitude, longitude » (Google Maps : appui long sur l'imprimerie au téléphone, clic droit à l'ordinateur, puis copier les deux nombres), ou un lien long de Google Maps. Dans un lien long, le repère `!3d<latitude>!4d<longitude>` passe avant `@latitude,longitude`, qui n'est que le centre de la vue (environ 280 m à côté pour l'imprimerie). Un lien court (`maps.app.goo.gl`) ne donne pas la position : plan dessiné et bouton « Itinéraire » seulement. Les données structurées (`geo`) prennent le même repère (`DonneesStructurees::position`).
- **Affichage** : le plan dessiné (SVG) tient la place. Quand le bloc arrive à l'écran (IntersectionObserver), la page charge `public/js/site-carte.js`, qui charge Leaflet 1.9.4 (`public/vendor/leaflet`) et pose la carte : repère rouge, bulle avec le nom et l'adresse, bouton « Itinéraire » en haut à droite. Rien de la carte n'est téléchargé avant (au téléphone, 0 requête tant que le bloc n'est pas à l'écran). Styles : `public/css/site-carte.css`, écrits dans la page.
- **Fonds de carte** : `https://tile.openstreetmap.org`, seul site extérieur permis par la politique de contenu (des images, `img-src`), avec la mention « © OpenStreetMap » et son lien vers la page des droits, obligatoire.
- **Sans piège au défilement** : la molette ne zoome qu'après un clic sur la carte et s'arrête quand la souris en sort ; au téléphone, un doigt fait défiler la page tant que la carte n'a pas été touchée (deux doigts zooment), et faire défiler la page la rebloque. Une petite bulle le dit (« Cliquez sur la carte pour zoomer à la molette », « Touchez la carte pour la déplacer »).
- **Clavier** : la carte se prend au Tab (flèches pour la déplacer, + et − pour zoomer), puis le repère (Entrée ouvre la bulle), les boutons + et −, enfin « Itinéraire ». Mouvements réduits (`prefers-reduced-motion`) : ni fondu, ni animation de zoom, ni glissé de la carte (flèches, bulle qui se recadre).
- **Repli** : sans JavaScript, sans IntersectionObserver, si Leaflet ne se charge pas ou si aucun fond de carte n'arrive (trois erreurs ou 12 s), le plan dessiné reste, avec son lien d'itinéraire.
- **« Itinéraire »** : Google Maps, itinéraire jusqu'aux coordonnées (ou le lien saisi).
- **Fiche Google** (Référencement) : par défaut https://maps.app.goo.gl/S53cwaekd2L3FHP86 (`ReferencementSite::FICHE_GOOGLE`), lien « Voir notre fiche Google » de la page et `sameAs` des données structurées ; un lien saisi la remplace.
