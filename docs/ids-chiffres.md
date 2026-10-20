# Numéros des fiches chiffrés dans les URL

Demande de Yaya (20/10) : aucun numéro de fiche lisible dans les URL.
« /commandes/4217 » devient « /commandes/2C6t1Ellp ».

## Ce que fait l'application

- **Chiffrement** (`App\Routing\CodeId`). Le numéro passe par un chiffrement de Feistel à 4 tours, avec HMAC-SHA256 et la clé `ID_CLE`. Il reçoit ensuite une signature de 21 bits. Le code fait toujours 9 caractères `[0-9A-Za-z]`. Deux fiches qui se suivent donnent des codes sans rapport. Un code inventé ou modifié est refusé (page introuvable).
- **Création des liens** (`App\Routing\RouteurIdsChiffres`, qui décore le routeur). `path()`, `url()`, `redirectToRoute()` et `generateUrl()` chiffrent tout seuls les paramètres listés dans `App\Routing\IdsChiffres::NOMS` : `id`, `commande`, `client`, `facture`, `ligne`, `paiement`, etc. Cela vaut dans le chemin et dans la requête (`?commande=`).
- **Routes** (`App\Routing\ChargeurRoutesIdsChiffres`, branché dans `config/routes.yaml`). Un paramètre en `\d+` accepte aussi le code de 9 caractères.
- **Arrivée** (`App\EventSubscriber\IdsChiffresSubscriber`, juste après le routeur). Le code redevient le numéro avant que le contrôleur charge la fiche. Les contrôleurs n'ont rien à changer.
- **URL écrite à la main** dans un gabarit ou un script : utiliser le filtre `{{ x.id|code_id }}`.
- **Hors périmètre** : l'API (`/api/v1`, routes `api_*`) et les routes internes de Symfony (`_*`) gardent leurs numéros. L'espace client utilisait déjà des identifiants publics (UUID) et des jetons.

## Anciens liens

Les favoris, les QR déjà imprimés, les notifications et les messages déjà envoyés contiennent des numéros. Avec `ID_CHIFFRES_ANCIENS_LIENS=1` (le défaut), une page ouverte avec un numéro est renvoyée vers la même page avec le code. Avec `0`, un numéro dans le chemin donne « page introuvable ».

## Mise en ligne

`ID_CLE` doit être **la même** sur l'ERP local et sur le site, dans `.env.local`. Sinon, un lien créé d'un côté ne s'ouvre pas de l'autre. Pour la générer : `php -r 'echo bin2hex(random_bytes(32)), "\n";'`. Ne jamais la changer après la mise en service, car tous les liens déjà envoyés deviendraient invalides. Sans `ID_CLE`, `APP_SECRET` sert.

## Ce que cela protège, et ce que cela ne protège pas

Le chiffrement empêche de deviner ou de parcourir les fiches en changeant un numéro, et il ne montre plus combien de commandes ou de clients existent. Ce qui protège vraiment les données reste le contrôle des droits sur chaque page. `app:recette:acces` le vérifie : il trouve 0 fuite.
