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
- **Exporter la grille** : CSV (séparateur `;`, BOM pour Excel, protégé contre les formules). Les colonnes coût et marge n'y sont qu'avec le droit marges.
- 390 px : chaque produit devient une carte.

## PR02 — Fiche produit (`/produits/{id}`)

- Général : désignation, nom commercial, code, catégorie, unité de vente, prix de base et prix revendeur (historisés dans `PrixHistorique`), délai standard (heures), description, « En vente (comptoir) » (`publie`) et « Site public et espace client » (`visibleEnLigne`).
- Prix par configuration (prix, revendeur, marge), options du produit, coût de revient détaillé (matières du stock au coût moyen, encre, temps machine × coût horaire), coût saisi à la main en repli, production (machine, machine de secours, vitesse, nomenclature, compatibilités du comptoir), simulateur (largeur, hauteur, quantité → prix client, coût, marge), aperçu de ce que voit le client, ventes du mois.
- Dupliquer : la copie part masquée en ligne avec le motif « copie à relire ».
- Supprimer : seulement un produit sans commande.

### Visible en ligne

`publie` sert déjà au comptoir (un produit non publié ne se vend plus). Le nouveau champ `visibleEnLigne` ne retire le produit que du site public, du calculateur et de l'espace client : il reste vendu au comptoir. `motifHorsLigne` (160 caractères) dit pourquoi ; remettre le produit en ligne vide le motif. `Produits::estEnLigne()` = actif, publié et visible en ligne. Quand une machine tombe en panne, l'atelier (Maintenance, docs/atelier-production.md) masque en ligne les produits qui n'ont qu'elle (sans machine de secours en état), avec le motif « Machine en réparation », et les remet en ligne à la résolution ; ces écrans-ci ne gèrent pas les pannes.

## PR03 — Prix spéciaux et promotions (`/produits/prix-speciaux`)

- Grilles tarifaires Standard et Revendeur : nombre de clients, remise moyenne revendeur.
- **Prix négociés par client** (`prix_special`) : client, configuration, prix, fin (facultative), statut (actif, en attente, refusé, arrêté), raison, auteur, décision.
  - Garde-fous (Paramètres › Grille) : marge à viser 30 %, marge minimale 25 %, remise maximale sans accord 10 %. Un prix au-delà de la remise maximale, ou sous la marge minimale, saisi par quelqu'un d'autre que la direction, passe **en attente** et la direction est prévenue. Un seul prix valable par client et configuration (le nouveau arrête l'ancien).
  - Application : dans l'espace client (estimation et commande) et au comptoir dès que le client est choisi, le prix négocié est posé **en remise par unité** (prix de la ligne − prix négocié), comme le B2B. La facture garde le prix normal, la remise apparaît ; une promotion ne s'y ajoute pas (on garde la plus forte des deux remises). `App\Service\Catalogue\PrixSpeciaux`.
- Promotions en cours, à venir et suspendues, avec leur nombre d'utilisations (docs/promotions.md).
- Alertes : configurations dont la marge passe sous le minimum en grille revendeur (droit marges seulement).

## PR04 — Options partagées (`/produits/options`)

Matières (supports), finitions, délais (Standard, Express), services (création graphique, livraison). Chaque option dit sur combien de produits elle porte, et « Où est utilisée… » liste ces produits. Changer le prix d'une finition (Admin) le change sur toutes les configurations qui l'utilisent, avec l'historique des prix. Les matières liées au stock montrent prix d'achat et coût unitaire avec le droit marges seulement.

## Calculs

`App\Service\Catalogue\CoutProduit` :
- coût = Σ matières (quantité de la nomenclature × coût moyen du stock) + encre (au m²) + machine (coût horaire ÷ vitesse) ; si rien ne se calcule, le coût saisi sur la fiche ;
- marge = arrondi((prix − coût) ÷ prix × 100) ;
- niveau : bon à partir de la cible, à surveiller entre le minimum et la cible, alerte dessous.

## Données

Migration `Version20261018171820` : table `prix_special` ; colonnes `produits.delai_heures`, `cout_revient_saisi`, `visible_en_ligne` (1 par défaut), `motif_hors_ligne`, `machine_id`, `machine_secours_id`.

Synchronisation : les produits emportent `visibleEnLigne` et `delaiHeures` ; `prix_special` descend au site (prix, fin, statut, client, configuration). Restent au local : la raison, les auteurs, la décision, le coût saisi, les machines.

## Démo

`app:demo` (`ProduitsDemo`) : délai, machine et machine de secours de chaque produit fictif ; le polo masqué en ligne (« Machine en réparation », la presse de sublimation étant en panne dans la démo de l'atelier) ; six prix négociés (Hôtel Lumière : bâche et cartes ; Garage Sacko : t-shirt ; ONG Nutrition Enfance : Akylux ; Clinique Kayes : vinyle **en attente** ; Boulangerie Ballo : flyers **échu**). Demo.kadiatou (direction) voit les marges ; demo.aminata (commerciale) ne les voit pas.
