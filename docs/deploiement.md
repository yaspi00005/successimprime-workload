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

Le domaine pointe toujours sur le dossier `public/` du projet, jamais sur sa racine. Le mieux est de ranger le projet hors de `public_html`, dans le dossier personnel. Si l'hébergeur n'accepte de racine que sous `public_html` (cas de LWS), le projet va dans `public_html/succesimprim` et le domaine pointe sur `public_html/succesimprim/public`. Une adresse qui sert `public_html` lui-même (adresse temporaire de l'hébergeur, autre domaine) atteindrait alors `.env.local` : le `.htaccess` à la racine du projet le refuse, à condition que l'hébergeur lise les `.htaccess` (Apache ou LiteSpeed). Vérifier que https://le-domaine/.env.local est introuvable.

Puis pointer le domaine sur `~/successimprim/public`, créer la base dans le panneau, écrire `.env.local` (mêmes variables que plus bas, avec `APP_ENV=prod` et `APP_EN_LIGNE=1`, droits 600) et lancer :

```
php -d memory_limit=512M bin/console cache:clear
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:verifier-installation
php bin/console app:orange-money:tester --paiement
```

`app:verifier-installation` doit afficher « Vérification du site en ligne » : sinon `APP_EN_LIGNE=1` manque, et il réclame à tort le thème de l'administration et le dossier des sauvegardes. Sans `APP_ENV=prod`, `composer install` vide le cache en mode développement, qui dépasse 256 Mo de mémoire : écrire `.env.local` avant, ou relancer `cache:clear` comme ci-dessus. Sur certains hébergeurs, la commande `php` du terminal ou du cron n'est pas la bonne version : utiliser le chemin donné par l'hébergeur (par exemple `/usr/local/bin/php8.4` ou `ea-php84`) et le vérifier avec `php -v`. Les tâches cron sont celles de « Tâches planifiées du serveur », avec ce chemin. `public/.htaccess` gère la réécriture des adresses, la compression et le cache des fichiers statiques sous Apache ou LiteSpeed.

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

L'ERP local (poste de la boutique) a besoin des mêmes extensions ; **zip** sert aussi à lire les classeurs `.xlsx` de l'import des données (docs/import-donnees.md) : sans elle, l'import n'accepte que le CSV. `app:verifier-installation` le signale.

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
| APP_EN_LIGNE | 1 |
| DEFAULT_URI | https://succesimprim.com |
| DATABASE_URL | mysql://succes:<mot de passe>@127.0.0.1:3306/succesimprim?serverVersion=10.11-MariaDB&charset=utf8mb4 |
| SYNC_CLE | la clé de `app:synchro --nouvelle-cle`, la même que sur l'ERP local |
| ORANGE_SMS_CLIENT_ID, ORANGE_SMS_CLIENT_SECRET, ORANGE_SMS_SENDER_ADDRESS, ORANGE_SMS_SENDER_NAME | compte SMS Orange : sans lui, le personnel ne reçoit pas son code de connexion en ligne |
| ORANGE_MONEY_CLIENT_ID, ORANGE_MONEY_CLIENT_SECRET, ORANGE_MONEY_MERCHANT_KEY, ORANGE_MONEY_API, ORANGE_MONEY_DEVISE | paiement en ligne (docs/espace-client.md) |
| VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT | notifications sur le téléphone des clients (`app:push:cles`) |
| MAILER_DSN, MAILER_FROM_ADDRESS | envoi des e-mails |
| SAUVEGARDE_DIR | dossier des sauvegardes du serveur |
| ANTHROPIC_API_KEY | clé de l'API Claude (console Anthropic, « sk-ant-… ») pour l'assistant du site et de l'espace client (docs/assistant-client.md). Sans elle, l'assistant est caché |
| ASSISTANT_MODELE | facultatif : impose un modèle à l'assistant (par défaut celui de la tuile Réglages › Assistant du site) |

`SAUVEGARDE_CLE_HORS_SITE` ne va **jamais** sur le serveur en ligne : elle reste sur l'ERP local. À l'inverse, `ANTHROPIC_API_KEY` ne va que sur le serveur en ligne : l'ERP local n'en a pas besoin. `ASSISTANT_DEMO` (réponses de démonstration sans API) ne sert qu'en développement : jamais sur un serveur en service.

Pour l'assistant, le serveur doit pouvoir appeler `https://api.anthropic.com` en HTTPS. Mettre un plafond de dépense mensuel dans la console Anthropic, en plus du plafond par jour de la tuile.

Puis :

```
sudo -u succes php bin/console doctrine:migrations:migrate --no-interaction
sudo -u succes php bin/console cache:clear
sudo -u succes php bin/console app:verifier-installation
```

`app:verifier-installation` doit finir sans « Erreur ». Il n'affiche aucune valeur secrète.

## Nginx et HTTPS

`/etc/nginx/sites-available/succesimprim` :

```
server {
    server_name succesimprim.com www.succesimprim.com;
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
}
```

Le pool PHP-FPM `succes` (`/etc/php/8.4/fpm/pool.d/succes.conf`) tourne sous l'utilisateur `succes` et écoute sur `/run/php/php8.4-fpm-succes.sock`, avec `upload_max_filesize = 64M` et `post_max_size = 64M`.

```
sudo ln -s /etc/nginx/sites-available/succesimprim /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d succesimprim.com -d www.succesimprim.com
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

## Tâches planifiées du serveur

`crontab -e` du compte `succes` :

```
0 * * * * cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
@reboot   sleep 60 && cd /srv/successimprim && php bin/console app:sauvegarde --auto -q >> var/log/sauvegarde.log 2>&1
*/15 * * * * cd /srv/successimprim && php bin/console app:espace:notifications -q >> var/log/espace-notifications.log 2>&1
```

`app:espace:notifications` envoie à 7 h les SMS des clients mis de côté pendant les heures calmes (21 h – 7 h) et les rappels d'échéance des factures (docs/espace-client.md).

Les autres tâches (synchronisation, actions de caisse, rappels, alerte de trésorerie, rapport de la semaine) tournent sur l'ERP local.

## Brancher l'ERP local

1. Sur l'ERP local, dans `.env.local` : `SYNC_URL=https://succesimprim.com` et la même `SYNC_CLE`.
2. `php bin/console app:verifier-installation` sur l'ERP local.
3. Premier chargement : `php bin/console app:synchro --initialiser`.
4. Tâche planifiée de la synchronisation (docs/synchronisation.md, « Mise en route »).

## Avant d'ouvrir au public

- [ ] `app:verifier-installation` sans erreur sur les deux machines.
- [ ] https://succesimprim.com montre l'accueil du site ; /robots.txt et /sitemap.xml répondent.
- [ ] Un membre du personnel se connecte en ligne avec son appareil autorisé et le code SMS.
- [ ] Un client se connecte à son espace et voit ses commandes.
- [ ] Un paiement Orange Money de test arrive « en attente » au local.
- [ ] Assistant : la clé posée (`app:verifier-installation`), la tuile Réglages › Assistant du site allumée au local et descendue en ligne ; une question sur le site reçoit une réponse, et la conversation arrive dans Clients › Assistant du site au local.
- [ ] Une sauvegarde du serveur existe et se restaure (docs/sauvegardes.md).

## Mettre à jour

```
cd /srv/successimprim
sudo -u succes git pull
sudo -u succes composer install --no-dev --optimize-autoloader
sudo -u succes php bin/console doctrine:migrations:migrate --no-interaction
sudo -u succes php bin/console cache:clear
sudo systemctl reload php8.4-fpm
```

Mettre à jour l'ERP local en même temps : les deux installations doivent avoir les mêmes migrations.
