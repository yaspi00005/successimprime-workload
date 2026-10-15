# Sécurité du site en ligne

Ce que l'application fait pour se protéger, comment le revérifier, et les règles pour le code nouveau. Le dernier audit (15/10/2026) a donné ces règles ; le serveur en ligne a aussi sa liste dans « Sur le serveur » plus bas.

Points de l'audit traités depuis : **D4** fait (plus aucun fichier d'un autre site dans l'administration : « Bibliothèques copiées dans le site ») ; **D6** fait (jeton des modifications en JSON des finitions et des types d'impression : « Jetons des formulaires et des appels en JSON »).

## En-têtes de sécurité

Toutes les réponses de l'application reçoivent les mêmes en-têtes, au local comme en ligne. Ils sont posés à un seul endroit : `src/EventSubscriber/EntetesSecuriteSubscriber.php`, avec les valeurs de `src/Security/EntetesSecurite.php`. Un en-tête déjà posé par une page est gardé (le suivi de facture garde `Referrer-Policy: no-referrer`, les photos de réclamation leur politique stricte).

| En-tête | Valeur | Pourquoi |
|---|---|---|
| `Content-Security-Policy` | voir plus bas | aucun plugin, aucune balise `<base>`, aucun formulaire envoyé ailleurs, aucune page du site dans le cadre d'un autre site |
| `X-Frame-Options` | `SAMEORIGIN` | pareil pour les vieux navigateurs |
| `X-Content-Type-Options` | `nosniff` | le navigateur garde le type annoncé (une image reste une image) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | un autre site ne voit pas l'adresse complète d'où vient le visiteur |
| `Permissions-Policy` | caméra et position pour le site seulement ; micro, paiement, USB… fermés | pointage, photos et livraison en ont besoin, rien d'autre |
| `Strict-Transport-Security` | `max-age=31536000` | seulement quand la page arrive en https : le navigateur n'essaie plus le site en http pendant un an |
| `X-Powered-By` | retiré | la version de PHP n'a pas à être annoncée |

### Politique de contenu

Tout vient du site lui-même : `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`, `form-action 'self'` plus la page de paiement d'Orange Money (`*.orange-money.com`, où le bouton « Payer » renvoie), images `data:` et `blob:` (atelier 3D, repères du BAT, aperçus avant envoi), `worker-src 'self' blob:`.

Exception permanente : les fonds de carte OpenStreetMap, `https://tile.openstreetmap.org` en `img-src` (des images, aucun code), pour la carte de la page Contact et les cartes des clients et des tournées. Aucun script, style ou police d'un autre site, ni sur le site public ni dans l'administration (audit D4, voir « Bibliothèques copiées dans le site »).

Seule autre exception, demandée par Yaya le 15/10/2026 : la **mesure d'audience Google** (gtag.js). Sur les pages publiques du site en ligne qui portent la balise, et sur elles seules (`App\Service\Site\MesureAudience`, docs/referencement.md, « Mesure d'audience »), la politique ajoute `https://www.googletagmanager.com` en `script-src`, et `https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com` en `img-src` et `connect-src` (`EntetesSecurite::AJOUTS_MESURE_AUDIENCE`). La même décision pose la balise dans la page et ouvre la politique : l'une ne va jamais sans l'autre. Espace client, connexions, adresses à code (`/f/…`, `/avis/…`, PDF publics, retours de paiement), écran TV, administration et ERP local gardent la politique sans Google. Rien de personnel n'est envoyé (ni utilisateur, ni client, ni paramètre d'adresse hors campagne).

Le code garde deux zones (site public, espace client et pages sans compte ; administration : personnel connecté, écran TV, tablette) ; l'administration n'a aujourd'hui rien de plus (`AJOUTS_ADMIN` vide).

Les scripts et styles écrits dans les pages restent permis (`'unsafe-inline'`) : beaucoup de pages en ont. `'unsafe-eval'` ne l'est pas.

Un fichier servi par l'application (image, JSON, BAT en image…) reçoit une politique qui n'exécute rien (`default-src 'none'; … sandbox`). Les PDF reçoivent seulement `frame-ancestors 'self'` : le lecteur PDF de Chrome refuse de s'ouvrir dans une page « sandbox ».

