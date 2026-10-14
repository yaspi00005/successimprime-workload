# Finances (maquettes F01 à F07)

Module Finances de l'ERP local, construit le 13/10/2026. Tout reste au local : rien de ces écrans ne part vers le site en ligne.

| Écran | Adresse | Droit |
|---|---|---|
| F01 Vue d'ensemble du mois | `/finances` | un des droits Trésorerie (voir), Statistiques globales, Paiements (voir), Coûts et marges ; chaque chiffre n'est calculé que si la personne a le droit correspondant |
| F02 Encaissements et rapprochement Orange Money | `/finances/encaissements` | Paiements (voir) ; import du relevé : Paiement (encaisser) |
| F03 Dépenses et budgets, reçu, approbation | `/finances/depenses` | Trésorerie (voir ou saisir) ; approbation : Direction |
| F04 Fournisseurs et échéancier | `/finances/fournisseurs` | Trésorerie (voir) |
| F05 Prévision de trésorerie à 90 jours | `/finances/prevision` | Trésorerie (voir) |
| F06 Rentabilité par commande, produit, machine | `/finances/rentabilite` | Coûts et marges |
| F07 Finances du jour (téléphone) | `/finances/jour` | comme F01 |

Le montant des salaires (échéancier F04, sorties prises en compte et repères de la courbe F05) n'apparaît qu'avec le droit RH : les autres voient « salaires (RH) » à la place. Les totaux et la courbe restent justes, car le montant y est compté.

## Soldes d'ouverture

Les soldes de départ (ce que les clients devaient, l'argent des comptes, ce que la boutique devait aux fournisseurs) se reprennent par Réglages › Import des données (docs/import-donnees.md) :

- une **dette fournisseur** devient une dépense « à payer », catégorie matières, marquée « Solde d'ouverture » : elle est dans l'échéancier (F04) et la prévision (F05), se paie comme les autres, mais reste **hors budgets et hors dépenses du mois** (ce n'est pas une charge de la période) ;
- le solde d'un **compte de trésorerie** devient son solde initial (compte sans aucune opération seulement) ;
- une **créance client** devient une commande « solde d'ouverture » sans lignes ni facture : elle est dans les créances et les encaissements quand elle est réglée, jamais dans le CA ni la rentabilité (F06).

Chaque solde fait une écriture d'à-nouveaux (journal « AN », contrepartie 471) dans l'export comptable.

## Réglages

Réglages › Paramètres › tuile **Finances et budgets** : objectif de CA du mois (9 000 000 F par défaut), seuil d'alerte de trésorerie (2 000 000 F), seuil d'approbation des dépenses (100 000 F), coût d'une heure de main d'œuvre (1 000 F). À ajuster par la direction.

## Tâche planifiée

Une fois par jour, sur l'ERP local :

```
php bin/console app:finances:alerte-tresorerie
```

Elle prévient la direction (notification dans l'ERP, aucun SMS ni WhatsApp) quand la prévision prudente à 90 jours passe sous le seuil d'alerte.

- Linux ou Mac : `0 7 * * * cd /chemin/du/projet && php bin/console app:finances:alerte-tresorerie -q`.
- Windows : une tâche planifiée quotidienne qui lance la même commande.

## Relevé Orange Money

Export CSV du compte marchand (colonnes Date, Référence, Montant, et si possible Numéro, Nom, Type, Statut). Les envois, retraits et échecs sont écartés ; une transaction déjà importée n'est pas reprise ; le fichier n'est pas conservé. Le format exact du vrai export reste à confirmer avec un fichier réel.

## Questions ouvertes (Yaya)

1. Une dépense doit-elle être liée à un achat ou un bon de commande fournisseur ?
2. Les dettes fournisseurs doivent-elles inclure les charges récurrentes ?
3. Les remboursements d'emprunt sont-ils une charge récurrente ?
4. Rapprochement automatique quand la référence Orange Money est identique, ou confirmation ligne par ligne ?
5. Comment estimer une échéance fiscale qui n'a pas encore de montant ?
6. Format réel de l'export du compte marchand Orange Money.
7. Les demandes d'employés acceptées mais pas encore payées doivent-elles apparaître dans « À décider » ?
