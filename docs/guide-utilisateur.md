# Guide utilisateur dans l'application

Un guide par rôle, dans l'ERP local : une fiche par tâche, avec son but, les étapes numérotées, une ou deux images de l'écran et « À savoir ». La formation d'une demi-journée s'appuie dessus (`docs/formation.md`).

## Où le trouver

- « ? Aide » en haut de chaque écran, à côté de la recherche (sur le téléphone, l'icône seule). Aussi « Aide et guide » dans le menu de la personne (son avatar). Pas d'entrée de plus dans le menu de gauche.
- L'aide s'ouvre avec, en premier, « Pour l'écran que vous quittez » : les fiches de l'écran d'où l'on vient (paramètre `ecran`, le nom de la route).
- Puis les groupes : les siens d'abord, ensuite ceux qu'elle ouvre aussi.
  - Premiers pas : le parcours du premier jour, de la connexion à la facture.
  - Direction, Caisse et commercial, Graphiste PAO, Opérateur production, Livreur, Entretien.
  - Mon espace : pointage, absences, prime, statistiques.
- « Imprimer mon guide » : une page A4 par fiche, avec une couverture et un sommaire. Le guide imprimé contient Premiers pas, le groupe du rôle, les groupes dont le droit est ajouté sur la fiche de la personne (par exemple « Encaissements » pour un graphiste), puis Mon espace. « Toutes mes fiches » imprime tout ce que la personne voit ; « Imprimer ces fiches » imprime un seul groupe.
- « Fiches du formateur » (`/aide/formation`, Direction seulement) : la demi-journée de chaque rôle, à imprimer pour le formateur.

| Adresse | Qui |
|---|---|
| `/aide` | toute personne connectée ; seulement ses fiches |
| `/aide/{fiche}` | qui a les droits de l'écran décrit |
| `/aide/imprimer` | toute personne connectée ; seulement ses fiches |
| `/aide/capture/{nom}.webp` | qui a les droits d'une fiche qui montre cette image |
| `/aide/formation` | Direction (`ROLE_ADMIN`) |

## Qui voit quoi

- Chaque fiche porte les droits de l'écran qu'elle décrit et de ce que montrent ses images (`src/Service/Guide/CatalogueGuide.php`). Le contrôleur les vérifie sur le serveur : la liste, l'impression et les images ne montrent que les fiches permises. L'adresse d'une autre fiche tapée à la main répond « accès refusé », de même pour son image.
- Un droit en liste veut dire « l'un suffit » (par exemple la fiche du montage s'ouvre avec la prépresse ou la production).
- Mode « Voir » (Rôles et menus) : un module en lecture seule garde seulement ses fiches de consultation ; les fiches qui créent ou modifient disparaissent, comme les boutons.
- Les fiches « Mon espace » demandent une fiche employé (droit `PERSONNEL`, `src/Security/PersonnelVoter.php`), comme Mon espace et le pointage.
- Le texte des fiches ne contient aucun montant. Les images sont prises avec le compte le moins large qui ouvre l'écran : par exemple la commande et la facture avec un graphiste, sans les paiements.
- Sur le site en ligne (`APP_EN_LIGNE=1`), le guide n'existe pas : toutes ses adresses répondent « page introuvable » (`AideHorsLigneSubscriber`) et le lien « ? Aide » n'est pas affiché.
- La recette des accès (`app:recette:acces`, `docs/recette-par-role.md`) ouvre chaque fiche et chaque image avec chaque compte de démonstration et compare aux droits de la fiche : une fiche ouverte sans ces droits est une fuite, une fiche refusée alors que les droits y sont est « à examiner ».

## Ajouter ou changer une fiche