**Une page a besoin d'un fichier d'un autre site ?** Le copier dans `public/vendor/<bibliothèque>-<version>/` avec sa licence plutôt qu'ajouter un site à la politique. `tests/Unit/Security/SansCdnTest.php` refuse toute balise `<script src>`, `<link href>`, `@import` ou police venant d'un autre site dans `templates/`, `public/css` et `public/js`, et vérifie que chaque fichier de `public/vendor` appelé existe et que chaque dossier a sa licence. Il vérifie aussi la seule exception : l'adresse de gtag.js n'est écrite que dans `public/js/mesure-audience.js`, qui n'appelle rien d'autre, et seul `templates/site/_site.html.twig` appelle ce fichier, quand `site_mesure_audience()` donne un identifiant.

## Bibliothèques copiées dans le site

Règle : rien d'un autre site à l'exécution (ni CDN, ni Google Fonts). Seule exception : la mesure d'audience Google du site public, plus haut. Les fichiers sont ceux des paquets officiels, sans modification (empreinte sha512 du paquet npm vérifiée au téléchargement), avec leur licence à côté.

| Bibliothèque | Version | Dossier de `public/vendor` | Licence | Pages |
|---|---|---|---|---|
| Sortable | 1.15.6 | `sortablejs-1.15.6/` (`Sortable.min.js`, `LICENSE`) | MIT | réglages du catalogue, nouveau devis, réglages de l'écran TV |
| Chart.js | 4.4.4 | `chart.js-4.4.4/` (`chart.umd.js`, `LICENSE.md`) | MIT | statistiques (globales, clients, commandes, trésorerie) |
| Bootstrap (CSS) | 4.6.2 | `bootstrap-4.6.2/` (`bootstrap.min.css`, `LICENSE`) | MIT | suivi d'une étiquette (QR code) |
| Font Awesome | 4.7.0 | `font-awesome-4.7.0/` (`css/font-awesome.min.css`, `fonts/*.woff2`, `*.woff`, `LICENSE.txt`) | police SIL OFL 1.1, CSS MIT | suivi d'une étiquette |
| Poppins | Google Fonts v24, 300 à 800 | `poppins-v24/` (`poppins.css`, 12 fichiers woff2, `OFL.txt`) | SIL OFL 1.1 | administration (police du thème) |
| Roboto | Google Fonts v51, 400, 500, 700 | `roboto-v51/` (`roboto.css`, 2 fichiers woff2, `OFL.txt`) | SIL OFL 1.1 | administration (bulles du thème) |
| Archivo | Google Fonts v25, 600, 800, 900 | `archivo-v25/` (`archivo.css`, 2 fichiers woff2, `OFL.txt`) | SIL OFL 1.1 | écran TV |
| Figtree | Google Fonts v9, 400 à 800 | `figtree-v9/` (`figtree.css`, 2 fichiers woff2, `OFL.txt`) | SIL OFL 1.1 | écran TV |
| Leaflet | 1.9.4 | `leaflet/` | BSD 2 clauses | cartes des clients et des tournées, carte de la page Contact |
| three.js | 0.186.0 | `three/` | MIT | Studio textile |
| pdf.js, pdf-lib | 4.10.38, — | `montage/` | Apache 2.0, MIT | montage d'impression |
| SweetAlert2 | 11.26.25 | `sweetalert2/` | MIT | fenêtres de confirmation |
| Polices du Studio textile | — | `polices-atelier/` | SIL OFL 1.1 | Studio textile |

- Chart.js : `chart.umd.js` est le fichier compressé du paquet. L'adresse du CDN (`chart.umd.min.js`) n'existe pas dans le paquet : le CDN la fabriquait en recompressant ce fichier.
- Polices Google : CSS de Google Fonts demandée comme Chrome, gardée telle quelle pour les sous-ensembles latin et latin-ext (`unicode-range` compris), adresses remplacées par les fichiers copiés ; `font-display: swap`. Roboto, Archivo et Figtree sont des polices variables : un fichier par sous-ensemble sert tous les poids.
- Les commentaires `sourceMappingURL` (Chart.js, Bootstrap) sont gardés, comme pour Leaflet : seuls les outils de développement du navigateur les lisent (404 sans effet).

### Thème de l'administration

