# Filtres des tableaux (la petite flèche)

Demande de Yaya : « Ajouter les filtres dans les tableaux avec la petite flèche. »

Dans les listes de l'administration (ERP local), chaque titre de colonne porte une petite flèche. Un clic ouvre un menu comme dans Excel :

- **Trier** : « Trier de A à Z » / « de Z à A » pour un texte, « Du plus petit au plus grand » / « Du plus grand au plus petit » pour un montant ou un nombre, « Du plus ancien au plus récent » / « Du plus récent au plus ancien » pour une date. Un second clic sur le même tri rend l'ordre d'origine. Les cases vides restent en bas.
- **Entre** (montants, nombres) ou **Période** (dates) : un minimum et un maximum, « Du » et « Au ».
- **Rechercher…** : réduit la liste des valeurs. Entrée garde seulement les valeurs trouvées, comme dans Excel.
- **Les valeurs de la colonne à cocher**, avec leur nombre de lignes, et « Tout sélectionner ». Le compte tient compte des filtres posés sur les autres colonnes.
- **Effacer** (cette colonne) et **OK**.

Les filtres de plusieurs colonnes se combinent. Une colonne filtrée a sa flèche remplacée par un entonnoir sur fond bleu et son titre en bleu ; une colonne triée montre ↑ ou ↓. Au-dessus du tableau, une bande dit « 12 lignes sur 204 · filtré par Paiement, Travaux » avec le lien « Effacer les filtres » (filtres et tri de tout le tableau).

Les colonnes d'actions (boutons), de cases à cocher, sans titre ou sans texte (icônes, barres de niveau) n'ont pas de flèche.

## Deux façons de filtrer

| Liste | Où agit le filtre | Pourquoi |
|---|---|---|
| Commandes (`/commandes/`) | les lignes affichées | la liste n'est pas coupée en pages : toutes les commandes trouvées (étape choisie et panneau « Recherche ») sont dans le tableau (les commandes actives par défaut), docs/suivi-commandes.md |
| Devis (`/devis/`) | les lignes affichées | idem, tous les devis trouvés sont dans le tableau |
| Factures (`/factures/`) | les lignes affichées | toutes les factures sont dans le tableau ; la recherche de la page reste et se combine aux filtres |
| Paiements (`/paiements`) | **le serveur**, tous les paiements | la liste ne montre que les 100 derniers : un filtre sur ces 100 lignes aurait menti |
| Clients (`/clients`, vue Liste) | **le serveur**, tous les clients | la liste est coupée en pages de 24 |
| Articles et stock, toutes les autres listes | les lignes affichées | listes complètes |

**Dans la page** (par défaut) : rien n'est demandé au serveur, les lignes non retenues sont seulement cachées. Le menu le dit : « Agit sur les 204 lignes affichées. » (ou « seulement sur les 24 lignes de cette page » si une pagination est à côté). Le choix est retenu par page sur cet appareil (voir plus bas).

**Par le serveur** : la flèche règle un paramètre de l'adresse, et la liste se recharge. Le menu le dit : « Filtre toute la liste, pas seulement cette page. » Une valeur se choisit d'un clic (bouton rond « Tous », « Espèces »…) ; la recherche et le minimum / maximum s'appliquent avec Entrée ou « Appliquer ». Il n'y a pas de nombre à côté des valeurs (il faudrait compter toute la base). L'adresse garde le filtre : on peut la mettre en favori.

- Paiements : Client et Commande (recherche `q`, la même que la case « Rechercher »), Montant (tri, `min`, `max`), Mode (`mode`), Compte (`compte`), Date (tri, `du`, `au`), Statut (`statut`). Dès qu'un filtre est posé, la liste cherche dans tous les paiements, 500 au plus (`FiltresListePaiements`, valeurs inconnues ignorées). Les choix du filtre Compte sont les comptes qui ont reçu au moins un paiement (noms seulement, aucun solde).
- Clients : Client (recherche `q`, tri par nom), Type (`type`), CA 12 mois, Dernière commande et Solde dû (tri), Statut (`statut`), Suivi par (`suivi`). Ce sont les filtres et tris que la page avait déjà (`ListeClients`), rien de nouveau côté serveur. Les colonnes CA et Solde n'existent que pour qui a le droit de les voir.

## Droits

