# Mise en ligne du site sur un VPS (jalon J7)

L'ERP local reste à l'atelier (Windows, XAMPP). Le site vitrine, l'espace client et l'administration en ligne tournent sur un serveur loué (VPS) sous Linux. Les deux installations ont le même code ; la synchronisation fait le lien (docs/synchronisation.md).

Ce guide suppose Ubuntu 24.04 LTS. Le choix du fournisseur reste à faire par Yaya.

## Essai sur un hébergement mutualisé

Avant le VPS, un hébergement mutualisé suffit pour essayer le site en ligne : espace client, paiement Orange Money avec une vraie adresse, synchronisation avec l'ERP local. Ce n'est pas la cible de production (pas de pare-feu à soi, sauvegardes et performances limitées).

À vérifier avec l'hébergeur avant de commander :

| Point | Pourquoi |
|---|---|
| PHP 8.4 au choix, extensions intl, gd (JPEG et WebP), mbstring, curl, zip, pdo_mysql | Symfony 8.1 demande PHP 8.4 ; `app:verifier-installation` contrôle le reste |
| Accès SSH | composer et `bin/console` (migrations, vérification, essai Orange Money) |
| Dossier racine du domaine ou du sous-domaine réglable sur `public/` | sinon `.env.local` et le code seraient servis ; ne jamais contourner par une réécriture vers `public/` |
| HTTPS (Let's Encrypt) avec redirection forcée dans le panneau | notifications sur téléphone, paiement, connexion ; la redirection ne va pas dans `public/.htaccess`, qui sert aussi à XAMPP en local |
| Base MySQL ou MariaDB, tâches cron | données ; `app:espace:notifications` toutes les 15 minutes |
| Envoi de fichiers de 64 Mo | `public/.user.ini` le règle quand PHP tourne en FPM ou CGI ; sinon, réglage PHP du panneau |
| Appels sortants en HTTPS | API Orange SMS et Orange Money |

Installation par SSH :

```
cd ~ && git clone https://github.com/yaspi00005/successimprime-workload.git successimprim
cd successimprim
php composer.phar install --no-dev --optimize-autoloader   # ou composer, s'il est installé
```

À la fin, `composer install` lance de lui-même `cache:clear` et `assets:install` avec la mémoire par défaut de PHP en ligne de commande, souvent trop basse chez un hébergeur : il peut finir sur « Allowed memory size … exhausted » ou « Script cache:clear returned with error code 255 ». Le code est quand même installé ; lancer alors ces deux commandes à la main (voir « Mémoire des commandes » plus bas) :

```
php -d memory_limit=512M bin/console cache:clear
php -d memory_limit=512M bin/console assets:install public
```

Le domaine pointe toujours sur le dossier `public/` du projet, jamais sur sa racine. Le mieux est de ranger le projet hors de `public_html`, dans le dossier personnel. Si l'hébergeur n'accepte de racine que sous `public_html` (cas de LWS), le projet va dans `public_html/succesimprim` et le domaine pointe sur `public_html/succesimprim/public`. Une adresse qui sert `public_html` lui-même (adresse temporaire de l'hébergeur, autre domaine) atteindrait alors `.env.local` : le `.htaccess` à la racine du projet le refuse, à condition que l'hébergeur lise les `.htaccess` (Apache ou LiteSpeed). Vérifier que https://le-domaine/.env.local est introuvable.

Puis pointer le domaine sur `~/successimprim/public`, créer la base dans le panneau, écrire `.env.local` (mêmes variables que plus bas, avec `APP_ENV=prod` et `APP_EN_LIGNE=1`, droits 600) et lancer :

```
php -d memory_limit=512M bin/console cache:clear
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:verifier-installation
php bin/console app:orange-money:tester --paiement
```

`app:verifier-installation` doit afficher « Vérification du site en ligne » : sinon `APP_EN_LIGNE=1` manque, et il réclame à tort le thème de l'administration et le dossier des sauvegardes. Sans `APP_ENV=prod`, `composer install` vide le cache en mode développement, plus gourmand : écrire `.env.local` avant, ou relancer `cache:clear` comme ci-dessus. Sur certains hébergeurs, la commande `php` du terminal ou du cron n'est pas la bonne version : utiliser le chemin donné par l'hébergeur (par exemple `/usr/local/bin/php8.4` ou `ea-php84`) et le vérifier avec `php -v`. Les tâches cron sont celles de « Tâches planifiées du serveur », avec ce chemin. `public/.htaccess` gère la réécriture des adresses, la compression et le cache des fichiers statiques sous Apache ou LiteSpeed.

## Serveur

- 2 processeurs, 4 Go de mémoire, 60 Go de disque SSD au minimum. Les fichiers des clients (BAT, fichiers d'impression) prennent la place : prévoir plus si on les garde longtemps en ligne.
- Un nom de domaine (succesimprim.com) pointé sur l'adresse du serveur.
- Accès SSH par clé seulement, pare-feu ouvert sur 22, 80 et 443.

## Logiciels

```
sudo apt update && sudo apt upgrade
sudo apt install nginx mariadb-server git unzip certbot python3-certbot-nginx fail2ban ufw \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl php8.4-gd php8.4-zip \
  php8.4-curl php8.4-xml php8.4-opcache
```

L'ERP local (poste de l'imprimerie) a besoin des mêmes extensions ; **zip** sert aussi à lire les classeurs `.xlsx` de l'import des données (docs/import-donnees.md) : sans elle, l'import n'accepte que le CSV. `app:verifier-installation` le signale.

PHP 8.4 n'est pas dans Ubuntu 24.04 de base : ajouter d'abord le dépôt `ppa:ondrej/php`. GD doit savoir lire et écrire le JPEG et le WebP (c'est le cas du paquet `php8.4-gd`) ; sans cela, les images du site sont plus lourdes et les photos JPEG sont refusées.

Composer : suivre https://getcomposer.org/download/ puis `sudo mv composer.phar /usr/local/bin/composer`.

## Installation

```
sudo adduser --disabled-password succes
sudo mkdir -p /srv/successimprim && sudo chown succes: /srv/successimprim
sudo -u succes git clone https://github.com/yaspi00005/successimprime-workload.git /srv/successimprim
cd /srv/successimprim
sudo -u succes composer install --no-dev --optimize-autoloader
```

Base de données :

```
sudo mysql -e "CREATE DATABASE succesimprim CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'succes'@'localhost' IDENTIFIED BY '<mot de passe>';
  GRANT ALL ON succesimprim.* TO 'succes'@'localhost';"
```

Fichier `/srv/successimprim/.env.local` (droits 600, propriétaire `succes`). Les valeurs ne passent jamais par un message ni par le dépôt : Yaya les saisit sur le serveur.

| Variable | Valeur |
|---|---|
| APP_ENV | prod |
| APP_SECRET | 32 caractères au hasard, propres à ce serveur : `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'` |
| ID_CLE | Clé qui chiffre les numéros des fiches dans les URL : **la même** sur l'ERP local et sur le site, à ne plus changer ensuite (docs/ids-chiffres.md). `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` |
| APP_EN_LIGNE | 1 |
| DEFAULT_URI | https://succesimprim.com |
| DATABASE_URL | mysql://succes:<mot de passe>@127.0.0.1:3306/succesimprim?serverVersion=10.11-MariaDB&charset=utf8mb4 |
| SYNC_CLE | la clé de `app:synchro --nouvelle-cle`, la même que sur l'ERP local |
| ORANGE_SMS_CLIENT_ID, ORANGE_SMS_CLIENT_SECRET, ORANGE_SMS_SENDER_ADDRESS, ORANGE_SMS_SENDER_NAME | compte SMS Orange : sans lui, les clients et les employés sans adresse e-mail ne reçoivent pas leur code de connexion en ligne. L'expéditeur est celui du contrat, `tel:+2230000` au Mali, pas un numéro de téléphone ; les numéros maliens à 8 chiffres reçoivent +223 tout seuls |
| ORANGE_MONEY_CLIENT_ID, ORANGE_MONEY_CLIENT_SECRET, ORANGE_MONEY_MERCHANT_KEY, ORANGE_MONEY_API, ORANGE_MONEY_DEVISE | paiement en ligne (docs/espace-client.md). Il faut aussi allumer Réglages › Espace client › « Paiement Orange Money en ligne » à l'ERP local (coupé au départ) : sans lui, l'espace ne propose pas Orange Money |
| VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT | notifications sur le téléphone des clients (`app:push:cles`) |
| MAILER_DSN, MAILER_FROM_ADDRESS | envoi des e-mails, dont les codes de connexion des clients et du personnel. Sans l'une des deux, ces codes partent par SMS. Voir « Boîte e-mail LWS » plus bas |
| SAUVEGARDE_DIR | dossier des sauvegardes du serveur |
| ANTHROPIC_API_KEY | clé de l'API Claude (console Anthropic, « sk-ant-… ») pour l'assistant du site et de l'espace client (docs/assistant-client.md). Sans elle, l'assistant est caché |
| ASSISTANT_MODELE | facultatif : impose un modèle à l'assistant (par défaut celui de la tuile Réglages › Assistant du site) |

`SAUVEGARDE_CLE_HORS_SITE` ne va **jamais** sur le serveur en ligne : elle reste sur l'ERP local. À l'inverse, `ANTHROPIC_API_KEY` ne va que sur le serveur en ligne : l'ERP local n'en a pas besoin. `ASSISTANT_DEMO` (réponses de démonstration sans API) ne sert qu'en développement : jamais sur un serveur en service.

Pour l'assistant, le serveur doit pouvoir appeler `https://api.anthropic.com` en HTTPS. Mettre un plafond de dépense mensuel dans la console Anthropic, en plus du plafond par jour de la tuile.

### Boîte e-mail LWS (MAILER_DSN)

Les codes de connexion partent par e-mail (moins cher que le SMS) depuis une boîte de l'hébergement LWS, par exemple `contact@succesimprim.com` :

1. Dans le panneau LWS, créer la boîte (Mails › Adresses e-mail) et noter son mot de passe. Il ne va **que** dans `.env.local` du serveur en ligne : jamais dans le dépôt, un message ou une capture d'écran.
2. Dans `/srv/successimprim/.env.local` :

   ```
   MAILER_DSN=smtps://contact%40succesimprim.com:MOT_DE_PASSE_DE_LA_BOITE@mail.succesimprim.com:465
   MAILER_FROM_ADDRESS=contact@succesimprim.com
   ```

   - `smtps` et le port 465 : SMTP chiffré en SSL, comme l'indique LWS pour ses boîtes. Le serveur sortant (SMTP) est donné dans le panneau LWS ; c'est en général `mail.` suivi du domaine.
   - L'identifiant est l'adresse complète, avec `@` écrit `%40` (sinon le `@` coupe l'adresse en deux). Même chose pour un caractère spécial du mot de passe : `@` → `%40`, `:` → `%3A`, `/` → `%2F`, `#` → `%23`, `?` → `%3F`, `%` → `%25`.
   - `MAILER_FROM_ADDRESS` est l'adresse de la boîte ; le nom affiché « Succès Imprim » s'ajoute tout seul.
3. `sudo -u succes php -d memory_limit=512M bin/console cache:clear`, puis `app:verifier-installation` : la ligne « E-mail » passe à OK.
4. Essai : « Recevoir un code » sur l'espace client avec le numéro d'une fiche qui a votre adresse e-mail. Le message arrive en quelques secondes (sinon, regarder les courriers indésirables).

Si une variable manque, ou si LWS refuse l'envoi, les codes partent par SMS : `app:verifier-installation` le dit (« les codes de connexion partiront par SMS »).

Puis :

```
sudo -u succes php bin/console doctrine:migrations:migrate --no-interaction
sudo -u succes php -d memory_limit=512M bin/console cache:clear
sudo -u succes php bin/console app:verifier-installation
```

`app:verifier-installation` doit finir sans « Erreur ». Il n'affiche aucune valeur secrète.

## Nginx et HTTPS

`/etc/nginx/sites-available/succesimprim` :

```
# Une seule adresse pour Google (docs/referencement.md) : www renvoie en 301,
# chemin et paramètres gardés, comme la règle de public/.htaccess sous Apache.
server {
    server_name www.succesimprim.com;
    return 301 https://succesimprim.com$request_uri;
}

server {
    server_name succesimprim.com;
    root /srv/successimprim/public;
    client_max_body_size 64m;

    # Pages légères en 3G : texte compressé, fichiers statiques gardés 7 jours.
    gzip on;
    gzip_types text/css application/javascript image/svg+xml application/xml application/json text/plain;
    location ~* ^/(css|js|images)/ { expires 7d; access_log off; try_files $uri =404; }

    location / { try_files $uri /index.php$is_args$args; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm-succes.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }
    location ~ \.php$ { return 404; }
    # Fichiers envoyés : jamais servis comme une page du site (docs/securite.md).
    location ~* ^/uploads/.*\.(html?|xhtml|shtml|svgz?|xml|js|mjs)(\.|$) { return 404; }
}
```

Le pool PHP-FPM `succes` (`/etc/php/8.4/fpm/pool.d/succes.conf`) tourne sous l'utilisateur `succes` et écoute sur `/run/php/php8.4-fpm-succes.sock`, avec `upload_max_filesize = 64M` et `post_max_size = 64M`.

```
sudo ln -s /etc/nginx/sites-available/succesimprim /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d succesimprim.com -d www.succesimprim.com
curl -sI http://www.succesimprim.com/contact | grep -i location   # https://succesimprim.com/contact
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

## Tâches planifiées du serveur

`crontab -e` du compte `succes` :

```
0 * * * * cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
@reboot   sleep 60 && cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
*/15 * * * * cd /srv/successimprim && php bin/console app:espace:notifications -q >> var/log/espace-notifications.log 2>&1
30 3 * * * cd /srv/successimprim && php bin/console app:corbeille:purger -q >> var/log/corbeille.log 2>&1
*/5 * * * * cd /srv/successimprim && php bin/console app:alertes -q >> var/log/alertes.log 2>&1
```

`app:espace:notifications` envoie à 7 h les SMS des clients mis de côté pendant les heures calmes (21 h – 7 h) et les rappels d'échéance des factures (docs/espace-client.md). `app:corbeille:purger` supprime pour de bon, après la sauvegarde de la nuit, ce que les clients ont retiré de leur espace il y a plus de 30 jours ; `app:corbeille:restaurer` le remet en place s'ils appellent (docs/corbeille.md).

`app:alertes` vérifie l'arrivée de la synchronisation, l'espace disque et la file de messages, puis envoie les alertes système en attente par e-mail et dans l'application (docs/alertes-systeme.md). Mettre dans `.env.local` du serveur `MAILER_DSN`, `MAILER_FROM_ADDRESS` et, au besoin, `ALERTES_EMAIL` (une ou plusieurs adresses, séparées par des virgules, qui reçoivent toutes les alertes en plus de la direction) : sans messagerie, les alertes restent visibles dans Réglages › Alertes système et dans la cloche. La même tâche tourne sur l'ERP local (Windows : `scripts\windows\installer_tache_alertes.bat`).

Les autres tâches (synchronisation, notifications de l'équipe `app:notifications` chaque minute avec la synchronisation ([docs/notifications-equipe.md](notifications-equipe.md)), actions de caisse, rappels, alerte de trésorerie, rapport de la semaine, crédits SMS et e-mail, rapports par e-mail, expiration et relances des devis, alertes d'achat) tournent sur l'ERP local.

Devis (docs/devis.md) : sur l'ERP local, `app:devis:relances` chaque heure. Sous Windows : `scripts\windows\installer_tache_devis_relances.bat`. Sous Linux ou Mac, `crontab -e` :

```
0 * * * * cd /chemin/du/projet && php bin/console app:devis:relances -q >> var/log/devis-relances.log 2>&1
```

Prépresse (docs/prepresse.md) : sur l'ERP local, `app:dossier-partage:importer` chaque minute (lancée par `scripts\windows\synchro.bat`) et `app:prepresse:relances` chaque heure (lancée par `scripts\windows\devis_relances.bat`). Sous Linux ou Mac, `crontab -e` :

```
* * * * * cd /chemin/du/projet && php bin/console app:dossier-partage:importer -q >> var/log/dossier-partage.log 2>&1
5 * * * * cd /chemin/du/projet && php bin/console app:prepresse:relances -q >> var/log/prepresse-relances.log 2>&1
```

Achats (docs/achats.md, SU06) : sur l'ERP local, `app:achats:alertes` chaque heure (stock bas, hausse de prix, fournisseur en retard, commande oubliée : notifications de la direction). Sous Windows, elle est lancée par `scripts\windows\devis_relances.bat`. Sous Linux ou Mac, `crontab -e` :

```
10 * * * * cd /chemin/du/projet && php bin/console app:achats:alertes -q >> var/log/achats-alertes.log 2>&1
```

Rapports du jour, de la semaine et du mois par e-mail ([docs/rapports-mail.md](rapports-mail.md)) : sur l'ERP local, `MAILER_DSN` et `MAILER_FROM_ADDRESS` (la même boîte que ci-dessus) dans son `.env.local`, et la tâche chaque quart d'heure. Sous Windows : `scripts\windows\installer_tache_rapports_mail.bat` ; sous Linux ou Mac, `crontab -e` :

```
*/15 * * * * cd /chemin/du/projet && php bin/console app:rapports-mail -q >> var/log/rapports-mail.log 2>&1
```

Sur ce serveur en ligne, la commande refuse de tourner : ne pas l'ajouter ici.

## Brancher l'ERP local

1. Sur l'ERP local, dans `.env.local` : `SYNC_URL=https://succesimprim.com` et la même `SYNC_CLE`.
2. `php bin/console app:verifier-installation` sur l'ERP local.
3. Premier chargement : `php bin/console app:synchro --initialiser`.
4. Tâche planifiée de la synchronisation (docs/synchronisation.md, « Mise en route »). Elle lance aussi `app:messagerie:delais` (délais de réponse aux clients, escalade, clôture automatique : `docs/messagerie-clients.md`) et `app:notifications` (notifications de l'équipe : `docs/notifications-equipe.md`). `app:messagerie:delais` prévient aussi la direction d'un rappel promis sur la page Contact et pas fait 15 minutes après l'heure (docs/site-vitrine.md, « Contact, rappel, engagements et Comment venir »).

## Comptes du personnel en ligne

Les comptes du personnel ne descendent pas par la synchronisation : mots de passe et données du personnel restent à l'atelier. Seules les personnes qui ont besoin de l'administration en ligne y ont un compte, créé sur le serveur :

1. `php bin/console app:creer-utilisateur <identifiant>` (prénom, nom, téléphone, e-mail qui reçoit le code de sécurité, mot de passe ; `--email=` pour le donner d'avance, `--role=` pour un autre rôle que la direction). Sans e-mail, le code part par SMS au téléphone.
2. `php bin/console app:autoriser-appareil <identifiant> --nom="…"` donne un lien valable 5 minutes, à ouvrir sur l'appareil à autoriser. Le lien prend l'adresse de `DEFAULT_URI`. Les appareils suivants s'autorisent depuis Réglages › Sécurité › Appareils autorisés.
3. Connexion sur /login : mot de passe, puis code de sécurité envoyé à l'e-mail de l'employé (par SMS s'il n'en a pas, ou si l'e-mail n'est pas réglé). Il vaut 5 minutes ; 3 codes par personne en 15 minutes. Sans e-mail réglé ni clés Orange SMS, aucun code ne part et personne ne se connecte en ligne.

## Avant d'ouvrir au public

- [ ] `app:verifier-installation` sans erreur sur les deux machines.
- [ ] https://succesimprim.com montre l'accueil du site ; /robots.txt et /sitemap.xml répondent.
- [ ] Un membre du personnel se connecte en ligne avec son appareil autorisé et le code reçu par e-mail.
- [ ] Un client reçoit son accès par WhatsApp depuis sa fiche (ERP local), se connecte la première fois avec ce code, choisit son mot de passe et voit ses commandes.
- [ ] « Recevoir un code » envoie un e-mail à une fiche qui a une adresse, un SMS à une fiche qui n'en a pas.
- [ ] Un paiement Orange Money de test arrive « en attente » au local.
- [ ] Assistant : la clé posée (`app:verifier-installation`), la tuile Réglages › Assistant du site allumée au local et descendue en ligne ; une question sur le site reçoit une réponse, et la conversation arrive dans Clients › Assistant du site au local.
- [ ] Une sauvegarde du serveur existe et se restaure (docs/sauvegardes.md).
- [ ] Les trois commandes de « Revérifier » de docs/securite.md donnent le résultat attendu (en-têtes, fichiers introuvables, http renvoyé vers https).

## Mettre à jour

```
cd /srv/successimprim
sudo -u succes git pull
sudo -u succes composer install --no-dev --optimize-autoloader
sudo -u succes php bin/console doctrine:migrations:migrate --no-interaction
sudo -u succes php -d memory_limit=512M bin/console cache:clear
sudo -u succes php -d memory_limit=512M bin/console assets:install public
sudo -u succes php bin/console app:fichiers:deplacer --executer
sudo systemctl reload php8.4-fpm
```

Sur l'hébergement mutualisé (LWS), par SSH, une fois `git pull` et `composer install` faits dans le dossier du projet :

```
cd ~/successimprim 2>/dev/null || cd ~/public_html/succesimprim
php bin/console doctrine:migrations:migrate --no-interaction
php -d memory_limit=512M bin/console cache:clear
php -d memory_limit=512M bin/console assets:install public
php bin/console app:fichiers:deplacer --executer
php bin/console app:verifier-installation
```

`app:fichiers:deplacer --executer` range hors de `public/` les fichiers privés envoyés avant la mise à jour du 15/10/2026 (chat, pré-presse, étiquettes…, docs/securite.md). Il ne sert qu'une fois, mais se relance sans risque à chaque mise à jour : « Rien à déplacer : tout est déjà rangé. » S'il signale un conflit ou une erreur, envoyer sa sortie au développeur.

Mettre à jour l'ERP local en même temps : les deux installations doivent avoir les mêmes migrations. Au local aussi, dans le dossier du projet, après `git pull` :

```
php bin/console doctrine:migrations:migrate --no-interaction
php -d memory_limit=512M bin/console cache:clear
php bin/console app:fichiers:deplacer --executer
```

## Mémoire des commandes

Sur le serveur, `cache:clear`, `cache:warmup` et `assets:install` se lancent toujours avec `php -d memory_limit=512M bin/console …`, comme dans les blocs ci-dessus. La limite de mémoire de PHP en ligne de commande dépend de l'hébergeur (souvent 128 ou 256 Mo) et ne change pas le site lui-même.

- `cache:clear` en production prépare les plus de 500 gabarits de l'application. Jusqu'au 15/10/2026 il lui fallait plus de 256 Mo et il échouait à 256 Mo ; il compile maintenant les gabarits un par un sans les garder en mémoire (`src/Twig/GabaritsCacheWarmer.php`) et reste vers 130 Mo. Les 512 Mo laissent de la marge pour la suite.
- `composer install` lance à la fin `cache:clear`, `assets:install` et `importmap:install` avec la limite par défaut. S'il s'arrête sur « Allowed memory size … exhausted », le code est bien installé : lancer `php -d memory_limit=512M bin/console cache:clear` puis `php -d memory_limit=512M bin/console assets:install public`.
- Pour vérifier la limite du serveur : `php -r 'echo ini_get("memory_limit"), PHP_EOL;'`.

## Journaux

En production, les commandes n'affichent plus les avis « User Deprecated » (longues lignes en JSON après chaque commande) : ce sont des annonces de changements des prochaines versions de Symfony, Twig ou Doctrine, pas des erreurs. Ils vont dans `var/log/deprecations-AAAA-MM-JJ.log`, un fichier par jour, 3 jours gardés, pour préparer une mise à jour. Les vraies erreurs s'affichent toujours dans le terminal et vont dans le journal d'erreurs de PHP du serveur.

## Mise à jour du 19/10/2026 (Contact, engagements, Comment venir)

- Migration `Version20261019150000` (colonnes ajoutées à `demande_devis` et `conversation_client`, rien de supprimé), à passer sur l'ERP local **et** en ligne : `doctrine:migrations:migrate`.
- Nouvelles adresses publiques : `/comment-venir`, `/suivre-ma-reclamation`, `POST /contact/rappel`, `POST /nos-engagements/signaler`. Elles sont déjà dans `security.yaml`, rien à régler sur le serveur.
- Puis, au local : Réglages › « Site : contact et accueil » (accès en voiture, à moto, livraison, si on veut les montrer) et « Site : engagements et rappels » (écran de rappel, éteint par défaut). Lancer une synchronisation des réglages : `app:synchro --initialiser=reglages`.
- Les chiffres de « Nos engagements » ne paraissent qu'après Clients › Engagements clients › « Publier les chiffres ».

