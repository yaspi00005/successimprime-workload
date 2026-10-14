# Amortissement, patrimoine et primes

Yaya a envoyé 8 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/immobilisations-primes/` :
- M01 à M04 pour les machines et le patrimoine ;
- N01 à N04 pour la performance et les primes.

Les montants, compteurs, scores et noms des maquettes sont fictifs. Les écrans calculent tout à partir des données de l'ERP.

Tout est **sur l'ERP local seulement** : ces écrans renvoient 404 sur le site en ligne (`PatrimoinePrimesHorsLigneSubscriber`), et leurs tables ne sont pas synchronisées.

## Machines et amortissement à l'usage (M01, M02)

Écran : Finances › Immobilisations › Machines et amortissement (`/finances/immobilisations/machines`).

Chaque machine s'amortit selon son usage réel, avec une unité propre, choisie dans la tuile Machines (Atelier › Machines, section « Amortissement ») :

| Machine | Unité d'usage | Comptée à partir de |
|---|---|---|
| Solvants, laser, traceur | m² imprimé ou page | production terminée (dimensions de la ligne, sinon celles du format) |
| DTF | mètre de film | production terminée |
| Canon | page A4 (une A3 = 2 A4) | relevé mensuel des compteurs A4 / A3, couleur / noir |
| Imprimante PVC | carte | production terminée |
| Sublimation | transfert | production terminée |

- **Coût par unité** = (prix d'achat − valeur résiduelle) ÷ capacité prévue. Exemple : 18 000 000 F, 0 F de valeur résiduelle, 60 000 m² prévus → 300 F par m².
- **Amortissement du mois** = usage du mois × coût par unité. Il s'arrête quand la valeur nette atteint la valeur résiduelle.
- **Usage du mois** = différence entre les compteurs de fin de mois. Un relevé saisi fait foi ; sans relevé, le compteur est reconstitué à partir de la production.
- Ce coût entre dans le coût de revient de chaque commande (`CalculateurCoutRevient`) et dans la rentabilité de la machine.
- Une machine sans capacité prévue reste en linéaire. La tuile Machines permet aussi de tout passer en linéaire.

**Relevés de compteurs (M02).** Une machine peut être suivie par relevé : un compteur (traceur) ou quatre compteurs A4 / A3 (Canon), avec une photo du compteur. « Saisir le relevé » (fiche machine) est ouvert à la comptabilité ; la saisie accepte aussi la gestion de production, qui n'a pas encore d'entrée de menu vers elle. La carte « Relevés de compteurs » de M01 rappelle ceux qui manquent. La dotation d'une machine suivie par relevé attend son relevé jusqu'au jour réglé dans la tuile Patrimoine (10 du mois suivant par défaut).

**Fiche machine (M02)** :
- identité, compteurs du mois, valeur nette, amortissements du mois et cumulé ;
- pages (ou unités) par mois sur 6 mois ;
- coût complet par page : amortissement + toner (coûts par page couleur / noir de la tuile Machines) + entretien (interventions `Maintenance` des 12 derniers mois ÷ usage des 12 derniers mois). Une page A3 coûte le double.

**Tableau d'amortissement** : bouton « Tableau d'amortissement » de M01, CSV des 12 derniers mois (usage, dotation, cumul, valeur nette par machine).

## Patrimoine et registre (M03, M04)

Écrans : Finances › Immobilisations › Patrimoine et Registre.

- **Patrimoine (M03)** : nombre de biens, valeur d'achat totale (hors logiciel), valeur nette, biens à contrôler (inventaire plus vieux que le délai de la tuile Patrimoine, 12 mois par défaut), cartes par catégorie, valeur nette par catégorie et par emplacement.
- **Registre (M04)** : code `IMM-0001…`, bien, catégorie, mise en service, valeur, méthode, amortissement cumulé, valeur nette, lieu et personne à qui le bien est confié, état. Filtre par catégorie, PDF, étiquettes QR et export pour le cabinet (CSV : registre à date et dotations de l'année).
- **Fiche d'un bien** : ajout, modification, sortie (vente, mise au rebut, vol ou perte) avec le calcul de la plus ou moins-value.
- **Inventaire annuel** : on coche, lieu par lieu, les biens vus sur place ; la date de contrôle se met à jour.
- Une machine de la tuile Machines pas encore au registre s'y ajoute depuis M01 (lien « ajouter au registre » sous son nom).

Catégories et comptes SYSCOHADA :

| Catégorie | Compte du bien | Compte d'amortissement | Durée par défaut |
|---|---|---|---|
| Machines de production | 2411 | 2841 | à l'usage (5 ans en linéaire) |
| Ordinateurs et tablettes | 2442 | 2844 | 3 ans |
| Chaises, tables et bureaux | 2444 | 2844 | 5 / 10 ans |
| Motos et tricycle | 2451 | 2845 | 4 ans |
| Climatiseurs, groupe électrogène et onduleurs | 2341 | 2834 | 5 ans |
| Écran TV et caméras | 2441 | 2844 | 5 ans |
| Logiciels | 2131 | 2813 | 3 ans |

**Dotation du mois.** Une ligne par bien (`dotation_amortissement`, unique par bien et par mois) et une pièce `DOT-AAAA-MM` datée du dernier jour du mois dans le journal des opérations diverses (Finances › Comptabilité) :
- au débit, 681 « Dotations aux amortissements » ;
- au crédit, le 28x de chaque catégorie (une ligne par compte).

Une dotation à 0 n'écrit rien. Les écritures s'exportent avec le journal (bouton d'export de Comptabilité) ; la comptabilité par société (`docs/societes.md`) n'existe pas encore.

## Performance et primes (N01 à N04)

Chaque personne a un score sur 100 par mois. Sa prime est un pourcentage de la prime cible de son rôle :

| Score | Part de la prime |
|---|---|
| 90 à 100 | 100 % |
| 80 à 89 | 75 % |
| 70 à 79 | 50 % |
| 60 à 69 | 25 % |
| moins de 60 | 0 |

**Rôles de prime** : caisse et commercial, graphiste PAO, opérateur production, livreur, comptable, agent d'entretien. Le rôle vient du profil (métier du suivi des équipes, profil caisse, droit Comptabilité) ; l'admin peut l'imposer, le retirer (« Pas de prime ») ou fixer une prime cible propre depuis l'évaluation de la personne. La direction n'est pas évaluée.

**Barème (N03)** — Réglages › Paramètres › Primes de performance, bouton Barème (`/equipe/bareme/primes`, admin) : pour chaque rôle, les critères retenus, leur poids (total 100 %), leur objectif et la prime cible. Un barème par défaut s'applique tant qu'il n'a pas été enregistré.

**Critères** :
- calculés dans l'ERP : ventes par rapport à l'objectif (l'objectif fixé à la personne dans Statistiques › Objectifs remplace celui du barème), transformation des devis, avis clients, encaissements à temps, erreurs (écarts de caisse, réimpressions dues), ponctualité et présence (pointage), points du métier (suivi des équipes), actions commerciales ;
- saisi : l'appréciation du responsable (note et commentaire visible par l'agent).

**Évaluation (N02)** — `/equipe/primes/agent/{id}` :
- **Données manquantes** : un critère sans données dans le mois (aucun avis, aucun devis…) n'est pas noté 0. Son poids est réparti au prorata sur les autres, la colonne Poids montre le poids appliqué et la fiche nomme les critères concernés.
- Le score se calcule en direct jusqu'à la validation.
- **« Valider l'évaluation »** (le responsable ou l'admin, une fois le mois terminé ; appréciation obligatoire si la tuile l'exige) fige la photo des critères.
- **Recalcul** : une évaluation validée ne change plus. « Recalculer » crée une version 2, 3… avec un motif obligatoire ; la version précédente reste en vigueur jusqu'à la validation de la nouvelle. L'historique garde toutes les versions.
- **Suspension** : faute grave ou écart de caisse non justifié → prime suspendue pour le mois, avec un motif.

**Vue d'ensemble (N01)** — Équipe › Performance et primes (`/equipe/primes`) : score moyen et écart avec le mois précédent, primes calculées sur primes possibles, évaluations à finaliser, période et date de paie ; une ligne par personne avec score, prime cible, taux, prime et statut (Appréciation à saisir, À valider, Prêt, Prime validée, Mois en cours). Pour un mois terminé, une personne sans aucune donnée ni évaluation commencée (congé, arrivée en cours de mois, compte inutilisé) sort du classement et est citée sous le tableau, sans note à 0.

**Paie.** « Valider les primes » (admin) valide les primes des évaluations validées du mois. Elles passent alors en somme due sur le bulletin de paie en brouillon (ligne « Prime de performance <mois> », ou « Régularisation prime » si une nouvelle version a baissé la prime). Rien n'est versé automatiquement : le bulletin suit son circuit habituel. Le report se fait juste après la validation, à la préparation de la paie (Équipe › Paie) et chaque matin (`app:primes`). « Export paie » donne le CSV du mois.

**Mobile (N04)** — Mon espace › Ma performance (`/mon-espace/performance`) et Ma prime (`/mon-espace/prime`) : la note du mois en cours, ses critères, un conseil (ce qu'il reste à vendre, sinon le critère le plus faible), la prime estimée, les paliers et l'historique des primes. Si la tuile Primes cache la note en temps réel, l'agent ne voit que les mois validés.

## Droits

« Toute info sensible doit tenir compte des droits et rôles. » Tout est vérifié côté serveur (attributs `#[IsGranted]`, `PrimesVoter`, filtrage des requêtes), pas seulement dans les menus.

