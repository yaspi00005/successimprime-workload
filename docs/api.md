# API REST

L'API permet à un logiciel de lire l'ERP local de l'atelier : un tableur
(Excel, Power Query), un outil de rapports, une future application. Elle
est faite avec API Platform et sa documentation interactive est générée à
partir du code, elle est donc toujours à jour.

- Documentation : `http://<serveur-local>/api/v1/docs` (lien « Documentation »
  dans Réglages › Sécurité › Accès API).
- Description OpenAPI : `GET /api/v1/docs` avec `Accept: application/vnd.openapi+json`.

## Ce que l'API donne (lecture seule)

| Adresse | Contenu | Droit demandé |
|---|---|---|
| `GET /api/v1/produits` | Produits actifs, nom commercial et prix publics (et B2B) | tout le personnel |
| `GET /api/v1/clients` | Fiche client : nom, type, téléphone, e-mail, ville | Clients (ROLE_CLIENT) |
| `GET /api/v1/commandes` | Commande, client, lignes, total TTC, avancement | Commandes (ROLE_COMMANDE) |
| `GET /api/v1/factures` | Factures et proformas émises ou annulées | Factures (ROLE_FACTURE) |
| `GET /api/v1/stock` | Articles du stock, nom commercial, quantités, seuil d'alerte | administrateur |

Chaque liste a sa fiche : `/api/v1/commandes/{id}`, etc.

Filtres utiles :

- `q` : recherche (nom, numéro, téléphone selon la ressource ; pour les produits et le stock, la désignation ou le nom commercial, sans tenir compte des accents) ;
- `depuis=AAAA-MM-JJ` (commandes, factures), `modifieDepuis=AAAA-MM-JJ` (clients) ;
- `client=<id>` (commandes, factures) ;
- `aConfirmer=1` : commandes passées en ligne pas encore confirmées ;
- `type=facture|proforma` (factures) ;
- `alerte=1` : articles sous le seuil d'alerte ;
- `publie=1` : produits montrés sur le site ;
- `page` et `itemsPerPage` (50 par défaut, 200 au plus).

Formats : JSON simple par défaut (`Accept: application/json`). Avec
`Accept: application/ld+json`, la réponse donne aussi le nombre total
(`totalItems`) et les liens vers la page suivante (`view.next`).

## Ce que l'API ne donne jamais

Marges, coûts de revient des commandes, notes internes, coordonnées GPS,
RH (salaires, pointages, photos), trésorerie, comptabilité, fichiers des
clients. Rien ne peut être créé, modifié ni supprimé par l'API.

Les champs sensibles restent présents mais valent `null` sans le droit :

- montants payés, restes à payer, statut de paiement, plafond et crédit
  utilisé des clients : droit « Paiements » (ROLE_PAIEMENT_VOIR) ;
- coût unitaire du stock : droit « Marges et coûts » (ROLE_COUT_MARGE).

## Jetons

Chaque appel porte un jeton :

```
Authorization: Bearer si_…
```

- Un administrateur crée le jeton dans Réglages › Sécurité › Accès API :
  nom de l'outil, personne au nom de qui il agit, durée (30 jours, 3 mois,
  1 an ou sans fin).
- Le jeton est montré **une seule fois**. La base ne garde que son empreinte
  (sha256) et ses 9 premiers caractères pour le reconnaître dans la liste.
- Il a les droits **de la personne, au moment de l'appel** : retirer un droit
  à la personne le retire au jeton. Une personne désactivée, à l'accès fermé
  ou qui doit changer son mot de passe ne passe plus.
- Pour un outil de rapports, créer une personne aux droits limités plutôt
  qu'utiliser le compte d'un administrateur.
- La liste montre le dernier usage (date et adresse IP) ; « Révoquer » coupe
  l'accès tout de suite.

Réponses d'erreur (`application/problem+json`) : 401 sans jeton ou avec un
jeton inconnu, expiré ou révoqué ; 403 sans le droit ; 404 fiche introuvable.

## En ligne

L'API n'est ouverte que sur l'ERP local. Sur le serveur en ligne
(`APP_EN_LIGNE=1`), `/api/v1` répond 404 : l'administration en ligne exige
un appareil autorisé et un code de sécurité (e-mail ou SMS), qu'un jeton contournerait. La
synchronisation entre l'ERP et le site garde ses propres adresses signées
(`/api/synchro`, voir docs/synchronisation.md).

## Exemple

```
curl -H "Authorization: Bearer si_…" \
     "http://192.168.1.10/api/v1/commandes?depuis=2026-10-01&itemsPerPage=100"
```

Dans Excel : Données › À partir du web › Avancé, adresse
`http://<serveur-local>/api/v1/factures?depuis=2026-01-01&itemsPerPage=200`,
en-tête `Authorization` = `Bearer si_…`.

## Installation

Rien à régler : `composer install` installe API Platform et les fichiers de
la documentation (`assets:install`), puis `doctrine:migrations:migrate`
crée la table `jeton_api`.

## Pour les développeurs

Aucune entité n'est exposée telle quelle : chaque ressource est une classe
de `src/ApiResource` remplie par son provider (`src/State`), qui recopie
les seuls champs autorisés (`LectureApi`). Ajouter une ressource = une
classe, un provider, et un test des champs cachés (tests/Unit/State).