1. Ouvrir l'écran avec le compte de démonstration du rôle et faire la tâche jusqu'au bout. Noter les mots exacts de l'écran (boutons, messages).
2. Ajouter la fiche dans `CatalogueGuide::fiches()`, au bon groupe :
   - `cle` : en minuscules et tirets ; elle donne l'adresse `/aide/{cle}` (pas `imprimer`, `capture` ni `formation`) ;
   - `titre`, `but` : une phrase courte chacun ;
   - `droits` : ceux de l'écran (au moins un) ; une liste à l'intérieur veut dire « l'un suffit » ;
   - `route` : l'écran décrit (bouton « Ouvrir l'écran ») ; `ecrans` : débuts de nom de route où la fiche vient en premier ;
   - `captures` : une ou deux images, `mobile: true` pour une image de téléphone ;
   - `consultation: true` si la fiche ne fait que regarder (elle reste visible en mode « Voir »).
3. Écrire `templates/aide/fiches/{cle}.html.twig` avec deux blocs : `etapes` (une liste `<ol>`) et `savoir` (une liste `<ul>`). Des phrases courtes, les mots de l'écran entre « », aucun montant.
4. Ajouter la scène de ses images dans `tools/guide/captures.mjs`, puis refaire les images (ci-dessous).
5. Lancer `php vendor/bin/phpunit tests/Unit/Service/Guide` : chaque fiche a un droit connu, une route qui existe, son modèle avec ses deux blocs, ses images ; pas de clé ni d'image en double, pas d'image en trop, moins de 4 Mo d'images.
6. Si la fiche entre dans une formation, l'ajouter au bloc du rôle dans `src/Service/Guide/Formation.php` et reprendre `docs/formation.md` (un test vérifie que la doc a les mêmes exercices).

## Refaire les images

Les images sont dans `ressources/guide/captures/` (WebP, hors de `public/`), servies par `AideController` avec les droits de la fiche. Bureau 1440 × 900, téléphone 390 × 844. Elles se prennent seulement sur une base de démonstration : le script crée un client, un devis, une commande, un paiement, une facture et valide une remise de livraison.

1. Une base neuve remplie par la démonstration :
   ```
   php bin/console doctrine:migrations:migrate -n
   php bin/console app:demo
   ```
2. Le serveur local, en confiance avec 127.0.0.1 comme relais (le poste de machine de « Ma file » est reconnu par son adresse) :
   ```
   APP_EN_LIGNE=0 SYMFONY_TRUSTED_PROXIES=127.0.0.1 php -S 127.0.0.1:8000 -t public
   ```
3. Le lien de l'affiche de pointage, pour l'image du pointage (sans lui, cette image est sautée). Il se lit dans la base de démonstration et ne s'écrit nulle part :
   ```
   export POINTAGE_LIEN=$(mysql -N -e "select concat('/pointer/', code, '/', secret) from point_pointage limit 1" <base>)
   ```
4. Lancer le script (Playwright et Chromium déjà installés sur le poste) :
   ```
   BASE_URL=http://127.0.0.1:8000 PLAYWRIGHT=/chemin/vers/playwright/index.mjs CHROMIUM=/chemin/vers/chrome node tools/guide/captures.mjs
   ```
   - `SEULES=remise,tournee` refait seulement ces images ; `COPIE=<dossier>` en copie aussi pour la relecture ; `QUALITE=0.7` règle la compression.
   - Une remise ou une commande déjà faites ne se refont pas : pour tout refaire, repartir d'une base neuve (étape 1).
5. Relire les images : pas de vrai client, pas de code d'accès ni de secret. Le script masque le code de l'espace client.

Hors connexion, les fonds de carte (OpenStreetMap) ne se chargent pas : la carte des images de tournée reste grise.

## Fichiers

- `src/Controller/AideController.php` : liste, fiche, impression, images, fiches du formateur.
- `src/Service/Guide/CatalogueGuide.php` : les fiches et leurs droits ; `src/Service/Guide/Formation.php` : les plans de formation.
- `templates/aide/` : pages, impression A4, fiches (`fiches/`) ; `public/css/aide.css`.
- `src/EventSubscriber/AideHorsLigneSubscriber.php` : fermé en ligne ; `src/Security/PersonnelVoter.php` : fiches « Mon espace ».
- `tools/guide/captures.mjs` : les images.
- Tests : `tests/Unit/Service/Guide/`, `tests/Unit/Security/AideAccesTest.php`.
