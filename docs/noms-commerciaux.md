# Nom commercial des produits

Demande de Yaya le 16/10/2026 : « Au niveau des produits, pour la désignation, le nom est un peu technique. Je préfère qu'on ajoute un nom commercial, un nom qui est connu de tous. Par exemple vinyle adhésif, d'où on dit autocollant. On garde vinyle adhésif, mais on met un autre champ avec plutôt le nom commercial autocollant. Au moment de l'affichage, on affiche les deux. À la recherche, on pourra trouver les vinyles, on pourra trouver autocollants. »

## Principe

- La désignation technique ne change pas : « Vinyle adhésif » reste le nom du produit, celui des fiches, des prix et de l'historique.
- À côté, un **nom commercial** facultatif : le nom que tout le monde connaît (« Autocollant »). Vide : il n'y en a pas, rien ne change à l'écran.
- 120 caractères au plus. La saisie est nettoyée (espaces en trop, balises et caractères invisibles retirés).

## Ce qui en a un

| Élément | Pourquoi |
|---|---|
| Produits (Bâche imprimée, Vinyle adhésif…) | C'est ce que le client choisit sur le site, dans l'espace client, et ce qui figure sur les devis, commandes et factures. |
| Supports et matières (Micro-perforé, Akylux…) | Le client les choisit avec le produit (« Vitrophanie » pour micro-perforé). |
| Types d'impression (Solvant grand format, DTF…) | Choisis avec le produit ; « Impression extérieure » parle plus que « solvant ». |
| Articles de stock vendus tels quels (case « vendable ») | Ils sont vendus au comptoir et figurent sur les devis et factures. Ils restent à l'ERP local : sur le site en ligne (espace client), une ligne de vente d'article montre sa désignation seule, l'article n'y étant pas envoyé. |

Pas de nom commercial pour :

- les **formats** (A5, 85 × 55, Sur mesure) et les **finitions** (œillets, ourlet, pelliculage) : leurs noms sont déjà ceux de tous les jours ;
- les **configurations** du site (produit · support · format) : elles se composent des noms de leur produit et de leur support, qui portent déjà le leur ;
- les **produits du client** (« Mes produits ») : leur nom est déjà celui du client (« Bâche façade Awa Mode ») ; l'espace client montre en dessous les deux noms du produit du catalogue ;
- les **catégories** (Grand format, Textile) : ce sont déjà des noms de rayon.

## Où le saisir

- **Paramètres › Produits et options › Noms commerciaux** (`/produits/noms-commerciaux`, direction seulement, comme le reste du catalogue) : une page pour tout le catalogue, en quatre rubriques (produits, supports et matières, types d'impression, articles vendus). Une ligne par élément : la désignation, le champ « Nom commercial » et « Vu par le client » (l'aperçu, mis à jour pendant la saisie). Une case « Chercher » filtre la page sans tenir compte des accents ; un compteur dit combien de noms sont remplis par rubrique. Seuls les noms modifiés sont envoyés ; un nom vidé est retiré. Quitter la page avec des changements non enregistrés demande une confirmation.
- Les listes des produits, des supports et des types d'impression ont un bouton « Noms commerciaux » qui ouvre la rubrique ; la fenêtre « Nouveau produit » / « Modifier » rappelle où le saisir.
- **Fiche article** (Stock › Articles › Modifier, et l'ancien formulaire des articles) : champ « Nom commercial » sous la désignation.
- **Import** (docs/import-donnees.md) : colonne facultative « Nom commercial » (aussi reconnue sous « nom usuel », « appellation », « nom courant », « nom connu ») pour les articles du catalogue et les articles de stock. Pour un produit déjà au catalogue, un nom commercial donné remplace l'ancien (une case vide le garde) ; l'annulation de l'import le remet.
- Sur le site en ligne, la page existe aussi pour la direction, mais les noms y sont remplacés à la synchronisation : un bandeau le dit. Ils se saisissent à la boutique.

## Affichage

Sans nom commercial, ou quand il redit la désignation (« Bâche » pour « bache »), seule la désignation s'affiche : jamais de parenthèses vides.

**Côté client** : le nom commercial d'abord, la désignation à côté, plus petite et grise (« **Autocollant** Vinyle adhésif »).

- Site vitrine : cartes de « Nos produits » (et `alternateName` dans les données structurées), liste du calculateur et de la demande de devis (« Autocollant (Vinyle adhésif) · Vinyle adhésif blanc · Sur mesure » : une liste déroulante n'a qu'un texte).
- Espace client : commandes (titre des lignes, support), devis, factures, tableau de bord, fil d'activité, BAT, recherche, Mes produits, Mes créations (le produit de la création de l'Atelier 3D).
- Atelier 3D (site et espace client) : nom de l'article (« T-shirt imprimé (T-shirt personnalisé DTF) »), le nom commercial seul sur la maquette.
- Documents : devis PDF, facture PDF, bon de livraison imprimé, relevés du client (commandes, impayés).