Le `style.css` du thème Workload (sous licence, hors dépôt) demandait six familles de polices à Google (`@import url("https://fonts.googleapis.com/…")`). `app:installer-theme` retire ces lignes à l'installation ; pour un thème déjà installé, **une fois sur l'ERP et une fois sur le site en ligne** après la mise à jour :

```
php bin/console app:installer-theme --polices-locales
```

Les polices utilisées (Poppins, Roboto) sont servies par le site (`templates/base.html.twig`). Tant que la commande n'a pas été lancée, l'affichage est le même, mais le navigateur note six refus de la politique de contenu dans sa console ; `app:verifier-installation` le signale (« Polices du thème », à voir).

## Cookies

- Session (`config/packages/framework.yaml`) : `Secure` quand la page est en https, `HttpOnly`, `SameSite=Lax`.
- « Rester connecté » de l'espace client (`SI_ESPACE_SOUVENIR`, `config/packages/security.yaml`) : mêmes règles, chemin `/espace`, 30 jours, annulé par « Déconnecter les autres appareils ».
- Dernier utilisateur du poste (`LastUserCookieService`) : signé avec `APP_SECRET`, `HttpOnly`, `SameSite=Lax`.

Sans les règles écrites, Symfony mettait le cookie « rester connecté » sans `Secure` ni `SameSite`, même en https.

## Connexions

- **Personnel en ligne** (`AccesEnLigneSubscriber`) : l'appareil est contrôlé **avant** le mot de passe. Depuis un appareil non autorisé, la réponse est la même que le mot de passe soit juste ou faux, ou que l'identifiant n'existe pas : « Cet appareil n'est pas autorisé pour l'administration en ligne… ». Les administrateurs sont prévenus seulement quand le mot de passe était juste (une fois par jour et par appareil). Puis code de sécurité, 15 minutes sans activité.
- **Espace client** : mot de passe ou code reçu par e-mail ou SMS ; essais limités (`login_throttling`, `config/packages/rate_limiter.yaml`).
- **Codes publics** : chaque code qu'un inconnu peut taper a sa limite par connexion (adresse IP).

| Code | Limite |
|---|---|
| Code de connexion (espace, personnel) | 3 par destinataire en 15 min, 10 par connexion par heure |
| Suivi de commande sans compte | 20 par connexion par heure |
| Suivi de facture par QR (`/f/{code}`) | 20 codes **faux** par connexion par heure (`EssaisCodeFacture`) |
| Sondage, demande de devis, assistant, images de l'atelier 3D | voir `rate_limiter.yaml` |

Les limites par connexion supposent que l'application voit la vraie adresse du visiteur (voir « Sur le serveur »).

## Jetons des formulaires et des appels en JSON

Toute route qui modifie quelque chose vérifie un jeton CSRF, en plus du cookie `SameSite=Lax` (qui laisse passer une requête venue d'un autre port ou d'un sous-domaine du même site).

- Formulaires : champ `_token`.
- Appels en JSON depuis une page : en-tête `X-CSRF-TOKEN`, ou champ `_token` du corps (fenêtres des réglages du catalogue).
- Audit D6 : la modification d'une finition (`FinitionController::updateFinitionAjax`) et d'un type d'impression (`TypesImpressionController::updateAjax`) demande maintenant le jeton de sa route (`update_finition`, `update_type_impression`), lu par `App\Security\JetonJson` (en-tête, sinon `_token`). Sans jeton ou avec un mauvais : 403 en JSON, « Jeton de sécurité absent ou périmé : rechargez la page puis recommencez. », rien d'enregistré.
- Les scripts de ces deux pages (`public/assets/js/traitement_parametrage.js`, `traitement_types_impression.js`) ne sont pas dans le dépôt (dossier ignoré par git). Pour qu'ils marchent sans les modifier, la page charge `public/js/jeton-json.js` avec son jeton : il ajoute l'en-tête `X-CSRF-TOKEN` aux requêtes POST de la page vers `…/update/ajax` (fetch et XMLHttpRequest, donc jQuery), sans toucher aux autres requêtes ni à un en-tête déjà posé.

## Anciennes pages du thème