Les filtres n'ajoutent aucune donnée : dans la page, ils ne lisent que ce qui est affiché ; par le serveur, ils réduisent une liste que la personne a déjà le droit d'ouvrir (mêmes droits qu'avant : `ROLE_PAIEMENT_VOIR` pour les paiements, `ROLE_CLIENT` pour les clients). Aucune nouvelle adresse, aucun nouveau droit. Une colonne cachée par les droits (Payé, Reste, CA, Solde…) n'a ni flèche ni valeur. Sur les factures, la valeur filtrée de « Règlement » est le reste à payer, et seulement pour qui voit les paiements (`data-filtre-valeur`).

## Mémoire sur l'appareil

Les filtres et le tri « dans la page » sont retenus dans le `localStorage` du navigateur, par compte et par page (clé `si-filtres:<compte>:<adresse>:<tableau>`) : en revenant sur la liste, on retrouve son filtre, et la bande « N lignes sur M · Effacer les filtres » le rappelle. Sur un poste partagé, chacun retrouve les siens : la déconnexion efface les filtres retenus, et les filtres d'un autre compte sont effacés dès qu'une liste s'ouvre. Si le navigateur refuse cette mémoire (navigation privée, données bloquées), les filtres marchent mais ne sont pas retenus. Les filtres « par le serveur » sont dans l'adresse, pas dans cette mémoire.

## Clavier, téléphone, mouvement

- La flèche est un bouton : Tab pour l'atteindre, Entrée ou Espace pour ouvrir. Le curseur va dans « Rechercher… ». Tab tourne dans le menu, Échap le ferme et rend la main à la flèche. Un clic à côté ferme aussi.
- Sur téléphone (moins de 576 px), le menu monte du bas de l'écran sur un fond assombri, avec de grandes cases et de grands boutons. Le clavier ne s'ouvre pas tout seul. Les colonnes cachées sur téléphone (`si-masque-mobile`) n'ont pas de flèche.
- Les petites animations d'ouverture disparaissent si l'appareil demande moins de mouvement (`prefers-reduced-motion`). À l'impression, flèches et bande sont cachées.

## Côté code

- `public/js/filtres-tableaux.js` et `public/css/filtres-tableaux.css`, sans bibliothèque. `public/js/admin.js` les charge seulement si la page a une liste (un `<table>` avec `<thead>` et au moins deux lignes). Les adresses viennent de la balise de `admin.js` dans `templates/base.html.twig` (`data-filtres-js`, `data-filtres-css`, `data-filtres-compte`).
- Une liste est équipée si : un `<thead>` d'une seule ligne, sans case fusionnée ; un seul `<tbody>` d'au moins deux lignes, toutes du même nombre de cases (une ligne « Aucun résultat » sur toute la largeur est tolérée) ; aucune case de saisie (texte, nombre, liste…) dans le corps ; pas de tableau réordonné à la main (Sortable) ni dans une fenêtre (`.modal`). Les grilles de saisie (commande, devis), les matrices des rôles et le planning de l'année ne sont donc pas touchés.
- Pour qu'un tableau n'ait pas de flèches : `data-sans-filtres` sur le `<table>` ou un parent. Pour une colonne : `data-sans-filtre` sur son `<th>`. `data-filtre-libelle` change le nom de la colonne dans le menu.
- Valeur d'une cellule : `data-filtre-valeur` si présent, sinon le premier texte principal (lien, nom, badge), sans les petites lignes d'appoint (`small`, `.text-muted`) ni les boutons. Le type de colonne est reconnu seul : date (`jj/mm/aaaa`, avec ou sans heure), nombre (`12 500 FCFA`, `1,8 L`, `12 %`), sinon texte ; `data-filtre-type` le force.
- Liste filtrée par le serveur : `data-filtres="serveur"` sur le `<table>` (`data-filtres-tri` : nom du paramètre de tri, `tri` par défaut ; `data-filtres-tri-defaut` : le tri quand l'adresse n'en dit rien). Sur chaque `<th>` : `data-filtre-param` + `data-filtre-choix` (`{"valeur": "Libellé"}` ou `[["valeur", "Libellé"]]`), `data-filtre-recherche`, `data-filtre-tri-asc` / `data-filtre-tri-desc`, `data-filtre-min` / `data-filtre-max`, `data-filtre-type`. Une colonne sans aucun de ces attributs n'a pas de flèche. Une page n'est jamais rechargée avec le numéro de page (`page`) : un nouveau filtre repart de la page 1.
