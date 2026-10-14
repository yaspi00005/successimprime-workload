# Statistiques : objectifs, exports, rapport de la semaine (jalon J6)

Construit le 14/10/2026 sur l'ERP local. Règle du propriétaire : « toute info sensible doit tenir compte des droits et rôles, tout le monde ne doit pas tout voir ». Chaque droit est vérifié côté serveur : un chiffre caché n'est ni calculé, ni envoyé à la page, ni au fichier exporté, ni au rapport.

| Écran | Adresse | Droit |
|---|---|---|
| Statistiques et rapports (maquette 36) | `/statistiques` | « Chiffre d'affaires global » ; montants de vente : + « Indicateurs commerciaux » ; marge : « Marges et coûts de revient » ; taux d'encaissement : « Paiements » |
| Objectifs du mois | `/statistiques/objectifs` | « Chiffre d'affaires global » (chaque objectif avec le droit de son chiffre) ; fixer les objectifs : Direction |
| Agents, caisse et production | `/statistiques/globales` | « Chiffre d'affaires global » ; montants : + « Indicateurs commerciaux » ; encaissements par caissière : « Paiements » |
| Commandes | `/statistiques/commandes` | « Chiffre d'affaires global » ; chiffre d'affaires : + « Indicateurs commerciaux » ; impayés : « Paiements » |
| Clients | `/statistiques/clients` | « Chiffre d'affaires global » ; chiffre d'affaires par client : + « Indicateurs commerciaux » |
| Trésorerie | `/statistiques/tresorerie` | « Chiffre d'affaires global » et « Trésorerie et soldes » |
| Mes statistiques (maquette U05) | `/mes-statistiques` | « Ses propres statistiques » ; ses objectifs à lui seulement |
| Rapport de la semaine | `/rapport-semaine` | « Chiffre d'affaires global » ou « Ses propres statistiques » |

Libellés des droits de l'écran Rôles et menus (R04). Ils sont réunis dans `App\Service\Statistiques\DroitsStatistiques` : global (ROLE_STATS_GLOBAL), ventes (ROLE_STATS_VENTES, ne vaut qu'avec le précédent), paiements (ROLE_PAIEMENT_VOIR), trésorerie (ROLE_TRESORERIE_VOIR), marges (ROLE_COUT_MARGE), perso (ROLE_STATS_PERSO), admin (ROLE_ADMIN).

## Objectifs du mois

Fixés par la Direction pour chaque mois (de 12 mois en arrière à 3 mois en avance), écran **Objectifs** › « Fixer les objectifs ». Table `objectif`, une ligne par mois, indicateur, axe et cible.

| Indicateur | Axes | Définition |
|---|---|---|
| CA facturé | entreprise, catégorie de produits, agent | factures émises hors pro formas, TTC, à la date de facture ; par agent celui de la commande ; par catégorie réparti au prorata des lignes de la commande |
| Commandes | entreprise, agent | commandes du mois ni supprimées ni entièrement annulées |
| m² imprimés | entreprise, machine | lignes terminées en production dans le mois (surface × quantité), machines facturées au m² |
| Nouveaux clients | entreprise | première commande dans le mois |
| Taux d'encaissement | entreprise | paiements validés du mois (hors solde client) ÷ CA facturé du mois |

Sans objectif fixé : le CA de l'entreprise reprend l'objectif de la tuile Finances et budgets, chaque agent l'objectif de ventes de sa fiche, le taux d'encaissement vise 80 %. Une case vide garde la valeur par défaut ; 0 retire l'objectif. « Reprendre ceux du mois précédent » copie les objectifs saisis le mois d'avant.

Affichage (maquette 36) : réalisé / objectif, part atteinte, jauge avec un trait au rythme attendu du jour, projection en fin de mois au rythme actuel (réalisé ÷ part du mois écoulée, pas avant le deuxième jour), état : en bonne voie (projection ≥ 100 %), à surveiller (≥ 90 %), en retard, atteint, non atteint (mois fini). Les objectifs de l'entreprise sont aussi sur Statistiques et rapports (cartes et repère pointillé sur le graphique du CA par mois) ; ceux d'un agent sur Mes statistiques.

Qui voit quoi : avec « Chiffre d'affaires global », les commandes, m² et nouveaux clients de l'entreprise et par agent ou machine ; le CA (entreprise, catégories, agents) avec en plus « Indicateurs commerciaux » ; le taux d'encaissement avec « Paiements ». Un agent ne voit que ses propres objectifs (Mes statistiques, rapport de la semaine).

## Exports Excel et PDF

Menu **Exporter** en haut de Statistiques et rapports, Objectifs, Agents caisse et production, Commandes, Clients, Trésorerie et Mes statistiques. Le fichier est construit par la même méthode du contrôleur que la page (`donnees()`), pour la même période et les mêmes filtres, avec les mêmes droits : pas de colonne cachée, pas de chiffre en plus.

- Excel (.xlsx) : une feuille par tableau, titre, entreprise, période, « édité le … par … » en tête, ligne d'en-tête figée et filtrable, formats F CFA, %, m² et dates, ligne de total en gras. Un texte n'est jamais lu comme une formule.
- PDF (A4) : en-tête de l'entreprise principale (Réglages › Entreprises, code `mdg_success`), titre, période, pied « Document interne, confidentiel », en paysage au-delà de six colonnes.
- Chaque export est noté dans le journal d'activité (écran, période, format, tableaux, personne).

## Rapport de la semaine

Semaine du lundi 0 h au dimanche 24 h. Chaque personne a **sa** version, recalculée à l'ouverture selon ses droits :

