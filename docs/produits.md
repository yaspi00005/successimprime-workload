# Produits et services

Maquettes envoyées par Yaya : `/mnt/project-files/maquettes/produits-application-3d/` (PR01 à PR04). Ce sont des écrans neufs : on garde les données et les calculs (catalogue, configurations, noms commerciaux, grilles, B2B, options et règles R01-R02, nomenclature, délais, machines, synchronisation, promotions), pas l'ancienne présentation ni les anciens appels AJAX de `/produits`.

Menu : **Ventes › Produits et services** (Admin, Commandes, Devis) et **Ventes › Prix spéciaux** (les mêmes, plus Promotions). Trois onglets relient les écrans : Produits et services, Prix spéciaux et promotions, Options partagées.

## Qui voit quoi

| Donnée | Droit |
|---|---|
| Catalogue, fiche, prix spéciaux, options (lecture) | Admin, Commandes ou Devis (prix spéciaux : aussi Promotions) |
| Modifier un produit, le dupliquer, le masquer en ligne, changer le prix d'une finition | Admin |
| Ajouter ou arrêter un prix négocié | Admin ou Promotions |
| Accorder ou refuser un prix négocié en attente | Admin (la direction) |
| **Coût de revient, marge, prix d'achat des matières, alertes de marge** | **« Marges et coûts de revient » (`ROLE_COUT_MARGE`)**. Sans ce droit, ils ne sont ni calculés ni écrits dans la page, ni dans l'export. |
| Chiffre d'affaires du produit | Statistiques (globales ou ventes) |

Rien de cela n'est envoyé au site en ligne : la liste blanche de `CatalogueSynchro` n'a aucun champ de coût ni de marge (un test le vérifie). Sur le site en ligne (`APP_EN_LIGNE=1`), toutes les écritures de ces écrans sont refusées : le catalogue se règle au local.

## PR01 — Catalogue (`/produits`)

