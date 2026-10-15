# Dépôt du code en privé

Le dépôt `yaspi00005/successimprime-workload` est public : n'importe qui peut lire le code et son historique (constat D1 de l'audit de sécurité du 15/10/2026). Une fois le dépôt passé en privé, `git pull` demande une autorisation. Le serveur en ligne la reçoit sous forme de **clé d'accès en lecture seule** (« deploy key ») : elle ne permet que de lire ce dépôt, rien d'autre sur le compte GitHub.

Ordre à suivre : préparer la clé du serveur (étapes 1 à 3) **avant** de passer le dépôt en privé (étape 4). La clé marche déjà sur un dépôt public, ce qui permet de vérifier sans rien couper.

Une clé privée ne s'envoie jamais dans une conversation, un e-mail ou une capture : seule la ligne publique (`….pub`, qui commence par `ssh-ed25519`) est collée dans GitHub.

## Avant le premier `git pull` : mettre de côté les fichiers envoyés

Le 15/10/2026, 25 fichiers envoyés enregistrés par erreur dans le dépôt (pièce jointe du chat, fichiers de pré-presse, étiquettes) en ont été retirés (constat D2 de l'audit) ; `public/uploads/` n'est plus suivi, sauf son `.htaccess` et le dossier de l'écran TV. Un `git pull` efface ces 25 fichiers de l'ordinateur qui le fait. Pour les garder, sur **chaque** installation (serveur en ligne, Mac, poste de la boutique), une seule fois, dans le dossier du projet :

```
cp -a public/uploads ~/uploads-avant-retrait
git pull
cp -an ~/uploads-avant-retrait/. public/uploads/
```

La dernière ligne remet les fichiers effacés sans écraser les autres. Puis `php bin/console app:fichiers:deplacer --executer` les range hors de `public/` (docs/securite.md, « Fichiers envoyés »). Les fichiers envoyés ensuite restent sur la machine : Git ne les touche plus. Quand tout marche, `~/uploads-avant-retrait` peut être supprimé.

## 1. Créer la clé sur le serveur

Dans le terminal SSH du serveur (LWS ou VPS) :

```
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -N "" -C "serveur-succesimprim" -f ~/.ssh/succesimprim_depot
cat ~/.ssh/succesimprim_depot.pub
```

La dernière commande affiche une ligne qui commence par `ssh-ed25519`. La copier.

## 2. Ajouter la clé dans GitHub

Sur github.com, dépôt `successimprime-workload` › **Settings** › **Deploy keys** › **Add deploy key** :

- Title : `Serveur en ligne`
- Key : coller la ligne `ssh-ed25519 …`
- **Allow write access** : laisser décoché (le serveur ne fait que lire).

Puis **Add key**.

## 3. Brancher le serveur sur la clé

Toujours dans le terminal SSH du serveur. Le passage par le port 443 évite les hébergeurs qui bloquent le port SSH habituel (22) vers l'extérieur.

```
printf '\nHost github-succesimprim\n  HostName ssh.github.com\n  Port 443\n  User git\n  IdentityFile ~/.ssh/succesimprim_depot\n  IdentitiesOnly yes\n' >> ~/.ssh/config
chmod 600 ~/.ssh/config
ssh-keyscan -p 443 ssh.github.com 2>/dev/null | ssh-keygen -lf - | grep ED25519
```

La dernière ligne doit contenir `SHA256:+DiY3wvvV6TuJJhbpZisF/zLDA0zPMSvHdkr4UvCOqU` (empreinte publiée par GitHub). Si c'est bien le cas :

```
ssh-keyscan -p 443 ssh.github.com 2>/dev/null >> ~/.ssh/known_hosts
ssh -T github-succesimprim
```

Réponse attendue : `Hi yaspi00005/successimprime-workload! You've successfully authenticated, but GitHub does not provide shell access.`

Puis, dans le dossier du site :

```
cd ~/successimprim 2>/dev/null || cd ~/public_html/succesimprim
git remote set-url origin github-succesimprim:yaspi00005/successimprime-workload.git
git fetch origin && echo "Clé du serveur : OK"
```

## 4. Passer le dépôt en privé

Sur github.com, dépôt `successimprime-workload` › **Settings** › **General**, tout en bas, **Danger Zone** › **Change repository visibility** › **Change to private**, puis confirmer.

Vérifier ensuite sur le serveur :

```
cd ~/successimprim 2>/dev/null || cd ~/public_html/succesimprim
git fetch origin && echo "Dépôt privé, serveur : OK"
```

## Les autres ordinateurs (Mac, poste de la boutique)

Sur un ordinateur où Git est installé avec son gestionnaire de connexion (Git pour Windows, GitHub Desktop, Git sur Mac avec Xcode récent), le premier `git pull` après le passage en privé ouvre une fenêtre de connexion GitHub : se connecter avec le compte `yaspi00005`. Si une erreur `Repository not found` ou `Authentication failed` apparaît à la place, faire les étapes 1 à 3 sur cet ordinateur avec un autre nom de clé (par exemple `Mac de Yaya` comme titre dans GitHub). Une clé ne sert que sur une seule machine.

## À savoir

- Les vérifications automatiques de GitHub (tests à chaque envoi) restent actives sur un dépôt privé ; elles consomment alors le quota gratuit mensuel du compte (2 000 minutes par mois sur l'offre gratuite).
- Pour retirer l'accès d'un serveur (serveur rendu, clé perdue) : **Settings** › **Deploy keys** › **Delete** sur sa ligne.
- Repasser le dépôt en public ne remet pas en cause les clés ; elles continuent de marcher.
