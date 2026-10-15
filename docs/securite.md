# Sécurité du site en ligne

Ce que l'application fait pour se protéger, comment le revérifier, et les règles pour le code nouveau. Le dernier audit (15/10/2026) a donné ces règles ; le serveur en ligne a aussi sa liste dans « Sur le serveur » plus bas.

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

Deux zones :

- **site public, espace client, pages sans compte** (connexion, suivi de facture, Orange Money, avis) : tout vient du site lui-même. `default-src 'self'`, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`, `form-action 'self'` plus la page de paiement d'Orange Money (`*.orange-money.com`, où le bouton « Payer » renvoie), images `data:` et `blob:` (atelier 3D, repères du BAT, aperçus avant envoi), `worker-src 'self' blob:`.
- **administration** (personnel connecté, écran TV, tablette, pages de la zone `/lot/etiquette/suivi`) : en plus, ce que le thème charge encore d'ailleurs — polices Google, `cdn.jsdelivr.net` (Sortable, Chart.js, Bootstrap), `cdnjs.cloudflare.com` (Font Awesome) et les fonds de carte OpenStreetMap. À retirer quand ces fichiers seront copiés dans `public/vendor` (règle : pas de CDN).

Les scripts et styles écrits dans les pages restent permis (`'unsafe-inline'`) : beaucoup de pages en ont. `'unsafe-eval'` ne l'est pas.

Un fichier servi par l'application (image, JSON, BAT en image…) reçoit une politique qui n'exécute rien (`default-src 'none'; … sandbox`). Les PDF reçoivent seulement `frame-ancestors 'self'` : le lecteur PDF de Chrome refuse de s'ouvrir dans une page « sandbox ».

**Une page a besoin d'un fichier d'un autre site ?** Copier le fichier dans `public/vendor` (avec sa licence) plutôt qu'ajouter un site à la politique. Si c'est impossible, ajouter le site dans `AJOUTS_ADMIN` (administration seulement) et dans `tests/Unit/Security/EntetesSecuriteTest.php`, jamais dans la zone publique.

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

## Fichiers envoyés

Règle : un fichier de client se range **hors de `public/`** (`var/uploads/…`) et se sert par un contrôleur qui vérifie les droits ; une image de client est réencodée (sans EXIF).

Les dossiers encore sous `public/uploads` (chat, pré-presse, étiquettes, écran TV, images du site, logos des entreprises) suivent deux règles :

1. Le nom est choisi par le serveur et l'extension vient du **contenu** du fichier, dans une liste fermée : `App\Security\FichierEnvoye` (`extensionPour` pour une liste de types, `extensionPublique` qui remplace toute extension exécutable par `bin`). Jamais `guessExtension()` ni l'extension donnée par le navigateur telle quelle.
2. `public/uploads/.htaccess` refuse de servir, sous Apache ou LiteSpeed, tout fichier PHP, CGI, HTML, SVG, XML ou JavaScript, même avec une seconde extension (`photo.php.jpg`), et ajoute `nosniff`. Sous Nginx, la ligne équivalente est dans docs/deploiement.md.

## Adresses de retour

Un formulaire qui renvoie vers « la page d'où l'on vient » (`retour`) passe par `App\Security\RetourInterne::chemin()` : seulement une page du site, jamais `//autre-site.com`, `/\autre-site.com` ni une adresse complète.

## Revérifier

Au local, après une modification :

```
php vendor/bin/phpunit tests/Unit/Security
APP_EN_LIGNE=1 APP_DEBUG=0 php bin/console app:recette:acces
composer audit
```

`app:recette:acces` vérifie aussi les en-têtes de sécurité de `/`, `/espace/connexion` et `/login`. La lancer avec `APP_DEBUG=0` : en mode débogage, une page refusée contient la trace de l'erreur, que la recette prend pour des montants.

Dans le navigateur (Chrome, outils de développement, onglet Console) : aucune ligne « Content Security Policy » sur les pages modifiées, à l'ordinateur et au téléphone.

Sur le site en ligne, depuis n'importe quel ordinateur :

```
curl -sI https://succesimprim.com/ | grep -iE 'content-security|strict-transport|x-frame|x-content|referrer|permissions|x-powered|server'
for p in /.env /.env.local /.htaccess /composer.json /composer.lock /.git/HEAD /var/log/prod.log /_profiler /info.php /uploads/essai.php /api/v1; do printf '%s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://succesimprim.com$p"; done
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' http://succesimprim.com/
```

Attendu : les six en-têtes de la table, `Strict-Transport-Security` compris, et pas de `X-Powered-By` ; 403 ou 404 pour chaque fichier de la liste ; la page en http renvoie (301) vers https.

## Sur le serveur

- HTTPS forcé dans le panneau de l'hébergeur (pas dans `public/.htaccess`, qui sert aussi à XAMPP).
- PHP : `display_errors` désactivé (`public/.user.ini` le fait quand PHP tourne en FPM ou CGI, comme sur un hébergement mutualisé ; sinon réglage PHP du panneau), `expose_php` désactivé (réglage du panneau seulement).
- `.env.local` en droits 600, jamais dans le dépôt ; `APP_ENV=prod`, `APP_SECRET` de 32 caractères au moins, propre au serveur.
- L'application doit voir la vraie adresse des visiteurs : si l'hébergeur passe par un relais, régler `SYMFONY_TRUSTED_PROXIES` ; sinon toutes les limites par connexion tombent sur la même adresse.
- Sauvegardes de la base et de `var/uploads` (docs/sauvegardes.md).

## Règles pour le code nouveau

- Une route qui modifie quelque chose : `POST`, jeton CSRF vérifié, droits vérifiés dans le contrôleur.
- Une fiche demandée par son numéro : chercher **dans** les fiches du client ou du compte connecté (`…duClientParId`), jamais `find($id)` puis comparer.
- Un fichier envoyé : hors de `public/`, ou `FichierEnvoye` si le dossier est public.
- Un code ou un numéro tapé sans compte : une limite dans `rate_limiter.yaml`.
- Un message d'erreur de connexion : le même, que le compte existe ou non.
- Pas de `|raw` sur un texte saisi ; pour du JSON dans un `<script>`, `json_encode` avec `JSON_HEX_TAG`.
- Pas de fichier d'un autre site à l'exécution : copier dans `public/vendor`.
- Aucune clé, aucun mot de passe dans le dépôt, un message ou une capture d'écran.