**Côté ERP** : la désignation d'abord, le nom commercial en petit gris à côté : listes des produits, supports, types d'impression, configurations, grille tarifaire, articles et stock ; lignes des devis, commandes, factures, bons de livraison, livraisons, production (liste, aperçu, planning des machines, étiquette), prépresse, file de travail, caisse et écrans du livreur (tournée, remise, mes livraisons, suivi), créations de l'Atelier 3D ; listes de choix de la saisie (« Vinyle adhésif (Autocollant) »), y compris la liste « Configuration » (« Vinyle adhésif (Autocollant) — Solvant grand format (Impression extérieure) — Vinyle adhésif blanc — Sur mesure »).

**Lignes des documents** : la désignation enregistrée sur la ligne reste la référence (elle ne change pas quand le catalogue change). Le nom commercial du produit vendu (ou de l'article pour une vente d'article) s'affiche devant au moment de l'affichage. Une désignation qui contient déjà le nom commercial (« Autocollants vitrine », saisie à la main ou commande en ligne) est montrée seule ; « Vinyle adhésif (Autocollant) » devient « Autocollant · Vinyle adhésif ». Dans le formulaire du devis, choisir un produit écrit sa désignation technique seule dans la ligne.

Conséquence : un document déjà émis, réimprimé après un changement de nom commercial, montre le nouveau nom commercial ; sa désignation, ses quantités et ses prix ne changent pas.

## Recherche

Les deux noms sont cherchés, sans tenir compte des accents ni des majuscules : « autocollant » trouve « Vinyle adhésif », « vinyle » le trouve toujours. Là où la recherche se fait dans la page ou en PHP, le pluriel aussi est ignoré (« autocollants », « panneaux »).

| Où | Comment |
|---|---|
| Saisie des devis et des commandes : listes des produits, configurations, supports, types d'impression et articles | Libellé « Vinyle adhésif (Autocollant) » ; la recherche de la liste ignore accents, majuscules et pluriel. |
| Liste des produits (case de recherche et filtre de la colonne Nom) | Les deux noms. |
| Supports, types d'impression | Case de recherche de la liste (elle ne faisait rien jusqu'ici). |
| Articles, Stock › Articles, état du stock | Code, désignation ou nom commercial ; l'export Excel du stock a une colonne « Nom commercial ». |
| Livraisons, production | La recherche par produit trouve aussi son nom commercial. |
| Filtres de colonne des tableaux (`filtres-tableaux.js`) | La liste des valeurs se filtre sans accents ni majuscules. |
| Site : « Nos produits » | Case « Chercher un produit » (`?q=`, sans JavaScript) : les cartes dont un des noms, ou l'univers, contient le texte. |
| Espace client : recherche du haut | Les commandes et devis dont une ligne porte le texte dans sa désignation ou dans un des noms de ce qu'elle vend. |
| Espace client : Mes produits | Case de recherche : nom, référence, les deux noms du produit et du support, catégorie. |
| API (`GET /api/v1/produits?q=`, `GET /api/v1/stock?q=`) | Le nom commercial est cherché et rendu (`nomCommercial`). |
| Assistant (site et espace client) | « chercher_produits » lit le libellé « Autocollant (Vinyle adhésif) · … » : il trouve l'un ou l'autre et répond avec les deux. |