Le thème avait laissé des pages en anglais (« Create new », « back to list », « Edit », « Delete ») pour les catégories, clients, finitions, formats, paiements, configurations de produit, produits, supports, types d'impression et utilisateurs (mise à jour du 15/10/2026) :

- celles qu'aucune route n'affichait sont supprimées (dont deux pages d'exemple « Hello … »), avec leurs formulaires (`src/Form/FinitionType.php`, `FormatType`, `SupportsType`, `TypesImpressionType`, `CategorieProduitType`, `ProduitConfigurationType`, `ProduitsType`, `UserType`) ;
- `/finition/new`, `/finition/{id}` et `/finition/{id}/edit` renvoient à la liste des finitions, où l'ajout et la modification se font dans des fenêtres. Toujours réservées à la direction (`ROLE_ADMIN`). L'ancienne suppression par formulaire (`POST /finition/{id}`) n'existe plus : la liste supprime par `…/delete/ajax` ;
- `/paiements/{id}/edit` (paiement en attente, droit « Encaisser ») est en français, avec la présentation de l'administration.

Les routes en JSON que les scripts du thème appellent (`…/create/ajax`, `…/update/ajax`, `…/delete/ajax`, `…/mass-action`, `…/reorder`) restent (`AnciennesPagesThemeTest`).

## Fichiers envoyés

Règle : un fichier envoyé (par un client ou par le personnel) se range **hors de `public/`**, sous `var/uploads/…`, et s'ouvre seulement par une adresse de l'application qui vérifie les droits. Taper l'adresse sans le droit donne « accès refusé », ou la page de connexion sans compte. Une photo est réencodée : ses données EXIF, dont la position GPS du téléphone, disparaissent (constats D3 et D9 de l'audit du 15/10/2026).

### Fichiers privés (`var/uploads`)

