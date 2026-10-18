# Référencement du site vitrine

Pour qui ajoute ou modifie une page du site vitrine (docs/site-vitrine.md). But : que Google comprenne chaque page et la montre aux recherches faites à Bamako (« imprimerie à Bamako », « impression de bâches », « t-shirts personnalisés »…), sans jamais rien inventer.

Audit complet du 15/10/2026 : `/mnt/project-files/referencement/audit-2026-10-15.md`.

## L'adresse du site

Tout ce que lisent les moteurs et les réseaux sociaux (adresse canonique, plan du site, robots.txt, images de partage, données structurées) part de `App\Service\Site\AdresseSite` :

1. le champ « Site » de l'entité Succès Imprim (Réglages › Entités et en-têtes), qui descend au site ;
2. sinon `DEFAULT_URI` sur le serveur en ligne, `SYNC_URL` sur l'ERP local ;
3. sinon l'adresse de la requête.

L'adresse est toujours en https, sans chemin. Une adresse d'essai (localhost, IP, nom sans point, port) est écartée. Jamais l'adresse de l'ERP local.

Le champ « Site » doit être l'adresse que Google doit garder : avec ou sans « www », la même que celle déclarée dans Search Console et que celle vers laquelle l'hébergeur renvoie.

## Une nouvelle page : la liste

1. **Titre** (`{% block title %}`) : ce que la page offre, avec « à Bamako » quand cela a un sens. Viser 60 caractères, jamais plus de 65 (Google coupe vers 600 pixels), un titre différent sur chaque page. `{{ 'Mon titre'|site_titre }}` ajoute « · Succès Imprim, imprimerie à Bamako » ou « · Succès Imprim » quand il reste la place ; un titre qui porte déjà le nom reste tel quel.
2. **Description** (`{% block description %}`) : 120 à 158 caractères, une ou deux phrases qui disent quoi, où et ce qui rassure (BAT avant impression, prix en FCFA). Pour un texte saisi dans Réglages › Site vitrine : `|site_description` le met sur une ligne et le coupe entre deux mots.
3. **Titres dans la page** : un seul `<h1>` (le bandeau `site/_bandeau.html.twig` le pose), puis `<h2>`, `<h3>` dans l'ordre, sans sauter de niveau.
4. **Adresse canonique** : rien à faire. `site_canonique()` donne l'adresse du site suivie du chemin, sans paramètres (filtres, calculateur prérempli). Une page à ne pas garder remplace le bloc `canonique` du gabarit par `<meta name="robots" content="noindex">`.
5. **Indexation** : ajouter la route à `ReferencementSubscriber::INDEXABLES`. Toute autre réponse du serveur en ligne porte `X-Robots-Tag: noindex, nofollow` (espace client, connexion, recherche, API, réponses des formulaires, suivi de commande, erreurs) ; tout l'ERP local aussi.
6. **Plan du site** (/sitemap.xml) : une page du menu ou du pied de page (`SiteExtension::MENU`, `PIED`) y entre seule ; une autre page fixe s'ajoute à `PlanDuSite::AUTRES_PAGES`. Une page à contenu passe par un service qui implémente `App\Service\Site\SourcePlanSite` (exemples : `PlanConseils`, `PlanPages`, `PlanRealisations`) et donne sa date de dernière modification et ses images. Sans date connue, pas de `<lastmod>` : jamais une date inventée.
7. **robots.txt** : une nouvelle adresse privée (son début, comme « /espace ») va dans `SiteReferencementController::INTERDITS`. L'ERP local répond toujours « Disallow: / ».
8. **Données structurées** (JSON-LD, `App\Service\Site\DonneesStructurees`) :
   - fil d'Ariane : posé par le bandeau (`site_fil_ariane`) ; une page sans bandeau l'appelle elle-même ;
   - fiche de l'imprimerie (Organization, LocalBusiness, WebSite) : `{% set donnees_entreprise = true %}` sur les pages qui présentent l'imprimerie (accueil, contact, à propos) ;
   - conseil : `site_donnees_article(article, image)` ; produits : `site_donnees_catalogue(univers)` (prix de départ public, celui affiché) ; questions fréquentes : FAQPage par `SitePagesController::donneesFaq()`.
   - Jamais de note, d'avis, de prix B2B, de fourchette de prix ou de coordonnées devinées : un renseignement absent est laissé de côté.
