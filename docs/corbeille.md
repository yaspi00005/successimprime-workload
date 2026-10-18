# Confirmation des suppressions et corbeille de 30 jours

Demande de Yaya du 16/10/2026 :

- chaque suppression, partout, demande une confirmation claire dans la page ;
- ce qui est supprimé reste 30 jours dans une corbeille, d'où la Direction peut le restaurer.

## Confirmer chaque suppression

Avant de supprimer, une fenêtre s'ouvre dans la page :

> **Supprimer le devis DEV-2026-0042 ?**
> Il restera 30 jours dans la corbeille.
> [Annuler] [Supprimer]

- « Annuler » a le focus à l'ouverture. Échap annule, et un clic à côté de la fenêtre aussi.
- Tab et Maj+Tab restent dans la fenêtre. Le focus revient ensuite sur le bouton qui l'a ouverte.
- Sur téléphone, la fenêtre monte du bas de l'écran, avec de grands boutons.
- Le bouton de suppression est rouge.
- Elle ne s'appuie jamais sur la boîte « confirm » du navigateur ni sur une bibliothèque d'un autre site.

Le texte suit ce qui sera fait :

| Cas | Phrase sous la question |
|---|---|
| La fiche va dans la corbeille | « Il restera 30 jours dans la corbeille. » (la durée réglée) |
| Rien n'est gardé (consommable retiré du stock, média de l'écran TV, réglage technique) | « Cette suppression est définitive. » |
| Espace client | « En cas d'erreur, appelez Succès Imprim : c'est récupérable pendant 30 jours. » (le client ne voit pas la corbeille) |
| Retrait d'une ligne pendant la saisie d'un devis, d'une commande ou d'une demande d'achat | « Elle quitte la saisie ; rien n'est enregistré avant « Enregistrer ». » |

Les actions sensibles qui ne suppriment rien, comme annuler une facture ou renvoyer un mot de passe, passent aussi par cette fenêtre. Elles ont leur propre texte et un bouton qui dit ce qu'il fait.

### Pour les développeurs

Il n'y a qu'un seul mécanisme : `public/js/confirmation.js`, chargé par les deux coques, `base.html.twig` (ERP) et `espace/app/_app.html.twig` (espace client). Sa feuille de style est `public/css/confirmation.css`.

- **Dans un gabarit**, sur le `<form>`, le bouton ou le lien :

  ```twig
  <form method="post" action="{{ path('app_devis_delete', {id: devis.id}) }}" {{ confirmer_suppression('le devis ' ~ devis.numero) }}>
  ```

  Options de `confirmer_suppression()` (`App\Twig\ConfirmationExtension`) :
  - `verbe: 'Retirer'` ;
  - `detail: '…'`, une phrase de plus ;
  - `corbeille: false`, quand rien n'est gardé ;
  - `client: true`, dans l'espace client.

  À la main, ce sont les attributs `data-confirmer` (la question), `data-confirmer-detail`, `data-confirmer-bouton` et `data-confirmer-danger`.
- **Suppression en AJAX** (catalogue, lignes de saisie) : mettre les mêmes attributs sur le bouton. Le script arrête le clic avant tout autre script (phase de capture), puis le rejoue tel quel après « Supprimer ».
- **Depuis du JavaScript** : `await window.SiConfirmation.demander({message, detail, bouton, danger})` rend `true` ou `false`.
- **Test** : `tests/Unit/Twig/ConfirmationSuppressionsTest.php` relit les gabarits et les scripts. Il fait échouer la suite dans ces cas :
  - un formulaire ou un bouton qui supprime sans `data-confirmer` ni `confirmer_suppression()` ;
  - un lien vers une route de suppression (une suppression passe toujours par POST avec jeton) ;
  - un `confirm(` ou un ancien `js-confirm-form` ;
  - une coque qui ne charge plus la fenêtre.

  Une suppression ajoutée plus tard ne peut donc pas oublier la question.