| Dossier | Contenu | Qui l'ouvre | Adresse |
|---|---|---|---|
| `chat` | pièces jointes du chat du personnel | les participants de la conversation, et eux seuls (la direction n'ouvre pas une conversation dont elle ne fait pas partie) | `/chat/{conversation}/fichier/{message}` |
| `prepresse` | fichiers traités au contrôle pré-presse | droit Pré-presse ou Production (voir) ; l'outil de montage, avec ses propres droits | `/pre-presse/fichier/{id}/visualiser` et `/telecharger`, `/atelier/montage/fichier/{id}` |
| `etiquettes` | QR codes et ZIP des lots d'étiquettes | la direction, comme la page des lots | `/lot/etiquette/qr/{id}`, `/lot/etiquette/{id}/telecharger` |
| `reclamations` | justificatifs des demandes de remboursement du personnel | l'employé qui a fait la demande, la caisse, la trésorerie, la direction | `/reclamations/{id}/justificatif` |
| `employes` | photos du personnel | tout le personnel connecté (jamais un client ni un visiteur) | `/equipe/photo/{id}` |
| `entreprises` | logos et cachets des entreprises | personne : lus seulement pour fabriquer les factures et devis | aucune |
| `fichiers` | anciens fichiers divers | personne | aucune |

Les autres dossiers de `var/uploads` (`commandes` pour les fichiers des clients et les BAT, `atelier`, `imports`, `depenses`, `livraisons`, `entretien`…) y étaient déjà et ne changent pas.

Le service `App\Service\FichiersPrives` sait où se trouve chaque fichier. Les chemins enregistrés en base ne changent pas (`uploads/prepresse/…`, `uploads/etiquettes/…`) : ils se lisent maintenant sous `var/`, et encore sous `public/` sur une machine qui n'a pas lancé la commande de rangement ci-dessous. Pas de migration.

Les droits sont dans `App\Security\FichiersPrivesVoter` : les mêmes que la page qui montre le fichier. Un compte désactivé n'ouvre plus rien.

### Fichiers publics (`public/uploads`)

Deux dossiers seulement restent sous `public/uploads`, publics par nature :

- `site` : photos du site vitrine, réalisations, logos des partenaires. Elles sont faites pour être vues de tous sur le site public.
- `tv` : affiches, photos et vidéos de l'écran TV de la boutique. **Décision : ils restent publics.** Ce sont des médias choisis pour être montrés à tous les clients dans la boutique ; l'écran ne montre jamais de montant ni de nom de client. La page de l'écran s'ouvre déjà sans compte, avec son lien secret (`/ecran-accueil/{jeton}`). Vérifier les droits à chaque image ne cacherait rien que la boutique ne montre déjà, et faire passer les vidéos par PHP occuperait le serveur pour rien. Ne jamais y déposer un document privé.

Pour ces deux dossiers, deux règles :

1. Le nom est choisi par le serveur et l'extension vient du **contenu** du fichier, dans une liste fermée : `App\Security\FichierEnvoye` (`extensionPour` pour une liste de types, `extensionPublique` qui remplace toute extension exécutable par `bin`). Jamais `guessExtension()` ni l'extension donnée par le navigateur telle quelle.
2. `public/uploads/.htaccess` refuse de servir, sous Apache ou LiteSpeed, tout fichier PHP, CGI, HTML, SVG, XML ou JavaScript, même avec une seconde extension (`photo.php.jpg`), et ajoute `nosniff`. Sous Nginx, la ligne équivalente est dans docs/deploiement.md.

### Envoi d'un fichier privé

`FichierEnvoye::reponse()` envoie le fichier avec :

- le type lu dans le **contenu** du fichier, jamais celui enregistré en base ni celui du navigateur. Une page HTML, un SVG ou un script déguisé part en `application/octet-stream`, à télécharger ;
- `Content-Disposition` : affiché dans le navigateur pour une image ou un PDF, téléchargé pour le reste, avec un nom nettoyé ;
- `X-Content-Type-Options: nosniff` et la politique `sandbox` des fichiers (`EntetesSecurite::POLITIQUE_FICHIER`) ; un PDF peut s'afficher dans une page du site (`frame-ancestors 'self'`) ;
- `Cache-Control: private` : jamais gardé par un relais.

Les photos du chat (JPEG, PNG, WebP) et les photos des justificatifs passent par le réencodage des images de l'atelier (`ImagesAtelier::reencoder`, comme les images des clients). Un PDF ou un GIF (sans EXIF) est gardé tel quel. Sur un serveur dont PHP ne lit pas le WebP, une photo WebP est refusée avec un message, jamais gardée avec ses données.

### Anciennes adresses

Une ancienne adresse `/uploads/chat/…`, `/uploads/prepresse/…`, `/uploads/etiquettes/…`, `/uploads/reclamations/…` ou `/uploads/employes/…` (favori, ancien message) ne donne plus le fichier : sans compte, la page de connexion ; sans le droit, « accès refusé » ; avec le droit, renvoi vers la nouvelle adresse.

### Après la mise à jour du 15/10/2026 : ranger les anciens fichiers

Sur **chaque** installation (serveur en ligne et ERP local), une fois, dans le dossier du projet, après `git pull` et `cache:clear` (docs/deploiement.md, « Mettre à jour ») :

```
php bin/console app:fichiers:deplacer
php bin/console app:fichiers:deplacer --executer
php bin/console app:verifier-installation
```

La première ligne compte seulement les fichiers à ranger. La deuxième les déplace de `public/uploads/<dossier>` vers `var/uploads/<dossier>`, au même chemin. Elle affiche des nombres, jamais un nom ni le contenu d'un fichier, et se relance sans risque : « Rien à déplacer : tout est déjà rangé. » `app:verifier-installation` signale « Fichiers privés » tant qu'il en reste sous `public/uploads`.

En cas de conflit (un fichier différent déjà au même endroit sous `var/uploads`) ou d'erreur, les deux fichiers restent en place et la commande le dit : envoyer sa sortie au développeur. La relancer aussi après avoir remis d'anciens fichiers sous `public/uploads` (ancienne sauvegarde, `~/uploads-avant-retrait` de docs/depot-prive.md).

## Adresses de retour

Un formulaire qui renvoie vers « la page d'où l'on vient » (`retour`) passe par `App\Security\RetourInterne::chemin()` : seulement une page du site, jamais `//autre-site.com`, `/\autre-site.com` ni une adresse complète.

## Revérifier

Au local, après une modification :

```
php vendor/bin/phpunit tests/Unit/Security
APP_EN_LIGNE=1 APP_DEBUG=0 php bin/console app:recette:acces
composer audit
```

`app:recette:acces` vérifie aussi les en-têtes de sécurité de `/`, `/espace/connexion` et `/login`, et la mesure d'audience Google : balise et politique sur `/` et `/demander-un-devis` du site en ligne (si l'identifiant est réglé), ni l'une ni l'autre sur l'ERP local, dans l'espace client, aux connexions, sur « Suivre ma commande » et sur les adresses à code. La lancer avec `APP_DEBUG=0` : en mode débogage, une page refusée contient la trace de l'erreur, que la recette prend pour des montants.

