# successimprime
# successimprime

## Tests

```bash
composer install
php bin/console lint:container
php vendor/bin/phpunit
```

Les tests unitaires sont dans `tests/Unit`. La CI GitHub Actions (`.github/workflows/ci.yml`)
lance ces verifications sur chaque pull request.

## Images du guide utilisateur (`tools/guide/`)

`tools/guide/captures.mjs` refait les images du guide (« ? Aide », `docs/guide-utilisateur.md`) avec Playwright, sur une base remplie par `php bin/console app:demo`, jamais sur la base de la boutique. Les images vont dans `ressources/guide/captures/` (WebP, hors de `public/`).

```bash
APP_EN_LIGNE=0 SYMFONY_TRUSTED_PROXIES=127.0.0.1 php -S 127.0.0.1:8000 -t public &
BASE_URL=http://127.0.0.1:8000 node tools/guide/captures.mjs            # toutes
SEULES=remise,tournee node tools/guide/captures.mjs                     # quelques-unes
```

Variables (`PLAYWRIGHT`, `CHROMIUM`, `POINTAGE_LIEN`, `COPIE`, `QUALITE`) : en tête du script et dans `docs/guide-utilisateur.md`.