Les recherches faites par la base (API, articles, stock, livraisons, production) s'appuient sur l'interclassement `utf8mb4_general_ci`, qui ignore déjà accents et majuscules.

## Synchronisation

Le nom commercial des produits, des supports et des types d'impression part vers le site avec eux (champ `nomCommercial` de la liste blanche, `CatalogueSynchro`), dans le même sens : le local est maître, le site reçoit et remplace. Les articles de stock ne partent pas. Après le déploiement, pour envoyer les noms déjà saisis : `php bin/console app:synchro --initialiser=type_impression`, puis `support` et `produit`, sur le local.

## Données de démonstration

`app:demo` charge quelques paires : Bâche imprimée → Banderole, Vinyle adhésif → Autocollant, Micro-perforé vitrine → Vitrophanie, Panneau Akylux → Panneau publicitaire, T-shirt personnalisé DTF → T-shirt imprimé ; supports Micro-perforé → Vitrophanie, Bâche 440 g roll-up → Toile de roll-up ; types Solvant grand format → Impression extérieure, Éco-solvant intérieur → Impression intérieure, DTF textile → Impression sur textile ; articles vinyle, micro-perforé et structure roll-up.

## Écran « Nouvelle commande » de l'espace client

Branché le 18/10/2026 (`PageNouvelleCommande`) :

- étape 1, cartes des produits : le nom commercial en gras, la désignation dessous en plus petit (« Banderole » puis « Bâche imprimée ») ; le dessin du produit est choisi d'après les deux noms ;
- étape 2, liste des matières et récapitulatif « Votre commande » : le support sous son nom commercial, la désignation entre parenthèses (« Vitrophanie (Micro-perforé) ») ; sans nom commercial, la désignation seule ;
- la ligne de la commande garde la désignation technique (`GrilleTarifaire::libelle`), comme au comptoir.

Pour d'autres écrans : `NomCommercial::configuration($configuration)` rend `['nom' => 'Autocollant · Vinyle adhésif blanc · Sur mesure', 'technique' => 'Vinyle adhésif', 'texte' => 'Autocollant (Vinyle adhésif) · …']` ; en Twig, `configuration|configuration_client` donne le HTML (nom commercial, désignation en petit) ; `produit|nom_client`, `support|nom_client` pour un élément seul, `NomCommercial::clientDe($element)` en PHP ; pour une recherche dans la page, `data-recherche="{{ cle_recherche(produit.nom, produit.nomCommercial, support.nom, support.nomCommercial) }}"`, comparé à la même clé calculée en JavaScript (fonction `cle` de `public/js/recherche-catalogue.js`) ; côté serveur, `NomCommercial::correspond($texte, $nom1, $nom2…)`.

## Dans le code

- Migration `Version20261018143100` : colonne `nom_commercial` (VARCHAR 120, NULL) dans `produits`, `supports`, `types_impression` et `articles`.
- Interface `App\Entity\AUnNomCommercial` (`getNomCommercial`, `setNomCommercial`, `getNomTechnique`) et trait `NomCommercialTrait`.
- `App\Service\Catalogue\NomCommercial` : nettoyage, affichage client et ERP, lignes de documents, configurations, clé de recherche (minuscules, sans accents, ni ponctuation, ni pluriel) ; tout est statique et testé sans base.
- Filtres Twig (`App\Twig\NomCommercialExtension`) : `nom_client`, `nom_interne`, `texte_client`, `texte_interne`, `ligne_client`, `ligne_interne`, `texte_ligne`, `texte_ligne_interne`, `titre_ligne_client`, `configuration_client` ; fonction `cle_recherche`. Classes CSS : `nc-nom`, `nc-technique` (client), `nc-commercial` (ERP).
- Page de saisie : `NomsCommerciauxController` (`app_produits_noms_commerciaux`, GET et POST, `ROLE_ADMIN`, jeton CSRF), gabarit `produits/noms_commerciaux.html.twig`, `public/js/noms-commerciaux.js`, `public/css/noms-commerciaux.css`. La recette des accès vérifie que l'envoi est refusé hors de la direction.
