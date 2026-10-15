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
| Nos engagements | 08 | /nos-engagements |
| Questions fréquentes | 09 | /faq |
| Suivre ma commande | 10 | /suivre-ma-commande |
| Contact, demande de devis | 11 | /contact, /demander-un-devis |
| Conseils | 12 | /conseils |

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
- /sitemap.xml (serveur en ligne seulement) : l'accueil, les pages du menu et du pied de page qui existent, la demande de devis, les pages de contenu publiées, les conseils, avec la date de dernière modification quand elle est connue et les photos (réalisations, conseils). Une page à contenu variable s'y ajoute en écrivant un service qui implémente `App\Service\Site\SourcePlanSite`.
- /robots.txt : en ligne, tout est permis sauf l'espace client, la connexion, l'API, l'administration et les liens privés ; sur l'ERP local, tout est interdit. En-tête `X-Robots-Tag: noindex` sur tout l'ERP local et, en ligne, sur tout ce qui n'est pas une page publique du site (`ReferencementSubscriber`).
- Chaque page a son titre (60 caractères au plus), sa description, un seul `<h1>`, son adresse canonique sans paramètres et ses balises de partage (Open Graph, Twitter) avec une image 1 200 × 630. Données structurées : fiche de l'imprimerie (accueil, contact, à propos), fil d'Ariane, produits avec leur prix de départ, questions fréquentes, conseils.
- Réglages › Site vitrine › Référencement : codes de vérification Google Search Console et Bing, fiche Google, réseaux sociaux, et la liste de ce qui manque encore pour Google.
- Page introuvable : statut 404, en français, avec les liens utiles.
- Mesure du 13/10/2026, accueil en ligne sur un téléphone en « 3G normale » (750 kb/s, 100 ms), sans cache : 30 Ko, premier affichage en 0,5 s, page chargée en 0,6 s.
- Mesure du 14/10/2026 en production (APP_ENV=prod), téléphone en « Fast 3G » des outils Chrome (1,4 Mb/s, 562 ms) et processeur ralenti 4 fois, sans cache : accueil 69 Ko, affiché en 1,4 s ; réalisations 79 Ko, 1,6 s ; produits et calculateur 65 à 67 Ko, 1,4 s. En revenant sur une page : 0,8 à 1,4 s.
- Mesure du 14/10/2026 au soir, après l'Atelier 3D et les animations (même méthode) : accueil 77 Ko, affiché en 1,6 s (1,1 s en « 3G normale ») ; atelier 121 Ko, affiché en 1,7 s (1,1 à 1,3 s en « 3G normale »), la 3D arrivant ensuite sans bloquer la page. Détails dans docs/atelier-3d.md.
- Mesure du 15/10/2026 (production derrière Apache comme chez LWS, téléphone 390 px, processeur ralenti 4 fois, sans cache, médiane de 3 passages) : en « Fast 3G », pages affichées en 0,7 à 0,8 s au lieu de 1,4 s, 32 à 39 Ko au lieu de 54 à 60 Ko ; studio textile 0,8 s au lieu de 1,5 s, 121 Ko au lieu de 245 Ko. En « 3G lente » des outils Chrome (2 s d'attente par requête), 2,2 s au lieu de 4,5 s : le minimum possible, la page n'ayant plus qu'une requête avant d'être lisible. Détails : /mnt/project-files/referencement/audit-2026-10-15.md.

## Ce que le site n'affiche jamais

Montants d'un client, noms de clients sans accord, coûts, marges, prix B2B. Pas de note Google inventée : la bande d'avis n'apparaîtra qu'avec le lien de la vraie fiche.

## Photos du site

Dix photos envoyées par Yaya le 15/10/2026 « pour le site » (originaux dans /mnt/project-files/uploads/hearth/, décrits dans /mnt/project-files/.notes/inputs.md). Ce sont des **visuels de présentation générés par intelligence artificielle pour la marque**, pas des photos de travaux livrés ni de clients : elles n'entrent ni dans Réalisations ni parmi les partenaires, et aucun texte ne les présente comme un travail fait pour un client.

### Où elles sont

| Photo (nom du fichier) | Page et endroit |
|---|---|
| Façade et enseigne (`enseigne-succes-imprim-bamako`) | Accueil, en haut à côté de « Nous imprimons vos succès ! » ; « Qui sommes-nous ? », bloc « Venez nous voir » (adresse, téléphone, lien vers Contact) ; image de partage (Facebook, WhatsApp) et image de la fiche de l'imprimerie pour Google |
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

Une photo ne revient jamais deux fois dans une page. Les cartes produit gardent leurs dessins (une photo par produit se répéterait), comme les réalisations sans photo, le guide, la FAQ et les conseils.

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
- **Changer d'endroit** : les noms sont dans `SiteController` (accueil : `PHOTO_ACCROCHE`, `PHOTO_PROJET`, `PHOTO_ATELIER`), dans `PhotosSite::FAMILLES` et `MOTS`, et dans les gabarits `a_propos`, `engagements` et `produits`.
- **Si la vraie boutique ou l'atelier ne ressemble pas aux visuels**, remplacer d'abord la façade (accueil, « Qui sommes-nous ? », partage) et l'atelier par de vraies photos.

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

## Produits et calculateur (maquettes 02 et 03)

Code : `SiteProduitsController`, `App\Service\Site\CatalogueSite` et `App\Service\Site\Calculateur`, gabarits `site/produits.html.twig` et `site/calculateur.html.twig`, feuille `public/css/site-produits.css`.

**Nos produits** (`app_site_produits`, /nos-produits)
- Catégories publiées, avec leurs produits publiés et actifs. Une catégorie et sa copie de démonstration « (démo) » ne font qu'un filtre, comme sur l'accueil ; `?categorie=<id>` filtre sans JavaScript.
- Prix de départ : la configuration la moins chère au prix normal, avec son unité (le m², l'unité...). Un produit sans configuration chiffrable affiche « Prix sur devis ».
- Pas de photo de produit en base : chaque carte a un dessin SVG choisi d'après le nom du produit (`CatalogueSite::ILLUSTRATIONS`), sinon d'après sa famille. En bas de page, l'encadré « Un autre support, une autre taille ? » porte la photo du fourgon habillé (« Photos du site »).

**Calculateur** (`app_site_calculateur`, /calculateur)
- Paramètres : `produit` (identifiant de la configuration, comme dans l'espace client), `categorie`, `largeur` et `hauteur` en cm, `quantite`, `finitions[]`.
- Mêmes produits que la commande en ligne (`NouvelleCommande::produits`), même calcul (`NouvelleCommande::estimer(..., b2b: false)`).
- Le formulaire part en GET et la page revient avec le prix : il marche sans JavaScript. Le script de la page demande le prix à /calculateur/estimation (`app_site_calculateur_estimation`, JSON) à chaque changement ; il ne calcule rien lui-même.
- Bornes : côtés de 0,01 à 5 000 cm (au-delà, « demandez un devis »), quantité jusqu'à 100 000, 30 finitions au plus. Une taille ou une quantité illisible est refusée avec un message, sans lien « Commander ».
- Le JSON donne le total, le détail (impression, finitions), les lignes affichées et les liens. Jamais le prix B2B, un coût ni une marge.
- « Commander » ouvre /espace/commandes/nouvelle avec les mêmes paramètres : connexion d'abord si besoin, puis la commande pré-remplie. « Demander un devis » mène à `app_site_devis` avec les mêmes paramètres ; le bouton apparaît quand cette page existe. « Recevoir le devis sur WhatsApp » envoie le choix et l'estimation en premier message.
- Délai : aucun délai de fabrication n'est saisi par produit. Le calculateur affiche « Annoncé à la validation du BAT ».
- Sur téléphone, le total reste en bas de l'écran pendant la saisie.

Ces pages et le JSON n'ouvrent pas de session : `Cache-Control: public, max-age=300`.

## Suivi, contact et demande de devis (maquettes 10 et 11)

Code : `SiteSuiviController`, `SiteDemandeController`, `App\Service\Site\{SuiviSite, ContactSite, FormulaireDemande, DemandesSite, PreparationDemande}`, `App\Service\EspaceClient\SuiviSansCompte`, entité `DemandeDevis` (migration `Version20261013225222`), gabarits `site/suivi`, `site/contact`, `site/devis`, `site/demande_envoyee`, feuille `public/css/site-formulaires.css`. Au local : `DemandesSiteController` (Ventes › Demandes du site).

**Suivre ma commande** (`app_site_suivi`, /suivre-ma-commande)
- Numéro de commande (complet, ou ses chiffres : « 0765 ») et téléphone de la commande. Même recherche que /espace/suivre (`SuiviSansCompte`) : jeton CSRF, 20 recherches par heure et par adresse IP, et **la même réponse** si la commande n'existe pas ou n'est pas à ce téléphone.
- Résultat : désignation, pastille (« En impression · prête vendredi », « BAT à valider »...) et les 6 étapes avec leur heure. Jamais de montant, de nom de client, de note ni de nom d'agent. Le détail, les factures et les BAT à valider renvoient à l'espace client.
- `noindex` (en-tête `X-Robots-Tag` et balise), `Cache-Control: private, no-store`, absent du plan du site.

**Contact** (`app_site_contact`, /contact)
- Adresse, téléphone et e-mail de l'entreprise, horaires du réglage « Horaires » avec « Ouvert maintenant » ou « Fermé, ouvre lundi à 8 h », bouton WhatsApp.
- Carte (ou plan dessiné) et bouton « Itinéraire » : voir « Carte de la boutique » plus bas.
- Court message : nom, téléphone malien, e-mail facultatif, objet (devis, suivi d'une commande, conseil sur un fichier, autre ; `?objet=commande` le présélectionne), message de 2 000 caractères au plus.

**Demander un devis** (`app_site_devis`, /demander-un-devis), sans compte
- Produit parmi le catalogue publié (mêmes produits que le calculateur, groupés par catégorie, sans prix), taille en cm si le produit en demande une, quantité, finitions proposées pour ce produit, message, coordonnées, fichier facultatif.
- Pré-remplissage depuis le calculateur : `produit` (identifiant de configuration), `largeur` et `hauteur` (cm), `quantite`, `finitions[]` (ou une liste séparée par des virgules). Une valeur inconnue ou hors bornes est ignorée, jamais affichée telle quelle.
- Fichier : PDF, AI, EPS, PSD, JPG, PNG ou TIFF, 20 Mo au plus (moins si le serveur n'accepte pas plus ; le formulaire affiche la limite réelle). Le contenu est vérifié (un faux PNG est refusé), puis le fichier est rangé hors de `public/` dans `var/uploads/commandes` (droits 0640), avec son empreinte SHA-256 et le contrôle automatique, comme « Mes fichiers ». Au-delà, le visiteur est invité à l'envoyer par WhatsApp.
- Confirmation (/demander-un-devis/envoyee, /contact/envoye) : numéro de la demande (W-DD-0005), résumé et prochaines étapes, **jamais de prix**. Montrée une seule fois, `noindex`.

**Anti-spam** (contact et devis) : jeton CSRF ; champ piège `site_web` caché (rempli : fausse confirmation sans numéro, rien d'enregistré) ; jeton signé de l'heure d'affichage (envoi refusé avant 4 s, page expirée après 12 h) ; 6 demandes valides par heure et par adresse IP (`site_demande_ip`) ; tout est revérifié côté serveur. Une erreur renvoie la page en 422 avec la saisie gardée.

**Accusé de réception** : un SMS sans montant (« nous avons bien reçu votre demande de devis W-DD-0005 ») part seulement si le service SMS est réglé, au plus 3 fois par jour vers un même numéro (`site_accuse_telephone`), jamais vers le numéro d'une fiche de démonstration ni d'un client qui a coupé les SMS. Si le site n'a pas pu l'envoyer, le local l'envoie à la réception, dans les 6 heures. Pas d'accusé WhatsApp pour l'instant (il faudrait un modèle de message approuvé).

**Au local** : la demande monte par la synchronisation (type `demande_devis`, sens ascendant). Le site ne la montre plus : aucune liste des demandes en ligne. À la réception, `DemandesSite::preparer` :
- demande de devis d'un numéro connu : rattachée à ce client, devis brouillon à son nom ;
- d'un numéro inconnu : un prospect source « Site » (montant estimé, relance le jour même) et une fiche client minimale (origine « site », à compléter) car un devis exige un client ;
- devis brouillon au tarif public (même calcul que le calculateur), avec la demande du visiteur en observation ; un produit hors bornes reste « à chiffrer » ;
- demande venue de l'Atelier 3D : même devis brouillon, au prix public de l'atelier (seconde face, palier de quantité) avec la désignation de la création ; ses vues et images sont dans Ventes › Créations Studio textile (docs/atelier-3d.md) ;
- un message est rattaché au client connu ; un message « demande de devis » d'un inconnu devient un prospect ;
- les personnes qui ont le droit Devis (et les administrateurs) sont prévenues.

Rien n'est confirmé ni envoyé au client sans geste de l'équipe : le devis brouillon n'est pas visible dans l'espace client. Badge « Site » dans Devis et commandes, Ventes et Prospects ; la fiche du devis rappelle la demande d'origine. Ventes › Demandes du site (droit Devis) liste les demandes à traiter et traitées ; la fiche montre le détail, le fichier à télécharger (téléchargement noté au journal d'activité) et « Marquer traitée ».

**Limites connues**
- Les réglages (horaires, lien du plan) ne descendent pas au site : en ligne, les horaires par défaut s'affichent et le plan reste caché tant qu'une synchronisation des réglages n'existe pas.
- Les demandes restent dans la base en ligne après leur montée (pas de purge automatique).

## Contenu du site (pages 04 à 09 et 12)

Réalisations, équipements, guide des fichiers, à propos, engagements, FAQ et conseils : le texte se saisit dans **Réglages › Site vitrine**, sur l'ERP local seulement, et descend au site par la synchronisation (voir docs/synchronisation.md). En ligne, l'écran est fermé (« Le contenu du site se modifie à l'atelier »).

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
| Chiffres | Afficher ou non, règle des « projets réalisés », seuil | Voir docs/site-public.md. Un chiffre nul ou sous le seuil n'est pas montré. |
| Référencement | Codes Google Search Console et Bing, lien de la fiche Google, pages des réseaux sociaux | Valeur refusée (mauvais domaine, code illisible) : l'ancienne reste. Voir docs/referencement.md. |

Une case « publié » cochée alors qu'une règle manque : la fiche est enregistrée **sans être publiée**, avec le message de ce qui manque.

### Texte des pages

Markdown simplifié (`App\Service\Site\MarkdownSite`), aide sous chaque champ : « ## » section, « ### » carte (ou question de la FAQ), listes « - », étapes « 1. », encadré « > », **gras**, *italique*, [lien](https://…). Une ligne « Œillets · Ourlets · Découpe » donne une rangée de pastilles ; dans une carte, une première ligne en italique (*Grand format*) devient son étiquette. Tout le texte est échappé : un HTML tapé s'affiche tel quel. Seuls les liens http, https, du site, mailto: et tel: sont gardés. L'icône d'une carte est choisie d'après les mots de son titre (`IconesSite`).

Règles propres à certaines pages :
- **Préparer son fichier** : la section dont le titre contient « gabarit » donne la liste des gabarits. « Nom — formats » ; un élément qui contient un lien affiche « Télécharger », sinon « Demander » (message WhatsApp).
- **Questions fréquentes** : chaque « ### » est une question ; la page porte les données FAQPage pour Google.
- **Conseils** : chaque article porte les données Article (titre, dates, auteur Succès Imprim, image).

Sans page « Demander un devis » ni « Contact », les boutons « Demander un devis » de ces pages ouvrent WhatsApp.

### Images

Une image envoyée (JPG, PNG ou WebP, 15 Mo au plus) est lue par GD, remise droite, réduite, **réencodée** en WebP (en PNG si GD ne sait pas écrire le WebP), donc sans EXIF ni position GPS, et ramenée à **300 Ko au plus**. C'est cette version qui est en base et qui descend au site ; elle est revérifiée à l'arrivée (type, taille, empreinte). L'original reste au local dans `var/uploads/site/originaux` (hors de public/). Les fichiers servis (`public/uploads/site/`, ignoré par git) sont refaits depuis la base de chaque côté, en deux largeurs, avec largeur et hauteur dans la page et chargement différé.

**Sur le serveur, GD doit avoir WebP et JPEG.** Le conteneur de développement n'a que le PNG : les images y sont en PNG et un JPG est refusé avec un message clair.

### Textes provisoires

`php bin/console app:site:contenu-initial` (sur l'ERP local) crée les cinq pages avec un texte provisoire ; `--demo` ajoute 6 réalisations et 5 conseils marqués « (démo) ». La commande se relance sans risque : ce qui existe déjà n'est jamais modifié. Les exemples « (démo) » gardent la mention sur le site, pour qu'un visiteur ne les prenne pas pour de vrais travaux (elle est seulement retirée du nom des catégories) : à supprimer quand les vraies réalisations sont saisies.

Textes écrits sans information de l'atelier, **à relire et remplacer par Yaya** :
- **Nos équipements** : les trois machines (grand format, roll-up et adhésifs, textile DTF) et leurs points forts, à remplacer par le vrai parc (nom, largeur, usages) ; la liste des finitions, dont « Pose sur site ».
- **Préparer son fichier** : chiffres repris des maquettes, à confirmer par la PAO : 100 à 150 dpi en grand format, 300 dpi en petit format, fonds perdus 3 mm, marge de 5 cm sur une bâche, CMJN et noir riche ; les tailles des gabarits (roll-up 85 × 200 cm, carte 85 × 55 mm, flyer A5, Door Shape 80 × 180 cm, banderole). Aucun gabarit n'a encore de fichier : ajouter le lien quand le PDF est en ligne.
- **Qui sommes-nous ?** : le paragraphe de présentation (clients cités par type : commerçants, entreprises, ONG, institutions), les trois valeurs et les rôles de l'équipe (Direction, Atelier, PAO). Pas de nom de personne ni d'année de création : à ajouter si Yaya le souhaite.
- **Nos engagements** : « Prix sans surprise », « Délai annoncé » et les étapes de commande (dont « Paiement de l'acompte » et « Contrôle qualité ») sont à valider ; seuls le BAT, la réimpression si le défaut vient de l'atelier et le paiement Orange Money étaient déjà affirmés.
- **Questions fréquentes** : toutes les réponses, en particulier l'acompte, la quantité minimale, la livraison à Bamako, la pose sur devis et l'entretien d'une bâche.
- **Conseils (démo)** : cinq articles d'exemple (bâche, fichier, DTF ou sérigraphie, roll-up, micro-perforé), à relire ou supprimer.

Aucun chiffre d'activité, aucune note ni aucun nom de client n'a été inventé. Les chiffres affichés sont calculés (docs/site-public.md) ; les avis et partenaires n'apparaissent qu'une fois saisis avec l'accord.

### Fichiers

- `src/Controller/SiteContenuController.php` (admin), `src/Controller/SitePagesController.php` (pages publiques), `src/Service/Site/` (`ContenuSite`, `MarkdownSite`, `ImagesSite`, `ChiffresSite`, `IconesSite`, `PlanConseils`, `ContenuInitial`), `src/Twig/SiteContenuExtension.php`.
- `templates/site_contenu/` (admin), `templates/site/` (`realisations`, `equipements`, `guide`, `a_propos`, `engagements`, `faq`, `conseils`, `conseil`, `_chiffres`, `_partenaires`, `_avis`), `public/css/site-contenu.css`, `public/css/reglages-site.css`.
- Chaque page est servie par `SiteController::cache()` (5 minutes dans le navigateur).

## Carte de la boutique (page Contact)

- **Position** : tuile Réglages « Site : page Contact » (`site_contact.lien_plan`, droits Admin ou Paramètres). Par défaut, le repère de la fiche Google de la boutique, « 12.6517571, -7.9299376 » (lien envoyé par Yaya le 15/10/2026) ; une valeur saisie la remplace et descend au site par la synchronisation des réglages. Vide : ni carte ni bouton.
- **Ce qui se saisit** : « latitude, longitude » (Google Maps : appui long sur la boutique au téléphone, clic droit à l'ordinateur, puis copier les deux nombres), ou un lien long de Google Maps. Dans un lien long, le repère `!3d<latitude>!4d<longitude>` passe avant `@latitude,longitude`, qui n'est que le centre de la vue (environ 280 m à côté pour la boutique). Un lien court (`maps.app.goo.gl`) ne donne pas la position : plan dessiné et bouton « Itinéraire » seulement. Les données structurées (`geo`) prennent le même repère (`DonneesStructurees::position`).
- **Affichage** : le plan dessiné (SVG) tient la place. Quand le bloc arrive à l'écran (IntersectionObserver), la page charge `public/js/site-carte.js`, qui charge Leaflet 1.9.4 (`public/vendor/leaflet`) et pose la carte : repère rouge, bulle avec le nom et l'adresse, bouton « Itinéraire » en haut à droite. Rien de la carte n'est téléchargé avant (au téléphone, 0 requête tant que le bloc n'est pas à l'écran). Styles : `public/css/site-carte.css`, écrits dans la page.
- **Fonds de carte** : `https://tile.openstreetmap.org`, seul site extérieur permis par la politique de contenu (des images, `img-src`), avec la mention « © OpenStreetMap » et son lien vers la page des droits, obligatoire.
- **Sans piège au défilement** : la molette ne zoome qu'après un clic sur la carte et s'arrête quand la souris en sort ; au téléphone, un doigt fait défiler la page tant que la carte n'a pas été touchée (deux doigts zooment), et faire défiler la page la rebloque. Une petite bulle le dit (« Cliquez sur la carte pour zoomer à la molette », « Touchez la carte pour la déplacer »).
- **Clavier** : la carte se prend au Tab (flèches pour la déplacer, + et − pour zoomer), puis le repère (Entrée ouvre la bulle), les boutons + et −, enfin « Itinéraire ». Mouvements réduits (`prefers-reduced-motion`) : ni fondu, ni animation de zoom, ni glissé de la carte (flèches, bulle qui se recadre).
- **Repli** : sans JavaScript, sans IntersectionObserver, si Leaflet ne se charge pas ou si aucun fond de carte n'arrive (trois erreurs ou 12 s), le plan dessiné reste, avec son lien d'itinéraire.
- **« Itinéraire »** : Google Maps, itinéraire jusqu'aux coordonnées (ou le lien saisi).
- **Fiche Google** (Référencement) : par défaut https://maps.app.goo.gl/S53cwaekd2L3FHP86 (`ReferencementSite::FICHE_GOOGLE`), lien « Voir notre fiche Google » de la page et `sameAs` des données structurées ; un lien saisi la remplace.