- **Scripts du thème hors du dépôt** : les pages du catalogue (supports, formats, finitions, types d'impression, catégories, configurations, produits) suppriment par `public/assets/js/traitement_*.js`, qui n'est pas dans git. Leurs boutons ont `data-confirmer` : notre fenêtre passe d'abord. Si ces scripts posent encore leur propre question, la personne voit deux confirmations de suite. On peut retirer celle du script sur la machine, mais rien ne se perd sans le faire.

## La corbeille

### Ce qui y entre

Chaque suppression faite par l'application est capturée par `App\Service\Corbeille\CaptureCorbeille`, un écouteur Doctrine :

- **onFlush** : les lignes sont encore en base. `Instantane` copie la fiche et tout ce que la base supprimera avec elle (clés étrangères en `ON DELETE CASCADE`, lues dans le schéma). Il note aussi les fiches restées en place dont le lien sera vidé (`ON DELETE SET NULL`), pour le rebrancher à la restauration.
- **postFlush** : l'enregistrement a réussi. Les copies sont écrites dans la table `corbeille`, une ligne par élément et un même `groupe` par suppression. La fiche supprimée y est la « racine ». Si l'enregistrement échoue, rien n'est écrit.

Une erreur de la corbeille n'empêche jamais une suppression. Elle est notée dans le journal de l'application.

Certaines fiches sont seulement **cachées**, sans quitter leur table : devis et commandes supprimés (`deleted`), adresse de livraison, accès d'une personne ou fichier retiré par le client (`actif`). Elles passent par `Corbeille::masquer()` (mode `masque`). La restauration leur rend leur champ.

`TypesCorbeille` règle le reste :

- `EXCLUES` : tables techniques jamais copiées. Ce sont la corbeille elle-même, le journal d'activité, la synchronisation, les notifications, les abonnements, les appareils, les jetons, les sessions et la file des messages.
- `RATTACHEES` : éléments qui n'ont de sens qu'avec leur fiche (ligne de devis, finition, contact d'un client…). Retirés seuls en modifiant la fiche, ils n'ouvrent pas d'entrée : c'est une modification, pas une suppression. Exception : ce qui porte un fichier est toujours gardé, pour que le fichier ne se perde pas et que la purge le retire. Une ligne de commande retirée avec les fichiers du client est gardée sous son nom ; une image retirée d'une page du site en la modifiant est gardée seule. Les tâches que le planning refait (avec leurs rappels WhatsApp) ne sont pas gardées.

  La « racine » d'une entrée est la fiche qui n'appartient à aucune autre ligne supprimée en même temps : le client avec ses adresses, la page du site avec ses images.
- `SENSIBLES` : colonnes **jamais copiées** (mots de passe, codes PIN, empreintes d'invitation, jetons de paiement, d'authenticité ou de suivi, secrets de pointage). À la restauration, elles sont remises vides ou à une valeur neuve tirée au hasard. L'ancien mot de passe ne revient donc pas : la personne en refait un. `TypesCorbeilleTest` vérifie que chaque colonne nommée existe vraiment, car une faute de frappe ferait copier le secret.
- `TYPES` : le nom en français (« Devis », « Client »), les colonnes du titre et le droit qu'il faut pour voir ce genre de fiche.
- `NON_RESTAURABLES` : fiches dont la suppression a eu un effet qu'une remise en base ne referait pas. C'est le consommable retiré, dont le stock a été recrédité. Elles restent visibles, mais leur restauration est refusée avec l'explication.

Un contenu binaire lourd (l'image d'origine d'une page du site, par exemple) est rangé à part, sous `var/corbeille/<groupe>/`. La table ne garde que son nom.

### Les fichiers

Les fichiers ne sont **pas effacés** à la suppression. Sont concernés :

- les fichiers des commandes et leurs aperçus ;
- les images IA ;
- les pièces jointes du chat ;
- les justificatifs ;
- l'original des images du site (ses tailles publiques sont refaites depuis la base).

Ils restent sur le disque jusqu'à la suppression définitive de l'entrée. À ce moment-là, un fichier encore utilisé par une fiche en place ou par une autre entrée reste. Seuls les fichiers sous `var/` sont retirés. Les noms viennent de la base, mais le chemin ne peut pas sortir de son dossier (`basename`, motif strict pour les justificatifs).

### L'écran (ERP local seulement)

L'écran est dans Réglages › Corbeille (`/corbeille`). Pour chaque suppression, il montre :

- l'élément et sa nature ;
- la date et la personne qui a supprimé (« Espace client : … » pour un geste du client, « Tâche automatique » sinon) ;
- le nombre d'éléments liés ;
- le jour où l'entrée part pour de bon.

On peut filtrer par type, par période (du, au) et par personne. La liste montre les 200 suppressions les plus récentes ; on filtre pour retrouver les plus anciennes.

Le détail d'une entrée montre ses champs et ce qui reviendra avec elle (adresses, accès, lignes…). Les montants y sont masqués pour qui n'est pas la Direction.

- Les champs se lisent comme ailleurs dans l'application : dates en jj/mm/aaaa, « oui » ou « non », montants avec leurs milliers séparés (70 000), noms de colonnes accentués (`TypesCorbeille::nomColonne`).
- Une fiche liée est nommée, par exemple « ONG Eau pour Tous (n° 900009) », si la personne a le droit de la voir ; sinon seulement son numéro. Si elle a disparu, le détail le dit (« n° 7, n'existe plus ») : la restauration sera refusée.
- Un lien d'une table de liaison (client visé par une promotion, produit d'une promotion) apparaît comme « lien vers une autre fiche », nommé d'après la fiche de l'autre côté, avec le même contrôle de droit.

Sur téléphone, la liste passe en fiches : le titre, la date de suppression et le jour où l'entrée part pour de bon.

- **Restaurer** remet tout en place **avec les mêmes numéros**, dans une seule transaction, fiches liées comprises, et rebranche les liens vidés (sauf s'ils ont changé depuis). La restauration est refusée, sans rien écrire, avec un message en français, dans ces cas :
  - la fiche à laquelle l'élément était rattaché n'existe plus (« Adresse de livraison « Boutique » ne peut pas revenir : client n° 7 auquel il était rattaché n'existe plus. ») ;
  - cette fiche est elle-même dans la corbeille (« Restaurez-le d'abord. ») ;
  - l'élément est déjà revenu ;
  - une autre fiche a pris son numéro.
- **Supprimer définitivement**, après confirmation, retire l'entrée et ses fichiers.

Une fiche suivie par la synchronisation et restaurée sur l'ERP est notée pour le prochain envoi au site (`JournalSynchro::noterRestauration`) : le site la retrouve.

### Qui y a accès

- La **Direction**, qui voit tout.
- Une personne qui a le droit **« Corbeille »** (`ROLE_CORBEILLE`). Ce droit se donne dans Réglages › Rôles et menus (module « Corbeille ») ou sur la fiche de la personne. Elle n'y voit que les fiches de ses autres droits : un paiement demande « Paiements », un devis « Devis », un produit ou un compte du personnel la Direction. La corbeille ne montre pas plus que les autres écrans. Une entrée qui ne lui est pas permise répond « accès refusé », même demandée par son numéro.
- **Mode « Voir »** (`ROLE_LECTURE_CORBEILLE`, la case « Voir » du module) : la personne consulte, mais ne peut ni restaurer ni supprimer pour de bon. `LectureSeuleSubscriber` refuse l'envoi avant le contrôleur.
- Toutes les adresses sont vérifiées côté serveur (`CorbeilleVoter`, `#[IsGranted]`) et chaque envoi porte un jeton CSRF. La recette des accès (`app:recette:acces`) essaie chaque compte de démonstration, un compte caisse à qui elle donne le droit, puis le mode « Voir ».

### Durée

Réglages › Paramètres › Corbeille règle la durée : 30 jours par défaut, de 1 à 365. Le réglage est envoyé au site en ligne par la synchronisation : la corbeille du site suit la même durée.

### Suppression définitive chaque nuit

`app:corbeille:purger` supprime pour de bon les entrées plus vieilles que la durée, avec leurs fichiers. Elle tourne après la sauvegarde de 02:00, pour que la sauvegarde de la nuit contienne encore ce qui part. L'option `--date=AAAA-MM-JJ` sert pour essayer.

**ERP local (Windows, XAMPP)** : lancer une fois, en administrateur, `scripts\windows\installer_tache_corbeille.bat`. La tâche « SuccessImprim_Corbeille » tourne chaque jour à 03:30. Journal : `var\log\corbeille.log`.

**Site en ligne (et Linux, Mac)** : dans `crontab -e` du compte du site :

```
30 3 * * * cd /srv/successimprim && php bin/console app:corbeille:purger -q >> var/log/corbeille.log 2>&1
```

Les devis et les commandes supprimés restent cachés en base après la purge, comme avant la corbeille : seule l'entrée de la corbeille part.

### Le journal d'activité

Chaque restauration (action « restauration ») et chaque suppression définitive (action « purge ») est notée dans le journal d'activité. L'entrée garde le libellé, le nombre d'éléments, la date de la suppression et la personne qui a fait le geste. Pour la purge de la nuit, cette personne est la tâche automatique.

### Sur le site en ligne

Ce que les clients retirent de leur espace part dans la corbeille du site en ligne :

- une adresse ;
- un fichier de « Mes fichiers » ;
- l'accès d'une personne de l'entreprise ;
- un brouillon de demande de conception.

Le fichier n'est jamais effacé, il est caché. La même tâche de nuit fait la purge.

Le site **n'a pas d'écran Corbeille** : `/corbeille` y répond « introuvable ». Pour remettre en place ce qu'un client a retiré par erreur, quand il appelle :

```
php bin/console app:corbeille:restaurer          # les 50 dernières entrées, avec leur numéro
php bin/console app:corbeille:restaurer 42       # restaure l'entrée 42 (mêmes contrôles que l'écran)
```

Un fichier retiré par le client et reçu par la synchronisation est aussi caché sur l'ERP local, au nom de « Espace client (en ligne) ». Il se restaure alors depuis l'écran de l'ERP.

### Limites connues

- Une suppression faite directement en SQL, hors de l'application, ne passe pas par la corbeille.
- Les suppressions appliquées par la synchronisation sur le site en ligne entrent aussi dans sa corbeille. Ce sont des copies de ce que l'ERP a supprimé ; elles partent à la purge.
- Retirer une finition ou un fournisseur pendant la saisie d'un formulaire n'est pas confirmé : rien n'est enregistré avant « Enregistrer ». Retirer une ligne entière l'est.

### Fichiers

- `migrations/Version20261018153700.php` : table `corbeille`.
- `src/Service/Corbeille/` : `CaptureCorbeille`, `Instantane`, `SchemaCorbeille`, `Restauration`, `Corbeille`, `TypesCorbeille`.
- `src/Controller/CorbeilleController.php`, `src/Security/CorbeilleVoter.php`.
- Commandes : `src/Command/CorbeillePurgerCommand.php`, `src/Command/CorbeilleRestaurerCommand.php`.
- Gabarits `templates/corbeille/`, styles `public/css/corbeille.css`.
- Tests :
  - `tests/Unit/Service/Corbeille/` : copie, exclusions, secrets, restauration avec ou sans fiche liée, purge et fichiers, journal, listes ;
  - `tests/Unit/Security/CorbeilleVoterTest.php` ;
  - `tests/Unit/Twig/ConfirmationSuppressionsTest.php`.