Dans le navigateur (Chrome, outils de développement, onglet Console) : aucune ligne « Content Security Policy » sur les pages modifiées, à l'ordinateur et au téléphone.

Sur le site en ligne, depuis n'importe quel ordinateur :

```
curl -sI https://succesimprim.com/ | grep -iE 'content-security|strict-transport|x-frame|x-content|referrer|permissions|x-powered|server'
for p in /.env /.env.local /.htaccess /composer.json /composer.lock /.git/HEAD /var/log/prod.log /_profiler /info.php /uploads/essai.php /api/v1; do printf '%s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://succesimprim.com$p"; done
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://succesimprim.com/
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' https://succesimprim.com/uploads/chat/essai.jpg
```

Attendu : les six en-têtes de la table, `Strict-Transport-Security` compris, et pas de `X-Powered-By` ; 403 ou 404 pour chaque fichier de la liste ; la page en http renvoie (301) vers https ; l'ancienne adresse d'une pièce jointe du chat renvoie (302) vers la page de connexion.

## Sur le serveur

- HTTPS forcé dans le panneau de l'hébergeur (pas dans `public/.htaccess`, qui sert aussi à XAMPP).
- PHP : `display_errors` désactivé (`public/.user.ini` le fait quand PHP tourne en FPM ou CGI, comme sur un hébergement mutualisé ; sinon réglage PHP du panneau), `expose_php` désactivé (réglage du panneau seulement).
- `.env.local` en droits 600, jamais dans le dépôt ; `APP_ENV=prod`, `APP_SECRET` de 32 caractères au moins, propre au serveur.
- L'application doit voir la vraie adresse des visiteurs : si l'hébergeur passe par un relais, régler `SYMFONY_TRUSTED_PROXIES` ; sinon toutes les limites par connexion tombent sur la même adresse.
- Sauvegardes de la base et de `var/uploads` (docs/sauvegardes.md).
- Après la mise à jour du 15/10/2026, `app:fichiers:deplacer --executer` lancé une fois (« Fichiers envoyés »).

## Règles pour le code nouveau

- Une route qui modifie quelque chose : `POST`, jeton CSRF vérifié (en JSON : en-tête `X-CSRF-TOKEN`, `JetonJson::lire`), droits vérifiés dans le contrôleur.
- Une fiche demandée par son numéro : chercher **dans** les fiches du client ou du compte connecté (`…duClientParId`), jamais `find($id)` puis comparer.
- Un fichier envoyé : sous `var/uploads` (`FichiersPrives`), servi par `FichierEnvoye::reponse()` après la vérification des droits, une photo réencodée. `public/uploads` seulement pour ce qui est fait pour être vu de tous (site, écran TV), avec `FichierEnvoye`.
- Un code ou un numéro tapé sans compte : une limite dans `rate_limiter.yaml`.
- Un message d'erreur de connexion : le même, que le compte existe ou non.
- Un texte saisi : sa longueur vérifiée avant l'enregistrement, à la taille de sa colonne, avec un message clair (« Nom du support : 255 caractères au plus. ») et `maxlength` sur le champ ; sinon la base refuse et la page tombe en erreur (exemple : `LongueursCatalogue` pour Réglages › Finitions, Formats, Supports et Types d'impression).
- Pas de `|raw` sur un texte saisi ; pour du JSON dans un `<script>`, `json_encode` avec `JSON_HEX_TAG`.
- Pas de fichier d'un autre site à l'exécution : copier dans `public/vendor/<bibliothèque>-<version>/`, avec sa licence (`SansCdnTest`). Seule exception : la balise Google de mesure d'audience, sur les pages de `MesureAudience::PAGES` (jamais une page privée ou à code).
- Aucune clé, aucun mot de passe dans le dépôt, un message ou une capture d'écran.