| Qui | Patrimoine et machines | Primes |
|---|---|---|
| Admin (direction) | tout | tout : barème, évaluations, validation des primes, export paie |
| Droit « Comptabilité et patrimoine » (`ROLE_COMPTABILITE`, case dans l'accès de l'employé) | tout : valeurs, dotations, relevés, export cabinet, Comptabilité | sa propre note seulement |
| Responsable (désigné sur la fiche employé) | ses biens confiés | son équipe : N01 filtré, évaluation et validation de ses agents ; pas de barème, pas de validation des primes |
| Gestion de production | saisie des relevés de compteurs (par l'adresse de la saisie, pas encore dans son menu) | — |
| Agent | les biens qui lui sont confiés, **sans aucun montant** (ni achat, ni cumul, ni valeur nette) | sa note et sa prime dans Mon espace ; aucune autre personne |
| RH sans droit Comptabilité | — | ni score ni critère ; seulement la ligne de prime sur le bulletin |

`ROLE_COMPTABILITE` est donné à l'admin par la hiérarchie des rôles, compte parmi les droits « montants » (`ModulesRoles::montants`) et ouvre désormais Finances › Comptabilité (auparavant admin seul).

## Réglages

- Tuile **Patrimoine et amortissements** (admin, comptabilité) : délai de contrôle des biens (12 mois), jour limite des relevés de compteurs (10 du mois suivant).
- Tuile **Primes de performance** (admin) : appréciation obligatoire avant validation (oui), l'agent voit sa note en temps réel (oui) ; bouton vers le barème.
- Tuile **Machines** : méthode (à l'usage / linéaire), unité d'usage, capacité, valeur résiduelle, suivi par relevé et coûts toner de chaque machine.

## Tâches planifiées (ERP local)

```
0 7 * * * cd /srv/successimprim && php bin/console app:immobilisations:dotations -q >> var/log/dotations.log 2>&1
30 7 * * * cd /srv/successimprim && php bin/console app:primes -q >> var/log/primes.log 2>&1
```

- `app:immobilisations:dotations` dote le mois précédent ; un bien déjà doté est sauté, une machine suivie par relevé attend son relevé, la commande peut donc tourner chaque jour. `--mois=AAAA-MM` rattrape un mois terminé, comme le bouton « Passer » du cadre « Écritures » sous le registre (M04).
- `app:primes` reporte les primes validées sur les bulletins en brouillon.
- Les deux refusent de tourner sur le site en ligne. Leur option `--demo` sert à `app:demo` et refuse la production.

## Ce qui reste

- **Comptes d'essai** : la base de développement a des comptes et des machines d'essai (pas de démo) qui apparaissent dans M01 et N01. Sur la vraie base, désactiver les comptes inutilisés ou leur mettre « Pas de prime ».
- **Ventes à 0** : pour qui a un objectif de ventes, un mois sans vente compte 0 sur le critère Ventes (choix recommandé à Yaya le 14/10/2026 ; l'autre option était « pas de données », poids réparti). Sans objectif, le critère n'est pas noté.
- **Surface des pièces** : sans dimensions sur la ligne de commande, l'usage prend la surface du format (A5, carte 85 × 55…) ; un format « taille unique » ne compte rien.
- **Comptabilité par société** : les dotations sont dans le journal unique en attendant `docs/societes.md`.
- **Relevés par l'atelier** : donner à la gestion de production une entrée vers la saisie des relevés (par exemple une alerte « relevé à saisir » sur son écran Production).
- **Notifications** : pas encore de rappel au responsable pour l'appréciation, ni à la comptabilité pour un relevé manquant (les cartes de M01 et N01 les montrent).
- Les maquettes montrent un bouton « + Nouveau » et un bandeau de synchronisation dans le menu : ils viennent du gabarit commun, hors de ce lot.