- Indicateurs : produits actifs (dont visibles en ligne), marge moyenne (pondérée par le chiffre d'affaires du mois, sinon simple), produit le plus vendu du mois, nombre de produits sous la marge à viser. Sans le droit marges, les deux tuiles de marge sont remplacées par « Masqués en ligne ».
- Filtres par famille (catégorie) et recherche (nom, code, nom commercial).
- Colonnes : produit (avec nom commercial), catégorie, unité, prix de base (configuration active la moins chère, « dès » s'il y en a plusieurs), coût de revient, marge (barre verte, orange sous la cible, rouge sous le minimum), délai, machine, interrupteur « en ligne ».
- **Coût incomplet** : quand une matière de la nomenclature n'a pas de prix d'achat dans le stock (ou que l'encre, la vitesse ou le coût horaire de la machine manque), le coût porte le badge « Coût incomplet » ; un clic montre les prix d'achat manquants, avec le lien vers l'article du stock (pour l'Admin, qui ouvre le stock). La marge s'écrit « au plus N % », grisée : le vrai coût est plus haut. Elle n'entre pas dans la marge moyenne. L'export a une colonne « Coût complet » (Oui/Non).
- **Exporter la grille** : CSV (séparateur `;`, BOM pour Excel, protégé contre les formules). Les colonnes coût et marge n'y sont qu'avec le droit marges.
- 390 px : chaque produit devient une carte.

## PR02 — Fiche produit (`/produits/{id}`)

- Général : désignation, nom commercial, code, catégorie, unité de vente, prix de base et prix revendeur (historisés dans `PrixHistorique`), délai standard (heures), description, « En vente (comptoir) » (`publie`) et « Site public et espace client » (`visibleEnLigne`).
- Prix par configuration (prix, revendeur, marge), **paliers de prix** (voir plus bas), options du produit, coût de revient détaillé (matières du stock au coût moyen, encre, temps machine × coût horaire), coût saisi à la main en repli, production (machine, machine de secours, vitesse, nomenclature, compatibilités du comptoir), simulateur (grille du client, largeur, hauteur, quantité → prix unitaire net, palier atteint et prochain palier, prix client, coût, marge), aperçu de ce que voit le client (avec les paliers de la configuration de base), ventes du mois.
- Coût de revient : badge « Incomplet » et liste des prix d'achat manquants (lien vers l'article du stock) quand une matière n'a pas de prix ; la marge est alors écrite « au plus N % », jamais comme sûre. Le coût de revient n'est fiable que si les prix d'achat sont bien saisis dans le stock.
- Dupliquer : la copie part masquée en ligne avec le motif « copie à relire ».
- Supprimer : seulement un produit sans commande.

### Visible en ligne

`publie` sert déjà au comptoir (un produit non publié ne se vend plus). Le nouveau champ `visibleEnLigne` ne retire le produit que du site public, du calculateur et de l'espace client : il reste vendu au comptoir. `motifHorsLigne` (160 caractères) dit pourquoi ; remettre le produit en ligne vide le motif. `Produits::estEnLigne()` = actif, publié et visible en ligne. Quand une machine tombe en panne, l'atelier (Maintenance, docs/atelier-production.md) masque en ligne les produits qui n'ont qu'elle (sans machine de secours en état), avec le motif « Machine en réparation », et les remet en ligne à la résolution ; ces écrans-ci ne gèrent pas les pannes.

## PR03 — Prix spéciaux et promotions (`/produits/prix-speciaux`)

- Grilles tarifaires **Standard, Partenaire, Association et Revendeur** : nombre de clients, remise de chaque grille (Partenaire et Association : réglée dans Paramètres › Grille tarifaire ; Revendeur : remise moyenne des prix revendeur).
- **Prix négociés par client** (`prix_special`) : client, configuration, prix, fin (facultative), statut (actif, en attente, refusé, arrêté), raison, auteur, décision.
  - Garde-fous (Paramètres › Grille) : marge à viser 30 %, marge minimale 25 %, remise maximale sans accord 10 %. Un prix au-delà de la remise maximale, ou sous la marge minimale, saisi par quelqu'un d'autre que la direction, passe **en attente** et la direction est prévenue. Un seul prix valable par client et configuration (le nouveau arrête l'ancien).
  - Le prix « de la grille » qui sert aux garde-fous est le prix revendeur pour un revendeur, et le prix de base moins la remise Partenaire ou Association pour ces grilles.
  - Application : dans l'espace client (estimation et commande) et au comptoir (aperçu dès que le client et le produit sont choisis, recalculé par le serveur à l'enregistrement), le prix négocié est posé **en remise par unité** (prix de la ligne − prix négocié), comme le B2B. La facture garde le prix normal, la remise apparaît ; une promotion ne s'y ajoute pas (on garde la plus forte des deux remises). `App\Service\Catalogue\PrixSpeciaux`.
- Promotions en cours, à venir et suspendues, avec leur nombre d'utilisations (docs/promotions.md).
- Alertes : configurations dont la marge passe sous le minimum en grille revendeur (droit marges seulement).

## Paliers de prix et grilles

Demandes de Yaya : « de 1 à 100 pièces tel prix, de 101 à 200 tel prix ; à partir de 10 m² telle remise, 20 m² telle remise » et les grilles « Standard, Partenaire, Revendeur, Association ».

### Saisie (fiche produit, carte « Paliers de prix »)

Pour chaque configuration (Admin, au local seulement) :
- **par quantité** : « dès N pièces » (2 ou plus) → un **prix unitaire** (sous le prix de la configuration) ou une **remise %**. En dessous du premier palier, le prix de la configuration. La carte montre les tranches : « 1 à 100 pièces 75 F · 101 à 200 68 F · 201 à 500 62 F · 501 et plus 59 F (−22 %) » ;
- **par surface** (configurations au m² seulement) : « dès N m² de la ligne » (surface × quantité, ou surface facturée des pièces au m²) → une remise %, par exemple 10 m² → 5 %, 20 m² → 10 % ;
- 8 paliers au plus de chaque sorte ; chaque palier doit être plus avantageux que le précédent ;
- les changements vont dans l'historique des prix (champ « paliers »).

**Garde-fous** (`GardeFousPrix`, Paramètres › Grille tarifaire) : chaque palier changé est contrôlé au pire cas, palier puis la plus forte grille à remise. Coût de revient connu et complet : la marge doit rester au-dessus de la marge minimale (25 % par défaut), donc jamais sous le coût. Coût inconnu ou incomplet : la remise ne dépasse pas la remise maximale sans accord (10 %). Hors garde-fous, l'enregistrement est refusé avec la liste des paliers en cause, sauf si la case « Accord de la direction » est cochée (l'accord est noté dans l'historique des prix).

### Règle de cumul (le serveur fait foi)

1. **Prix de la ligne** : prix de la configuration (Standard). Un **revendeur (B2B)** garde son prix revendeur, **sans palier ni grille** (comportement inchangé ; les revendeurs restent exclus des promotions).
2. **Palier de volume** : le meilleur entre le palier de quantité et la remise de surface, **jamais les deux**.
3. **Grille du client** : Partenaire ou Association, remise en % sur le prix déjà remisé par le palier.
4. **Prix négocié** (`prix_special`) : s'il est plus bas, il l'emporte (on garde le plus bas, sans cumul).
5. **Promotion** : comme avant, elle se compare à cette remise et ne donne que ce qui la dépasse.

Prix net = arrondi(prix × (1 − palier %) × (1 − grille %)), posé sur la ligne en **remise par unité** (prix − prix net) : la facture garde le prix normal et montre la remise. Au comptoir, la remise posée n'est jamais en dessous de celle due au client ; l'agent peut donner plus.

Exemple (démo) : flyers A5 à 75 F, client Partenaire (−5 %), 150 pièces → palier 68 F, puis −5 % = 64,60 → **65 F**, soit 10 F de remise par pièce, 9 750 F la ligne avant promotion. Bâche à 3 500 F le m², 4 bâches de 3 × 2 m = 24 m² → −10 % (dès 20 m²), puis −5 % = **2 993 F le m²**, 71 832 F.

### Où le calcul s'applique

- Calcul : `App\Service\Catalogue\PaliersPrix` (statique, testé) et `TarifClient` (grille du client, prix négocié, application aux lignes) ; `public/js/paliers-prix.js` refait le même calcul pour les aperçus.
- **Comptoir, commande et devis** : la configuration de la ligne est retrouvée par produit, type d'impression, support et format ; l'aperçu pose la remise due (et écrit le palier atteint et le prochain sous la remise) tant que l'agent ne l'a pas changée ; à l'enregistrement, `TarifClient::appliquerCommande` / `appliquerDevis` la remet au moins à ce niveau, avant les promotions. Une commande facturée et une ligne au total d'origine conservé ne bougent pas.
- **Espace client** (nouvelle commande, estimation et enregistrement) : `NouvelleCommande::construireLigne` pose le palier ; `appliquerPrixSpecial` ajoute la grille et le prix négocié. Le récapitulatif montre la ligne « Palier dès … » ou « Votre remise ».
- **Calculateur du site public** : même ligne de commande (prix Standard, sans grille) ; l'estimation montre « Prix dégressif » et la remise en moins.
- **Mes produits** (produits du client) et l'**Atelier 3D** gardent leurs propres paliers (`ProduitClient`, Réglages › Atelier 3D) : ils ne se cumulent pas avec ceux de la configuration.

### Grilles

| Grille | Prix | Choix |
|---|---|---|
| Standard | prix de la configuration, avec ses paliers | par défaut |
| Partenaire | palier puis **−5 %** (réglable) | fiche client |
| Association | palier puis **−10 %** (réglable) | fiche client (ONG, associations, écoles) |
| Revendeur | prix revendeur de la configuration, sans palier ni grille | fiche client (gens du métier) |

Les remises Partenaire et Association se règlent dans **Paramètres › Grille tarifaire** (0 à 50 %). Une grille autre que Standard choisie par quelqu'un qui n'est pas de la direction attend son accord (« Demande à valider », comme le Revendeur). Le client Partenaire ou Association reste un client au prix public pour la fidélité et les promotions (pas de cumul avec la remise de grille : la plus forte).

## PR04 — Options partagées (`/produits/options`)

Matières (supports), finitions, délais (Standard, Express), services (création graphique, livraison). Chaque option dit sur combien de produits elle porte, et « Où est utilisée… » liste ces produits. Changer le prix d'une finition (Admin) le change sur toutes les configurations qui l'utilisent, avec l'historique des prix. Les matières liées au stock montrent prix d'achat et coût unitaire avec le droit marges seulement.

## Calculs

`App\Service\Catalogue\CoutProduit` :
- coût = Σ matières (quantité de la nomenclature × coût moyen du stock) + encre (au m²) + machine (coût horaire ÷ vitesse) ; si rien ne se calcule, le coût saisi sur la fiche ;
- marge = arrondi((prix − coût) ÷ prix × 100) ;
- niveau : bon à partir de la cible, à surveiller entre le minimum et la cible, alerte dessous.

## Données

Migration `Version20261018171820` : table `prix_special` ; colonnes `produits.delai_heures`, `cout_revient_saisi`, `visible_en_ligne` (1 par défaut), `motif_hors_ligne`, `machine_id`, `machine_secours_id`.

Migration `Version20261018230000` : colonnes `produit_configuration.paliers_quantite` et `remises_surface` (JSON), `clients.grille_tarifaire` (`partenaire` ou `association`).

Synchronisation : les produits emportent `visibleEnLigne` et `delaiHeures` ; les configurations leurs paliers (`paliersQuantite`, `remisesSurface`) ; les clients leur `grilleTarifaire` ; les remises des grilles Partenaire et Association partent avec les réglages publics ; `prix_special` descend au site (prix, fin, statut, client, configuration). Restent au local : la raison, les auteurs, la décision, le coût saisi, les machines.

## Démo

`app:demo` (`ProduitsDemo`) : délai, machine et machine de secours de chaque produit fictif ; le polo masqué en ligne (« Machine en réparation », la presse de sublimation étant en panne dans la démo de l'atelier) ; six prix négociés (Hôtel Lumière : bâche et cartes ; Garage Sacko : t-shirt ; ONG Nutrition Enfance : Akylux ; Clinique Kayes : vinyle **en attente** ; Boulangerie Ballo : flyers **échu**). Paliers : flyers (101 → 68 F, 201 → 62 F, 501 → −22 %), cartes de visite (201 → 45 F, 501 → −15 %), bâche (−5 % dès 20 pièces ; −5 % dès 10 m², −10 % dès 20 m², −15 % dès 50 m²), vinyle (−5 % dès 10 m², −10 % dès 20 m²). Grilles : Cabinet Faso Partenaire, ONG Eau pour Tous (Bamako et Kati) Association. Demo.kadiatou (direction) voit les marges ; demo.aminata (commerciale) ne les voit pas.
