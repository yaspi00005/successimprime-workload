# Amortissement, patrimoine et primes

Yaya a envoyé 8 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/immobilisations-primes/` :
- M01 à M04 pour les machines et le patrimoine ;
- N01 à N04 pour la performance et les primes.

Les montants, compteurs, scores et noms sont fictifs.

## Machines et amortissement à l'usage (M01, M02)

Chaque machine s'amortit selon son usage réel, avec une unité propre :

| Machine | Unité d'usage |
|---|---|
| Les deux solvants, le traceur | m² imprimé |
| DTF | mètre de film |
| Canon | page A4 (une page A3 compte pour 2 pages A4) |
| Imprimante PVC | carte |
| Sublimation | transfert |

**Calcul :** coût par unité = (prix d'achat − valeur résiduelle) ÷ capacité prévue.

**Exemple :** 18 000 000 F d'achat, 0 F de valeur résiduelle et 60 000 m² prévus donnent 300 F par m².

- Ce coût entre dans le coût de revient de chaque commande, à côté de la matière (nomenclature S03, `docs/stock-et-options.md`).
- L'amortissement du mois est égal à l'usage du mois multiplié par le coût par unité. Il s'arrête quand la valeur nette atteint la valeur résiduelle.

### Ce qui existe déjà

`Machines` a déjà :
- le prix d'achat, la durée d'amortissement en mois ;
- un compteur de m², un compteur d'heures et un compteur de feuilles ;
- la page de rentabilité (`MachineRentabiliteService`), aujourd'hui calculée sur la durée.

### Ce qu'il faut ajouter

- Les champs : unité d'usage, capacité prévue, valeur résiduelle et code (IMM-0001…).
- L'usage des machines grand format, DTF, PVC et sublimation est compté automatiquement à partir de la production terminée.
- **Relevés de compteurs (M02) :** pour la Canon, on saisit chaque mois les compteurs A4 et A3, en couleur et en noir et blanc. La carte « Relevés de compteurs 6 / 7 » rappelle ceux qui manquent.
- **Fiche de la Canon :**
  - coût complet par page : amortissement, plus toner, plus entretien (`Maintenance`) ;
  - valeur nette.

## Patrimoine et registre (M03, M04)

Le patrimoine couvre tous les biens, pas seulement les machines : ordinateurs, chaises, tables, motos, climatiseurs, groupe électrogène…

Pour chaque bien, le registre donne :
- le code, la catégorie, le lieu, la date d'achat et la valeur d'achat ;
- la méthode : à l'usage pour les machines, linéaire sur N ans pour le reste ;
- l'amortissement cumulé et la valeur nette ;
- à qui le bien est confié, et son état.

**Dotation du mois :** elle produit une écriture SYSCOHADA :
- au débit, le compte 681 « Dotations aux amortissements » ;
- au crédit, le compte 28x « Amortissements » de la catégorie du bien.

La comptabilité par société (`docs/societes.md`) n'existe pas encore. D'ici là, les écritures s'exportent pour le comptable.

**Droits :** valeurs d'achat et valeurs nettes sont réservées à l'admin et à la comptabilité. Un agent voit seulement les biens qui lui sont confiés.

## Performance et primes (N01 à N04)

Chaque agent a un score sur 100 par mois. Sa prime est un pourcentage de la prime cible de son rôle :

| Score | Prime versée |
|---|---|
| 90 et plus | 100 % |
| 80 à 89 | 75 % |
| 70 à 79 | 50 % |
| 60 à 69 | 25 % |
| moins de 60 | 0 |

**Calcul du score :** score = somme des notes × poids. Chaque rôle a son barème (N03) : commercial, production, PAO, livreur, comptable.

**Critères mesurés automatiquement dans l'ERP :**
- ventes par rapport à l'objectif ;
- taux de transformation des devis ;
- avis clients ;
- encaissements à temps ;
- écarts de caisse ;
- réimpressions dues à l'agent ;
- ponctualité.

**Critère saisi :** l'appréciation du responsable.

### Règles

- **Données manquantes :** un critère sans données, par exemple la ponctualité avant le pointage de l'espace employé (`docs/espace-employe.md`), n'est pas noté 0. Son poids est réparti sur les autres critères, et la fiche le signale.
- **Validation :** le responsable valide l'évaluation du mois (« Valider l'évaluation »). Une fois validée, elle est figée. Un recalcul se fait par une nouvelle version, avec son motif.
- **Droits :**
  - l'agent voit seulement sa propre note et sa prime estimée, sur mobile (N04) ;
  - le responsable voit son équipe ;
  - l'admin voit tout ;
  - les autres agents ne voient rien.
- **Paie :** la prime validée passe dans la paie ou dans les sommes dues de l'espace employé. Elle n'est jamais versée automatiquement.

## Ordre proposé

1. **M01 et M02 :** l'amortissement à l'usage. Il alimente le coût de revient (S03), il vient donc juste après ou avec S03.
2. **M03 et M04 :** le patrimoine et le registre, avec l'export des dotations.
3. **N03, N02, N01, N04 :** le barème d'abord, puis l'évaluation, la vue d'ensemble et le mobile. Ces écrans ont besoin du pointage (espace employé) et des avis clients (`docs/sondages.md`) pour être complets.
