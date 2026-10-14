# Import des données de démarrage (jalon J7)

Pour démarrer l'ERP avec les données de l'ancien fichier : clients, articles du catalogue, articles de stock et soldes d'ouverture (ce que les clients devaient, l'argent des comptes, ce que la boutique devait à ses fournisseurs).

Écran : Réglages › Sécurité et système › **Import des données** (`/reglages/systeme/import`). **ERP local seulement** : les adresses de l'import renvoient 404 sur le site en ligne (`ImportHorsLigneSubscriber`, avant le pare-feu). Les fiches importées partent ensuite vers le site par la synchronisation ordinaire.

## Qui peut importer

| Type | Droits nécessaires (tous) |
|---|---|
| Clients | Direction (`ROLE_ADMIN`) |
| Articles du catalogue | Direction |
| Articles de stock | Direction ; la colonne « Prix d'achat » demande aussi « Coûts et marges » (`ROLE_COUT_MARGE`), sinon elle n'est ni dans le modèle ni lue |
| Soldes clients | Direction + Paiements (`ROLE_PAIEMENT_VOIR`) + Comptabilité (`ROLE_COMPTABILITE`) |
| Soldes des comptes | Direction + Trésorerie (voir et saisir) + Comptabilité |
| Dettes fournisseurs | Direction + Trésorerie (voir) + Comptabilité |

Tout est contrôlé côté serveur (`ImportController`, `#[IsGranted('ROLE_ADMIN')]` puis `Imports::accessible` pour chaque type, jeton CSRF sur chaque envoi). La direction a tous ces droits par la hiérarchie des rôles ; les autres rôles (caisse, graphiste, comptable) reçoivent « accès refusé » sur toutes les adresses de l'import, y compris le journal et les fichiers.

## Déroulé