9. **Image de partage** (WhatsApp, Facebook) : `{% set image_partage = {chemin: …, largeur: …, hauteur: …} %}` ; sans elle, le visuel de la façade et de l'enseigne `public/images/site/partage-enseigne-succes-imprim.jpg` (1 200 × 630, `site_image_partage()`) ; si ce fichier manque, le logo sur fond blanc `partage-succes-imprim.jpg`. L'image de la fiche de l'imprimerie pour Google (LocalBusiness) est la **vraie façade** en 16:9, 4:3 et 1:1 (`ReferencementExtension::IMAGES_FICHE`, docs/site-vitrine.md, « Vraies photos du local ») ; sans ces fichiers, l'image de partage.
10. **Images** : un `alt` qui dit ce qu'on voit (vide pour une image décorative), `width` et `height`, WebP (les photos envoyées passent par `ImagesSite` ; les photos livrées avec le site par `PhotosSite` et `site/_photo.html.twig`, avec `srcset` et `sizes`), `loading="lazy"` sous le premier écran seulement, jamais sur l'image du haut (`fetchpriority="high"` pour celle de l'accueil). Voir docs/site-vitrine.md, « Photos du site ».
11. **Feuille de style** : `{{ site_styles('css/ma-page.css') }}` plutôt qu'un `<link>` : la feuille est écrite dans la page, le premier affichage n'attend pas un aller-retour de plus (2 s en 3G lente). Une feuille qui ne sert qu'en bas de page reste un `<link>`. Viser moins de 14 Ko compressés pour le HTML d'une page.

## Réglages › Site vitrine › Référencement

Sur l'ERP local, droit **Site vitrine** (`ROLE_SITE_VITRINE`) ; enregistré par `POST /reglages/site-vitrine/referencement`, fermé en ligne.

- Code de vérification **Google Search Console** et **Bing** : coller la balise donnée ou le code seul ; le site affiche la balise `<meta>` correspondante. Ces codes ne sont pas secrets.
- **Mesure d'audience Google Analytics** (balise Google, gtag.js, demandée par Yaya le 15/10/2026) : l'identifiant « G-… » du flux de données du site, ou la balise entière collée telle quelle (seul l'identifiant est gardé). Par défaut celui de la boutique, `G-634VEQPP69` ; un champ vidé coupe la mesure. Voir « Mesure d'audience » plus bas.
- Lien de la **fiche Google** (Google Business Profile) : lien « Partager » de la fiche (maps.app.goo.gl, g.page, google.com). Il apparaît sur la page Contact, dans le pied de page et dans les données structurées. Par défaut, celui de la boutique (https://maps.app.goo.gl/S53cwaekd2L3FHP86, envoyé par Yaya le 15/10/2026) ; un lien saisi le remplace, un champ laissé vide le garde.
- Pages **Facebook, Instagram, TikTok, LinkedIn, YouTube** : adresse https du réseau seulement ; liens du pied de page et « sameAs » des données structurées.
- La colonne de droite donne les adresses à déclarer à Google et la liste de ce qui manque encore (e-mail, position GPS, fiche Google…).

Une valeur refusée n'est pas enregistrée (l'ancienne reste) et son message s'affiche. Le réglage descend au site par la synchronisation (`site_reglage`, docs/synchronisation.md).

## Mesure d'audience (Google Analytics)

`App\Service\Site\MesureAudience`, `public/js/mesure-audience.js`, posé par `site/_site.html.twig`.

- **Où** : seulement sur le site en ligne (`APP_EN_LIGNE=1`), sur les pages de `MesureAudience::PAGES` (les pages faites pour être trouvées, plus les confirmations « Demande envoyée » et « Message envoyé »), pour un visiteur. Jamais sur l'ERP local, dans l'espace client, l'administration, les pages de connexion ou de code, ni sur une adresse qui porte un code ou un jeton (suivi de facture `/f/…`, sondages `/avis/…`, PDF publics de devis et de factures, suivi d'étiquette, retours de paiement, écran TV, tablette, activation d'un appareil), ni sur « Suivre ma commande ». Le personnel connecté n'est pas compté.
- **Une nouvelle page publique** n'entre dans `PAGES` que si son adresse ne porte rien de privé (ni code, ni numéro de client, ni jeton).
- **Ce qui part chez Google** : la page vue, sans donnée personnelle. Ni identifiant d'utilisateur, ni client. L'adresse de la page part sans ses paramètres, sauf ceux d'une campagne (`utm_…`, `gclid`). Une page précédente du site part réduite à l'adresse du site (elle pourrait porter un code). Google Signals et la personnalisation des annonces sont coupés.
- **Quand** : gtag.js (plus de 100 Ko) est demandé une fois la page chargée : en 3G lente, il ne retarde ni le texte, ni les images, ni les polices. Le test d'installation d'Analytics peut répondre « balise non détectée » pour cette raison : vérifier plutôt dans Analytics › Rapports › Temps réel, après avoir ouvert le site sur un téléphone.
- **Sécurité** : seule exception à « rien d'un autre site » ; la politique de contenu de ces pages-là, et d'elles seules, s'ouvre à Google (docs/securite.md, « Politique de contenu »). `app:recette:acces` vérifie la balise et la politique sur l'accueil, et leur absence dans l'espace client, aux connexions et sur les adresses à code.

## Fichiers servis

- `public/.htaccess` : compression du texte (HTML, CSS, JS, JSON, XML, SVG) et cache du navigateur (7 jours pour CSS et JS, 30 jours pour images, polices et modèles 3D). Rien n'y force le https ni ne pose d'en-tête de sécurité. LiteSpeed (LWS) compresse de lui-même.
- Page introuvable : `templates/bundles/TwigBundle/Exception/error404.html.twig`, statut 404, `noindex`, liens vers les pages utiles, devis et WhatsApp.

## Vérifier

- Tests : `tests/Unit/Service/Site/AdresseSiteTest.php`, `DonneesStructureesTest.php`, `ReferencementSiteTest.php`, `MesureAudienceTest.php`, `PlanDuSiteTest.php`, `tests/Unit/Twig/ReferencementExtensionTest.php`, `tests/Unit/EventSubscriber/ReferencementSubscriberTest.php`, `tests/Unit/Security/SansCdnTest.php` (mesure d'audience).
- Après mise en ligne : test des résultats enrichis de Google (search.google.com/test/rich-results), Search Console › Inspection de l'URL, PageSpeed Insights sur téléphone.
