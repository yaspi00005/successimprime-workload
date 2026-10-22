# Guide technique : page en ligne et PDF

Le guide technique (`docs/technique/guide.json`) se lit de deux façons :

- la page `docs/technique/page/index.html`, publiée comme Artifact claude.ai avec `guide.json` à côté d'elle ; elle porte la grille d'audit partagée (Confirmé, À corriger, Question, commentaire) ;
- le PDF produit par `tools/guide-technique/pdf.mjs`, avec la grille d'audit à cocher à la main.

## Tout régénérer après un changement du code

1. Régénérer le guide :

   ```
   local app:doc:technique
   ```

   ou, depuis l'hôte, dans le conteneur de recette :

   ```
   docker exec si-e2e bash -c 'cd /home/claude/successimprime-workload; . /e2e/env.sh; local app:doc:technique'
   ```

   (sans ces outils : `php bin/console app:doc:technique`).

2. Refaire le PDF :

   ```
   node tools/guide-technique/pdf.mjs
   ```

   Il écrit `/mnt/project-files/technique/guide-technique.pdf` et la copie datée `guide-technique-AAAA-MM-JJ.pdf` (date de génération du guide). Options : `--entree=<guide.json>`, `--sortie=<dossier>`. Variables : `PW` (chemin du module Playwright, sinon `playwright` puis `npm root -g`), `CHROMIUM` (exécutable). Le sommaire porte les numéros de page quand `pdftotext` est installé.

3. Republier la page : copier `docs/technique/page/index.html` et `docs/technique/guide.json` dans un même dossier, puis republier ce dossier sur la même adresse d'Artifact, avec les capacités `{db: {}, user: {scopes: ["profile"]}}` (sans `profile`, les noms des personnes qui ont répondu s'affichent « Quelqu'un »).

## Où sont les réponses de l'audit

Dans la base partagée de l'Artifact, collection `audit`, un document par point :

- identifiant du document : l'identifiant du point (`controleur:<classe>`, `entite:<classe>`, `route:<nom>`, `securite:<clé>`, `commande:<nom>`) encodé en base64url sans `=` ;
- champs : `item` (identifiant du point, en clair), `statut` (`confirme`, `a_corriger`, `question` ou `null` quand il n'y a qu'un commentaire), `commentaire`, `par` (identifiant claude.ai de la personne), `le` (date ISO).

Les identifiants ne dépendent que du nom de la chose : après une régénération, les réponses restent attachées aux mêmes points. Une réponse dont le point a disparu du code s'affiche sous « Points retirés du code » dans la vue Audit.