1. **Choisir le type** et télécharger son **modèle** Excel : l'en-tête (colonnes obligatoires marquées « * »), deux lignes d'exemple en gris, une feuille « Notes » qui explique chaque colonne. Toutes les cellules sont au format texte, pour qu'Excel ne transforme ni les numéros ni les codes.
2. **Remplir** le modèle. L'ordre des colonnes est libre, les colonnes facultatives peuvent être retirées ; les en-têtes sont reconnus sans accents ni majuscules, avec leurs autres noms courants (« Tél. », « Portable », « Raison sociale »…). Les deux lignes d'exemple, si elles sont restées en tête du fichier, sont reconnues et écartées (au moins deux cellules identiques à l'exemple).
3. **Envoyer** le fichier : `.xlsx` ou CSV. Pour les soldes, choisir le **jour d'ouverture** (pas dans le futur) ; pour les clients, cocher au besoin « Compléter les fiches existantes ».
4. **Aperçu ligne par ligne** : pour chaque ligne, « Sera créé », « Sera mis à jour », « Écarté » (doublon, ligne d'exemple, montant nul…) ou « Erreur », avec la raison. Filtres par statut, lignes avec avertissement, 200 lignes par page. **Rien n'est écrit en base à cette étape.**
5. **Confirmer** : le fichier est relu et l'analyse refaite (la base a pu changer depuis l'aperçu), puis tout est enregistré **dans une seule transaction**, avec la ligne du journal. Si la base refuse quoi que ce soit, rien n'est gardé et le journal note un « échec ».

Le fichier envoyé attend la confirmation dans `var/imports/attente/` (au nom de la personne qui l'a envoyé, effacé après 24 h ou sur « Abandonner »).

### Fichiers acceptés

- Excel `.xlsx` (première feuille) ; un ancien `.xls` s'enregistre en `.xlsx` depuis Excel. La lecture des `.xlsx` demande l'extension PHP **zip** (docs/deploiement.md) ; sans elle, l'écran le dit et demande un CSV.
- CSV en UTF-8 (avec ou sans BOM), UTF-16 ou Windows-1252 (CSV d'Excel français), séparé par « ; », « , » ou tabulation (détecté sur la ligne d'en-tête).
- Montants : « 1 500 000 », « 1500000 », « 1500000,00 », « 1.500.000 », « 250 000 F » ; pas de centimes (le franc CFA n'en a pas). Négatif : « -25 000 » ou « (25 000) », seulement là où il a un sens (avance d'un client).
- Téléphones : 8 chiffres, avec ou sans +223 / 00223, espaces, points, tirets ; un numéro qu'Excel a changé en nombre (« 76123456.0 », « 7.6123456E7 ») est relu.
- Dates : jj/mm/aaaa, jj-mm-aa, aaaa-mm-jj ou date Excel.
- 5 Mo et 5 000 lignes au plus, 40 colonnes.

## Les types

### Clients

Chaque ligne passe par `EditionClient`, **exactement comme une fiche saisie à la main** : mêmes contrôles (téléphone à 8 chiffres, e-mail valide, NIF et RCCM pour les organisations), même mise en forme, même code client (`CLI-…`), même plafond par défaut (100 000 F), même journal de la fiche (« depuis l'import »).

- Particulier : « Nom » = prénom puis nom (« Mariam Traoré ») ; le dernier mot devient le nom.
- Organisation (entreprise, ONG, administration) : « Nom » = raison sociale, « Contact » = la personne à appeler, qui devient le **contact principal** avec le téléphone.
- Un code inconnu de l'ERP (code de l'ancien logiciel) est gardé dans les notes : « Ancien code : C-0042 ».

**Doublons**, dans cet ordre :
1. même code client ;
2. même téléphone (celui de la fiche, le second, ou celui d'un contact), quelle que soit l'écriture ;
3. même nom, sans accents ni majuscules et **dans n'importe quel ordre** (« Traoré Mariam » = « Mariam TRAORÉ »).

Retrouvé par code ou téléphone : la ligne est écartée (« Déjà enregistré »), ou, si « Compléter les fiches existantes » est coché, la fiche reçoit **seulement les champs vides** que la ligne remplit (rien n'est jamais écrasé ; chaque champ est noté au journal de la fiche). Retrouvé par le nom seulement : la ligne est écartée comme **doublon probable** et jamais complétée (ce peut être un homonyme) ; si c'est une autre personne, on l'ajoute à la main. Un client présent deux fois dans le fichier (même téléphone ou même nom) n'est créé qu'une fois.

Aucun accord pour les messages promotionnels n'est enregistré à l'import.

### Articles du catalogue

Nom, catégorie, unité (pièce, m², mètre, forfait, heure, feuille, exemplaire, point, face), prix public, prix revendeur (facultatif), code (facultatif, sinon tiré du nom : « BACHE-MICROPERFOREE »), description.

- Une catégorie inconnue est créée, **non publiée**.
- Les produits créés sont actifs (vendables au comptoir) mais **non publiés** sur le site : il leur manque types d'impression, supports et formats, à compléter avant de les publier.
- Un produit déjà au catalogue (même code, sinon même nom) n'est pas recréé : **seuls ses prix** sont mis à jour, et l'historique des prix garde l'ancien (`PrixHistorique`).
- Pas de coût de revient ici : il se calcule depuis le stock et les configurations.

### Articles de stock

Désignation, famille, unité, référence (sinon préfixe de la famille + numéro : « ENC-001 »), prix de vente, prix d'achat (droit Coûts et marges), seuil minimum, fournisseur, vendable.

- La famille doit exister (erreur sinon) ; un fournisseur inconnu donne un avertissement et l'article est créé sans fournisseur. Le fournisseur connu devient le fournisseur principal, avec le prix d'achat.
- Un article déjà là (même référence, sinon même désignation) est écarté : rien n'est écrasé.
- Le **stock de départ ne s'importe pas** : il se compte avec l'inventaire, qui le valorise au prix d'achat et laisse sa trace dans les mouvements de stock.
- Les articles de stock ne partent pas vers le site (règle de la synchronisation).

### Soldes d'ouverture

Trois imports, **en tout ou rien** : une seule ligne en erreur bloque l'import entier (on corrige le fichier et on le renvoie). Un montant nul est écarté. Le jour d'ouverture, choisi à l'envoi, date les soldes et leurs à-nouveaux.

| Type | Colonnes | Refusé quand |
|---|---|---|
| Soldes clients | code client, téléphone ou nom (au moins un), montant dû, échéance, note | client introuvable ou nom porté par plusieurs fiches ; client présent deux fois ; client qui a déjà un solde d'ouverture actif |
| Soldes des comptes | compte (code ou nom d'un compte de trésorerie déjà créé), solde | compte inconnu ; solde négatif (découvert : faire un ajustement) ; compte qui a déjà une opération (mouvement, paiement, clôture, rapprochement, dépense payée) ; compte déjà importé |
| Dettes fournisseurs | fournisseur, montant dû, échéance, objet | — (un fournisseur absent de la liste est gardé comme bénéficiaire, avec un avertissement) |

Un solde client au-dessus du plafond de crédit du client donne un avertissement.

## Représentation des soldes d'ouverture

Le choix : la représentation **la moins invasive** qui garde chaque écran juste, en réutilisant ce que les écrans savent déjà montrer, et **une trace par solde** (`SoldeOuverture`) qui fait les à-nouveaux en comptabilité.

| Solde | Ce qui est créé | Où on le voit |
|---|---|---|
| Un client devait de l'argent | une **commande marquée solde d'ouverture** (`commandes.solde_ouverture = 1`) : numéro `SO-<code client>`, montant = total TTC, **sans lignes, sans facture, sans TVA, sans travail** (`etat = 0`), date = jour d'ouverture, échéance = date de livraison (jour d'ouverture si vide) | fiche client (solde dû, ligne « Solde d'ouverture · Repris à l'import », historique), créances (avec son échéance et son ancienneté), caisse et page de paiement (« Solde d'ouverture »), relevé et PDF des impayés, espace client du payeur ; sa page (`/commandes/{id}`) montre le montant, les règlements et l'import d'origine |
| Un client avait versé une avance | son **solde client** (`clients.solde_credit`) augmente, noté au journal de la fiche | fiche client ; il se consomme comme tout solde client (paiement « solde client ») |
| Argent d'un compte de trésorerie | le **solde initial** du compte (et son solde actuel), comme s'il avait été saisi à sa création | journal du compte, caisse du jour, trésorerie, prévision |
| La boutique devait à un fournisseur | une **dépense « à payer »**, catégorie matières, approuvée, marquée solde d'ouverture, avec l'échéance | échéancier et prévision de trésorerie, fiche de la dépense (« Solde d'ouverture » à la place du reçu) ; elle se paie comme une autre (décaissement, compte 401) |

Chaque solde a aussi sa ligne `solde_ouverture` (type, montant, jour d'ouverture, tiers, échéance, note, import, et le lien vers la commande, le compte ou la dépense), qui fait le **journal des à-nouveaux** (« AN ») de la comptabilité :

| Solde | Débit | Crédit |
|---|---|---|
| Créance client | 411 client (lettrée avec ses règlements quand elle est soldée) | 471 |
| Avance client | 471 | 411 client |
| Compte de trésorerie | 571 caisse, 521 banque, 5213 Orange Money, 5214 Wave | 471 |
| Dette fournisseur | 471 | 401 fournisseur |

Pièce `AN-000001`… à la date d'ouverture ; chaque écriture est équilibrée. Le 471 (compte d'attente) porte la contrepartie de l'ensemble : le cabinet le reclasse au bilan d'ouverture (question ci-dessous). Un solde de compte remis à 0 ne fait pas d'écriture.

### Ce n'est jamais une vente

**Pas de facture inventée** : un solde d'ouverture n'a ni lignes ni prix ; il ne peut ni être facturé (`FacturesController` le refuse), ni modifié, annulé, supprimé ou restauré comme une commande (`CommandesController` le refuse : on annule l'import).

La commande « solde d'ouverture » est exclue de tout ce qui compte des ventes ou de l'activité (`c.soldeOuverture = false` dans chaque requête) :

- chiffre d'affaires, ventes, tableau de bord, évolution financière, rapports et statistiques (globales, clients, par agent), rapport de la semaine, rentabilité, chiffres du site ;
- objectifs (chiffre et encaissements), mes statistiques, primes (elles se calculent depuis ces mesures) ;
- fidélité : aucun point gagné sur son règlement, pas de bon ni de réveil utilisable dessus ;
- promotions, segments, réveil des clients, anniversaires, sondages, campagnes ;
- actions de caisse, écran TV, liste des commandes, recherche, file de l'atelier, API.

Son **règlement** est de l'argent réellement encaissé : il compte dans la caisse du jour, la clôture, la trésorerie, les encaissements et « encaissé aujourd'hui », comme n'importe quel paiement, mais pas dans les objectifs ni les statistiques personnelles. La notification interne de paiement dit « encaissé sur le solde d'ouverture SO-… ».

La dette fournisseur est hors budgets et hors dépenses du mois (`Budgets`, `Depenses::duMois`) : ce n'est pas une charge de la période. Son paiement sort bien de la trésorerie.

### Qui voit les soldes

- Libellé partout : « Solde d'ouverture ».
- À l'ERP : seulement avec le droit Paiements (`ROLE_PAIEMENT_VOIR`) ; sa page est refusée sans ce droit (un graphiste reçoit « accès refusé »). Sur la fiche client, les lignes et l'historique du solde n'apparaissent qu'avec ce droit.
- Espace client : comme tout montant d'entreprise, **seulement pour le payeur** (règle existante : Factures et paiements est fermé aux autres comptes de l'entreprise). Il y apparaît comme « Solde d'ouverture · Montant dû au démarrage du compte », payable en ligne, mais pas dans le fil des commandes ni dans les notifications.
- Les relances automatiques de paiement ne le prennent pas (`findToutesNonSoldees`) ; une relance manuelle reste possible depuis les créances.

### Synchronisation

Les soldes suivent ce que la synchronisation envoie déjà, rien de plus : la commande « solde d'ouverture » part comme une commande (avec le seul champ en plus `soldeOuverture`, liste blanche de `CatalogueSynchro`, jamais accepté en remontée, forcé à 0 sur une commande créée en ligne), le solde client part avec la fiche. Ne partent pas : le journal des imports, les lignes `solde_ouverture`, les dépenses, les comptes de trésorerie, les articles de stock.

Les clients, catégories et produits importés partent par la file ordinaire (`JournalSynchro`), au prochain `app:synchro`.

## Aucun message

L'import n'envoie **rien** aux clients : ni SMS, ni e-mail, ni WhatsApp, ni push, ni message de bienvenue. La création passe par `EditionClient` sans notification ; aucun abonné à la création d'un client n'envoie de message. Le règlement d'un solde d'ouverture n'entre pas dans le fil de notifications de l'espace client.

## Journal et fichiers

Chaque import (réussi, en échec ou annulé) a sa ligne au journal (`import_donnees`) : type, nom du fichier, empreinte SHA-256, taille, qui et quand, options (jour d'ouverture, compléter, encodage, séparateur), nombres de lignes lues, créées, mises à jour, écartées, en erreur, résultat, et le détail ligne par ligne (fiche créée ou complétée et ce qui a changé).

Le fichier importé est gardé **hors de `public/`**, dans `var/uploads/imports/AAAA/MM/<n°>-<empreinte>.<ext>`, et ne se télécharge que par le journal (`/reglages/systeme/import/journal/{id}/fichier`), avec les droits du type.

**Même fichier deux fois** : l'aperçu prévient (« Ce fichier a déjà été importé le … par … ») et la confirmation demande de cocher une case. Relire le fichier ne crée pas de doublons : les fiches déjà là sont reconnues et écartées, et un client ou un compte qui a déjà un solde d'ouverture est refusé.

## Annuler un import

Le détail d'un import propose « Annuler l'import » **tant que rien de ce qu'il a enregistré n'a servi**. L'annulation est une transaction ; le journal garde la ligne, marquée « Annulé ».

| Type | Ce que l'annulation défait | Bloquée quand |
|---|---|---|
| Clients | supprime les fiches créées (avec leurs contacts et leur journal) ; vide les champs complétés s'ils n'ont pas changé depuis | une fiche créée a servi (devis, commande, paiement, solde d'ouverture, compte de l'espace client…) ou a été modifiée à la main depuis |
| Articles du catalogue | supprime les produits créés et les catégories créées restées vides ; remet les anciens prix | un produit a servi (devis, commande, configuration…) ou ses prix ont changé depuis |
| Articles de stock | supprime les articles créés | un article a servi (mouvement de stock, inventaire, achat…) |
| Soldes clients | supprime la commande « solde d'ouverture » ; retire l'avance du solde client | un règlement ou une relance existe ; l'avance a été utilisée |
| Soldes des comptes | remet l'ancien solde initial | le compte a eu une opération |
| Dettes fournisseurs | supprime la dépense | la dette a été payée (ou n'est plus « à payer ») |

Les lignes `solde_ouverture` annulées restent (pour l'historique) mais ne font plus d'écriture.

**Quand l'annulation est refusée**, l'écran dit pourquoi (« Des soldes ont déjà reçu un paiement : Assétou Samaké »). On corrige alors fiche par fiche :
- un client : le modifier depuis sa fiche, ou le fusionner / le désactiver ;
- un produit ou un article : le modifier ou le désactiver ;
- un solde client mal saisi et déjà en partie réglé : rembourser ou ajuster le trop-perçu par la caisse (avoir ou solde client), ou faire passer la différence par une écriture d'ajustement validée par le cabinet ;
- un compte : un ajustement de trésorerie ;
- une dette : modifier la dépense tant qu'elle n'est pas payée.

Cas limite : si un client créé par l'import a ouvert entre-temps un compte de l'espace client en ligne, ce compte est lui-même une utilisation et bloque l'annulation.

## Décisions

- Tout l'import vit à l'ERP local ; le site en ligne ne reçoit que le résultat, par la synchronisation.
- Aperçu sans écriture, puis nouvelle lecture et nouvelle analyse à la confirmation (la base a pu changer), en une transaction.
- Fiches (clients, produits, articles) : les lignes en erreur sont laissées de côté, les autres enregistrées. Soldes : tout ou rien.
- Doublon par le nom seul : écarté, jamais complété.
- Produits et catégories créés non publiés.
- Soldes : commande sans lignes plutôt que fausse facture ; solde client pour une avance ; solde initial pour un compte vierge ; dépense à payer pour une dette ; contrepartie 471.
- Exemples du modèle reconnus seulement sur les deux premières lignes du fichier.

## Questions pour Yaya

1. **Compte de contrepartie des à-nouveaux** : 471 (compte d'attente) par défaut. Le cabinet préfère-t-il un autre compte (par exemple 101 ou 12x pour un vrai bilan d'ouverture) ?
2. **Relances automatiques** : les soldes d'ouverture n'en reçoivent pas (le client ne les connaît peut-être pas sous cette forme). Faut-il les relancer comme les autres créances après un délai ?
3. **Homonymes** : un client au même nom qu'une fiche existante, avec un autre téléphone, est écarté comme doublon probable. Faut-il plutôt le créer avec un avertissement ?
4. **Accord marketing** : aucun accord n'est repris. L'ancien fichier en contient-il un fiable à importer ?
5. **Extension zip** : le serveur de la boutique doit l'avoir pour lire les `.xlsx` (sinon, CSV seulement).
