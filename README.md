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
