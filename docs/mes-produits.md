# « Mes produits » et panier

Maquettes de Yaya du 18/10/2026, MP01 à MP07 (`/mnt/project-files/maquettes/mes-produits-panier/`). Elles remplacent le fonctionnement du 10/10 (P01 à P05) : le client ajoute lui-même ses produits, l'atelier lie une fois le fichier d'impression, puis le client commande par un panier sans renvoyer de fichier.

## Le parcours en bref

1. **Le client ajoute ses produits** (MP01) : image, nom, type, dimensions. Rien n'est commandé.
2. **Le prépresse lie le fichier traité** (MP02) de chaque produit, pris dans le dossier du client sur le serveur de l'atelier. L'ERP contrôle le fichier et garde son empreinte.
3. **Le produit passe « Prêt à imprimer »** (MP03) dans l'espace client. Il y reste, même sans commande.
4. **Le client choisit la taille et la quantité** (MP04), puis « Ajouter au panier ».
5. **Il valide le panier** (MP05) : le panier devient la commande. Il paie l'acompte par Orange Money, sauf s'il en est dispensé.
6. **L'ERP aiguille chaque ligne** (MP06) : fichier lié à une taille validée → file d'impression de la machine ; autre taille → prépresse puis BAT.

## 1. Ajouter des produits (client, MP01)

Espace client › Mes produits › « + Ajouter des produits » (`/espace/produits`).