- Direction (« Chiffre d'affaires global ») : commandes, m², nouveaux clients, devis émis et signés, commandes par agent, alertes ; avec « Indicateurs commerciaux », le CA facturé face à l'objectif de la semaine (objectif du mois au prorata des jours), l'avancement du mois au dimanche, les catégories les plus vendues et le CA par agent ; avec « Paiements », l'encaissé, le taux d'encaissement, les créances et factures échues.
- Agent (« Ses propres statistiques ») : ses ventes facturées face à son objectif de la semaine, ses commandes, devis, encaissements et travaux terminés, son avancement du mois.

Alertes, chacune seulement avec le droit de voir son chiffre : commandes en retard, CA de la semaine sous 90 % de l'objectif, projection du mois en retard, taux d'encaissement sous 80 %, créances au-dessus de 20 % du CA des 30 derniers jours, factures échues, devis sans réponse depuis 3 jours ; pour un agent : ses ventes sous 90 % de son objectif, ses devis à relancer.

Page Accueil › **Rapport de la semaine** (semaine dernière par défaut, semaines précédentes sur deux ans) et bouton **Télécharger le PDF** (même contenu).

### Envoi

Tâche `app:rapport-hebdomadaire`, à lancer **chaque heure** sur l'ERP local : elle n'envoie qu'au jour et à l'heure des Réglages, une seule fois par semaine (table `rapport_hebdomadaire`, une ligne par semaine posée avant l'envoi).

- Notification dans l'application à chaque destinataire, avec le lien vers sa version. Le message ne contient aucun montant (« Rapport de la semaine 41 (du 5 au 11 octobre 2026) disponible »), la notification push non plus.
- E-mail avec le PDF de sa version seulement si la case est cochée dans les Réglages **et** que l'envoi d'e-mails est configuré (`MAILER_DSN` autre que `null://`, `MAILER_FROM_ADDRESS` renseigné) et que l'employé a une adresse. Jamais de SMS ni de WhatsApp.
- Destinataires : la Direction (comptes avec « Chiffre d'affaires global ») si « Envoyer à la direction » est coché ; les agents (« Ses propres statistiques ») si « Envoyer aux agents » est coché et qu'ils ont eu une activité ou ont un objectif.

```
php bin/console app:rapport-hebdomadaire                       # à l'heure prévue, une fois par semaine
php bin/console app:rapport-hebdomadaire --dry-run             # liste des destinataires et de leur version, rien n'est envoyé
php bin/console app:rapport-hebdomadaire --semaine=2026-W41    # une semaine terminée précise, sans attendre l'heure prévue
```

- Linux ou Mac : `0 * * * * cd /chemin/du/projet && php bin/console app:rapport-hebdomadaire -q`.
- Windows : une tâche planifiée toutes les heures qui lance la même commande.

### Réglages

Réglages › Paramètres › tuile **Objectifs et rapport de la semaine** (Direction seulement) : envoi oui ou non, jour (lundi par défaut) et heure (8 h), envoi à la direction, envoi aux agents, PDF par e-mail. « Ouvrir la page complète » mène à l'écran Objectifs.

## Soldes d'ouverture

Les créances reprises à l'import (commandes « solde d'ouverture », docs/import-donnees.md) ne sont pas des ventes : elles sont hors de tous les chiffres de ces écrans (CA, commandes, nouveaux clients, objectifs, Mes statistiques, rapport de la semaine, exports) et donc des primes. Leur règlement reste un encaissement réel dans la trésorerie et la caisse, mais il n'entre pas dans le taux d'encaissement des objectifs.

## ERP local seulement

Les objectifs, les exports et le rapport de la semaine n'existent que sur l'ERP local : sur le serveur en ligne (`APP_EN_LIGNE=1`), ces adresses répondent 404 (`StatistiquesHorsLigneSubscriber`) et la tâche refuse de tourner. Les tables `objectif` et `rapport_hebdomadaire` ne sont pas synchronisées. Les écrans de statistiques qui existaient déjà en ligne y restent, sans objectifs ni menu Exporter.

## Droits resserrés sur les écrans existants

- Trésorerie (`/statistiques/tresorerie`) demande « Trésorerie et soldes », comme Finances › Trésorerie ; son lien n'apparaît qu'avec ce droit.
- Commandes : les encaissements et décaissements ne sont plus envoyés au navigateur ; le CA demande « Indicateurs commerciaux », les impayés « Paiements ».
- Agents, caisse et production : montants des ventes avec « Indicateurs commerciaux », encaissements par caissière avec « Paiements ».
- Clients : CA par client avec « Indicateurs commerciaux » (sinon nombre de commandes, trié sur ce nombre).
- Mes statistiques : le « CA global de l'entreprise » demande aussi « Indicateurs commerciaux ».

## Reste à faire

- Devis signés : la date d'acceptation d'un devis n'est pas gardée ; un devis compte comme signé dans la semaine de sa commande, ou accepté et daté de la semaine.
- Les créances, factures échues et commandes en retard du rapport sont la situation au moment du calcul, pas à la fin de la semaine consultée.
- Le taux d'encaissement compare les paiements de la période (factures anciennes comprises) au CA facturé de la période : il peut dépasser 100 %.
- Vue d'ensemble de Finances et Finances du jour gardent l'objectif de CA de la tuile Finances et budgets ; à brancher sur l'objectif du mois.
- Graphiques des anciens écrans (Agents, Commandes, Clients, Trésorerie) : Chart.js chargé depuis un CDN, vide sans internet.
- Pas encore d'objectif par agent pour les m² ni de rapport mensuel.