- Une ligne par produit : image (envoi par morceaux, comme les autres fichiers de l'espace), nom, type, dimensions. 20 produits au plus par envoi.
- Les dimensions s'écrivent librement : « 50 × 30 mm », « Ø 60 mm », « A6 », « 8,5 × 5,5 cm », « 3 × 1 m ». Le serveur les lit (`MesProduits::lireDimensions`).
- Le type range le produit dans une famille (Étiquettes, Cartes, Imprimés, Grand format, Autres) : ce sont les filtres de la page.
- Le produit est créé avec l'origine « client », l'état « à préparer » et sans prix. Le prépresse est prévenu.

## 2. Lier le fichier traité (prépresse, MP02)

Prépresse › Produits clients à préparer (`/pre-presse/produits-clients`, rôle Prépresse). Un bandeau sur l'écran Prépresse annonce les clients en attente.

- La file montre un client par ligne, le plus ancien d'abord : produits à lier, tailles commandées pas encore validées.
- Sur la page du client : ses produits à gauche, le **dossier local** à droite, puis le **contrôle du lien**.
- Le graphiste glisse un fichier du dossier sur un produit (ou touche le produit puis le fichier). Il indique la taille validée en mm (pré-remplie avec le format du produit, ou une taille déjà commandée). Le contrôle s'affiche, puis « Valider le lien ».
- Un fichier glissé depuis l'explorateur Windows est reconnu par son nom et son poids : il doit déjà être dans le dossier du client.
- « Client suivant » passe au client suivant de la file. « Prix et réglages » ouvre la fiche admin du produit (rôle Commandes).
- Un produit lié peut recevoir d'autres tailles (« glisser un fichier pour une autre taille »). « retirer » enlève une taille validée.

### Le dossier des clients

Réglage **Paramètres › Atelier › Dossier des clients** : le dossier racine, tel que le voit le serveur de l'ERP (`D:\Clients`, `/srv/clients`). Dans ce dossier :

- un dossier par client, dont le nom **commence par son code** (« DEMO-0195 Amadou Sow », « C-0231_Awa ») ;
- dans ce dossier, un sous-dossier « Produits » pour les fichiers traités (sinon le dossier du client lui-même) ;
- les sous-dossiers (« _sources ») sont listés mais ne se lient pas.

Fichiers acceptés : PDF, AI, EPS, TIFF, JPG, PNG, PSD.

### Pourquoi c'est l'ERP qui lit le dossier

Un navigateur ne peut pas lire un chemin du disque (`D:\Clients\…`) : il ne voit que les fichiers qu'on lui donne, sans leur chemin. C'est donc **le serveur de l'ERP local** qui liste le dossier, ouvre le fichier, le contrôle et calcule son **empreinte SHA-256**. Le fichier ne quitte jamais le serveur de l'atelier. Le site en ligne ne reçoit que la référence : nom du fichier, empreinte, poids, date et tailles validées.

### Ce que le contrôle vérifie, honnêtement

| Point | Comment | Si on ne sait pas |
|---|---|---|
| Dimensions | PDF : TrimBox (sinon MediaBox), fonds perdus calculés ; image : pixels. Tolérance 0,5 mm ; un fichier tourné est signalé. | « à vérifier dans le logiciel » (AI, EPS, PSD…) |
| Couleurs | PDF : espaces DeviceCMYK / DeviceRGB trouvés dans le fichier ; image : nombre de canaux. | « à vérifier » |
| Découpe / perforation | Couleur d'accompagnement ou calque nommé CutContour, Thru-cut, Kiss-cut, Découpe, Perfo, Rainage… | « aucun calque de découpe détecté : à vérifier » |

Le contrôle n'affirme le CMJN ou la découpe que s'il les trouve dans le fichier. Il lit les 4 premiers et les 4 derniers Mo, ce qui suffit pour un PDF de plusieurs centaines de Mo.

## 3. Mes produits (client, MP03)

- Chaque carte : image du client, nom, type et taille, puis l'état :
  - **✓ Prêt à imprimer** (fichier lié, version et date du lien) → « + Commander » ;
  - **⏳ Fichier en préparation** (date de réception) → « Disponible bientôt » ;
  - **BAT à chaque commande** : anciens produits enregistrés par l'atelier sans fichier lié ni BAT figé ; ils restent commandables comme avant.
- Un produit sans prix n'est pas commandable : l'atelier fixe le prix dans la fiche admin du produit (prix négocié et **prix par quantité**, jusqu'à 4 paliers).
- « Remplacer l'image » (dans la fenêtre de commande) : le lien est **suspendu**, le produit repasse « en préparation » et le prépresse est prévenu.

## 4. Taille et quantité (client, MP04)

- **Dimensions** : les tailles validées (« taille préparée · direct »), puis « Autre taille » (l'atelier adapte le fichier).
- **Quantité** : les paliers de prix (250, 500, 1 000…) avec le prix à l'unité, ou « Autre ».
- Le prix vient du serveur. Pour une autre taille, il suit la surface, jamais moins cher que la taille du produit (arrondi aux 5 F).
- « Ajouter au panier » : le volet du panier s'affiche à droite (barre en bas sur téléphone).

## 5. Le panier (client, MP05 et MP07)

`/espace/panier`. Le panier est gardé dans la session de la personne (il disparaît à la déconnexion).

- Chaque ligne : quantité − / +, prix, retrait. Pastille verte « Fichier prêt · va directement en impression » ou orange « Nouvelle taille · l'atelier adapte le fichier ».
- Réception : retrait à l'imprimerie (gratuit) ou livraison à Bamako (frais du réglage, adresse, contact, créneau). Bon de commande pour un compte entreprise.
- Récapitulatif : total, acompte (pourcentage du client ou du réglage), date « Prêt le ».
- Le bouton dépend de la personne :
  - payeur, Orange Money ouvert : **« Valider et payer X F »** → récapitulatif Orange Money puis la page d'Orange ;
  - client **dispensé d'acompte** : **« Valider la commande »** (vert), la commande part en production ;
  - demandeur d'un compte entreprise : **« Envoyer la demande »**, sans aucun prix ;
  - sinon « Valider la commande » : l'acompte se règle à l'imprimerie ou dans « Factures et paiements ».
- « Après validation » dit combien de produits partent directement en impression et combien attendent un BAT.

Droits : un demandeur ou une lecture seule ne voit aucun prix (page, volet, appels de calcul) ; l'acompte ne se montre qu'à qui paie ; la lecture seule ne peut rien envoyer.

## 6. L'aiguillage (ERP, MP06)

Fiche commande › bloc **Aiguillage automatique des lignes** (dès qu'une ligne vient de Mes produits).

| Cas | Ce qui se passe |
|---|---|
| Fichier lié + taille validée | file d'impression de la machine, pas de BAT |
| Nouvelle taille ou nouvelle matière | prépresse, puis BAT au client |
| Le client remplace l'image du produit | lien suspendu, retour au prépresse |
| Fichier modifié ou déplacé dans le dossier | alerte : l'empreinte ne correspond plus, la ligne repasse au prépresse |
| Nouvelle taille validée par l'atelier | ajoutée au produit pour la prochaine fois |

Quand l'aiguillage s'applique :

- **automatiquement**, à la réception au local d'une commande du panier, dès que l'acompte est couvert (paiement Orange Money reçu) ou que le client en est dispensé (`Aiguillage::lancerSiPrete`) ;
- **à la main**, avec « Confirmer et lancer » sur la fiche commande (rôle Commandes) : utile pour un acompte payé à l'imprimerie, et en démonstration (une seule base, pas de synchronisation).

Lancer crée un **ordre de production transmis** pour chaque ligne directe, avec un contrôle prépresse « envoyé en production » qui reprend le contrôle fait au lien (pas de BAT). Les autres lignes de Mes produits repassent au prépresse. Le prépresse et la production sont prévenus.

L'empreinte est **recalculée** à chaque affichage de l'aiguillage, sur « Ma file » de l'opérateur (bloc « Fichier lié ») et à chaque « Ouvrir le PDF » (`/commande-fichiers/lie/{ligne}`, rôles Prépresse ou Production). Si elle a changé, le PDF ne s'ouvre pas : une page d'alerte demande de prévenir le prépresse (qui peut l'ouvrir quand même).

### Données d'aiguillage réutilisables

`App\Service\ProduitsClient\Aiguillage` (aussi en Twig : `aiguillage_commande(commande)`, `etat_fichier_lie(ligne)`) :

- `pourLigne(ligne)` → `mode` (`direct`, `prepresse`, `standard`), `alerte`, `motif` (phrase prête à afficher), `taille` (« 50 × 30 mm »), `fichier` (`nom`, `empreinte`, `le`, `etat` : `identique` / `modifie` / `introuvable` / `hors_atelier`, `chemin`), `destination` (« File d'impression · Solvant 1,80 m », « Prépresse · adapter puis BAT »), `machine`, `operateur`, `ordre` (`id`, `numero`, `statut`), `produit` (`reference`, `nom`).
- `pourCommande(commande)` → `lignes` (chaque `ligne` avec son `aiguillage`), `directes`, `prepresse`, `alertes`, `depuisMesProduits`, `acompteRegle`, `lancee`.
- Sur chaque ligne de commande, `fichierLie` garde la référence retenue à la commande (nom, empreinte, l, h en mm, date du lien).

## Client dispensé d'acompte

Voir docs/clients.md. Pour Mes produits : pas d'acompte dans le panier, « Valider la commande » en vert, et la commande est lancée dès son arrivée au local.

## Synchronisation

`produit_client` circule dans les deux sens (docs/synchronisation.md) :

- du local vers le site : tout le produit, sauf le contrôle et le nom de l'employé dans les fichiers liés ;
- du site vers le local : les produits ajoutés par le client (référence `W-…`, état « à préparer », sans prix ni fichier) et le remplacement d'image (le lien est suspendu au local, le prépresse est prévenu). Le client ne peut changer ni le prix, ni le lien, ni le BAT.

## Dans le code

- `ProduitClient` : `origine`, `type`, `rond`, `image`, `imageRemplaceeLe`, `etatLien` (`a_preparer`, `lie`, `suspendu`), `fichiersLies` (une entrée par taille validée), `paliers`.
- `CommandesDetails::fichierLie`, `Clients::sansAcompte` (migration `Version20261018176000`).
- Services : `ProduitsClient\DossierClient` (dossier, liste, chemin sûr, empreinte), `ControleFichierLie`, `LiensFichiers` (préparer, lier, vérifier), `FilePrepresseProduits`, `Aiguillage` ; `EspaceClient\MesProduits` et `EspaceClient\Panier` ; `CommandeProduitClient::aiguillerALaCommande`.
- Contrôleurs : `EspaceProduitsController`, `EspacePanierController`, `ProduitsClientsPrepresseController`, `AiguillageCommandeController`.
- Tests : `tests/Unit/Service/MesProduitsPanierTest.php`, `CommandeProduitClientTest.php`.

Pas encore fait : réservation de la matière en stock à la commande.
