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
| Saisie d'un mouvement (entrée, sortie, virement) | `/gestion/tresorerie/mouvements/nouveau` | Trésorerie (saisir) ou Virements entre comptes ; sans Trésorerie (voir) : aucun solde, retour à la caisse |

Le montant des salaires (échéancier F04, sorties prises en compte et repères de la courbe F05) n'apparaît qu'avec le droit RH : les autres voient « salaires (RH) » à la place. Les totaux et la courbe restent justes, car le montant y est compté.

## La caisse et la trésorerie

Depuis le 15/10/2026, le rôle « Caisse et commercial » n'a plus
« Trésorerie et soldes » (Trésorerie (voir)) par défaut : la Direction le
donne à une personne sur sa fiche (docs/menus-par-role.md). Sans ce droit :

- la caisse du jour et la clôture restent visibles sur l'écran Caisse
  (c'est son tiroir qu'elle compte) ;
- les listes de comptes (paiement d'une commande, remboursement, saisie
  d'un mouvement) donnent le nom et la sorte du compte, jamais le solde ;
- la saisie d'un mouvement (bouton « Virement » de la caisse) ne propose
  que ce que la personne a le droit de faire : entrée et sortie avec
  Trésorerie (saisir), virement avec Virements entre comptes. Après
  l'enregistrement, elle revient à la caisse (ou aux dépenses) avec le
  montant, les comptes et la référence, sans solde ;
- la liste et le détail des mouvements, la prévision (F05), les
  fournisseurs (F04) et le planning annuel des échéances répondent
  « accès refusé ».

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

Frais Orange Money (15/10/2026) : le client paie le fonds de soutien et le frais de retrait en plus du montant (Réglages › Paiements, 1 % chacun par défaut). Le compte marchand reçoit donc le total, frais compris :

- à la caisse, choisir Orange Money montre les deux lignes de frais cochées d'office (la caissière peut les décocher) et le « Total à recevoir » ; la page de paiement d'une commande les coche aussi d'office pour Orange Money. Wave et les autres modes ne changent pas. Le reçu sépare « Pour la commande », « Fonds de soutien », « Frais de retrait » et « Total payé » ;
- un paiement porte son montant (la part de la commande) et ses frais à part ; « Encaissements », « À valider », la commande et la fiche du paiement montrent les frais en plus du montant (et le total reçu) ;
- le rapprochement compare la ligne du relevé au total reçu (montant + frais) : un paiement de 25 000 F avec 500 F de frais se rattache à une ligne de 25 500 F ;
- rattacher une ligne à une commande sans paiement saisi retrouve, quand la ligne dépasse ce qui reste dû, le montant et les frais aux taux du jour (25 500 F → 25 000 F + 250 + 250) ;
- à la validation d'un paiement en ligne, la trésorerie reçoit le montant (vente) et les frais à part, sur le compte « Orange Money en ligne » ; à la caisse, sur le numéro Orange Money choisi. Les frais sont « à prélever » par Orange, ce n'est pas une recette (section suivante). L'annulation d'un paiement validé dans « À valider » reprend les deux. Ni le prix de la commande ni la facture ne changent.

## Orange Money : deux sortes de comptes, frais prélevés au retrait

Explications de Yaya du 15/10/2026.

**Deux sortes de comptes Orange Money.**

- **Le compte de l'API, « Orange Money en ligne »** : il reçoit les paiements faits depuis l'espace client (commandes, acomptes de conception, crédit IA). Il porte la marque « Reçoit les paiements Orange Money en ligne » sur sa fiche (Trésorerie › Comptes › Modifier, compte de type Orange Money). S'il n'existe pas, il est créé au premier paiement en ligne validé (code OM-EN-LIGNE, compte administration : la caisse ne le voit pas et n'en fait rien sortir). Un seul compte porte la marque : la mettre sur un autre compte Orange Money la retire au premier. Si ce compte est désactivé, la validation d'un paiement en ligne est refusée avec un message qui dit quoi faire.
- **Les numéros de la boutique** (paiement à la caisse, transfert direct du client) : rien ne change, la caissière choisit le numéro comme avant. La caisse et le rapprochement du relevé ne proposent jamais le compte en ligne.

Dans Finances › À valider, un paiement Orange Money venu de l'espace client affiche son compte, « Orange Money en ligne », verrouillé : il n'y a rien à choisir. Les autres paiements saisis en ligne (Wave, virement déclaré par le client) gardent le choix du compte, sans le compte en ligne.

**Les frais ne sont pas une recette.** Le client paie en plus du montant le fonds de soutien (1 %) et le frais de retrait (1 %) (Réglages › Paiements). Ils arrivent sur le compte Orange Money avec le paiement, mais Orange les reprend quand l'argent sort du compte (retrait, chèque, transfert). Ils sont donc enregistrés à part, catégorie « Frais Orange Money à prélever » : ni produit ni charge, compte de passage 4712 (créditeurs divers) au journal. Ni 467 ni 4718 : ces comptes du plan SYSCOHADA révisé ne sont pas faits pour ça ; 4712 est un compte de tiers qui revient à zéro quand Orange débite. La même règle vaut pour les deux sortes de comptes, en ligne et à la caisse. Le tableau de bord, Finances (vue d'ensemble, du jour, dépenses, budgets), les statistiques, l'objectif du mois et la rentabilité ne comptent pas ces frais ; les soldes, si. Wave et les autres modes ne changent pas (frais en « autre produit »).

**Exemple.** Une commande de 25 000 F payée avec Orange Money :

1. le compte Orange Money reçoit 25 500 F : 25 000 F de vente et 500 F de frais à prélever. Il affiche « Solde 25 500 F, dont frais Orange à prélever 500 F, transférable 25 000 F » ;
2. on vire 25 000 F à la banque (Orange envoie un chèque) : Orange débite en plus 500 F de fonds de soutien et de frais de retrait ;
3. le compte Orange Money revient à 0, la banque a 25 000 F de plus. Le chiffre d'affaires est de 25 000 F ; les 500 F sont entrés puis sortis sans toucher au résultat.

**Ce qui se voit**, seulement avec Trésorerie (voir) : sur Finances › Trésorerie (« Orange Money : ce qui peut sortir », avec un bouton « Virer » pour qui a Virements entre comptes), dans la vue d'ensemble, Finances du jour, la liste et la fiche des comptes : le solde, « dont frais Orange à prélever » et « transférable ».

- À prélever : les frais payés par les clients qu'Orange n'a pas encore débités ; au moins ce qu'Orange prendrait pour vider le compte quand de l'argent y est entré sans frais suivis (solde d'avant le 15/10, dépôt).
- Transférable : le solde moins ce qui est à prélever.

Le rôle « Caisse et commercial » ne voit jamais ces chiffres ni aucun solde.

**Virer l'argent d'un compte Orange Money** : bouton « Virer » de la trésorerie ou de la fiche du compte, bouton « Virement » de la caisse, ou Trésorerie › Nouveau mouvement, type Transfert. Quand le compte source est un compte Orange Money :

- le montant se remplit avec la part transférable (seulement pour qui voit les soldes) ;
- une ligne « Fonds de soutien et frais de retrait prélevés par Orange » se remplit avec les taux de Réglages › Paiements appliqués au montant ; on la corrige avec ce qu'Orange a vraiment débité ;
- « Référence externe » reçoit le numéro du chèque d'Orange ou de la transaction.

À l'enregistrement : le transfert, plus une sortie « Frais Orange Money à prélever » du compte Orange Money (référence du transfert suivie de -FO). Si Orange débite plus que les frais payés par les clients encore à prélever (argent entré sans frais : frais décochés à la caisse, solde d'avant le 15/10, dépôt), le surplus devient une charge « Frais bancaires » (référence suivie de -FC). Les frais d'avant le 15/10 avaient été comptés en « autre produit » : cette charge les équilibre. Annuler le virement annule aussi ces lignes. Une sortie (décaissement) d'un compte Orange Money a la même ligne.

**Modifier un paiement en attente** (bouton « Modifier » du paiement, droit Encaisser) : les frais suivent la même règle qu'à la caisse (`FraisOrangeMoney::appliquer`), refaits par le serveur à l'enregistrement :

- les cases « Fonds de soutien » et « Frais de retrait » ne se montrent qu'en Orange Money ou Wave ; passer à un autre mode les décoche et remet les frais à 0 ;
- cocher une case calcule son frais aux taux de Réglages › Paiements, décocher le remet à 0 ;
- changer le montant ou le mode recalcule les frais des cases cochées ;
- enregistrer sans rien changer garde les frais déjà posés (ceux d'un paiement en ligne viennent de la transaction Orange Money, aux taux du jour du paiement).

Les frais restent sur le paiement : jamais dans le montant de la commande ni sur la facture.

**Annulation d'un paiement validé** (inchangée) : dans « À valider » ou sur le paiement, l'annulation reprend du compte Orange Money le montant et les frais entrés avec le paiement (25 500 F dans l'exemple). Elle sert à corriger une erreur de saisie.

**Remboursement d'un client** : c'est une dépense ordinaire, payée depuis la caisse ou la banque.

## Questions ouvertes (Yaya)

1. Une dépense doit-elle être liée à un achat ou un bon de commande fournisseur ?
2. Les dettes fournisseurs doivent-elles inclure les charges récurrentes ?
3. Les remboursements d'emprunt sont-ils une charge récurrente ?
4. Rapprochement automatique quand la référence Orange Money est identique, ou confirmation ligne par ligne ?
5. Comment estimer une échéance fiscale qui n'a pas encore de montant ?
6. Format réel de l'export du compte marchand Orange Money.
7. Les demandes d'employés acceptées mais pas encore payées doivent-elles apparaître dans « À décider » ?
8. ~~Frais Orange Money : produit, charge ou à reverser ?~~ Réponse du 15/10/2026 : ni produit ni charge, Orange les reprend quand l'argent sort du compte (section « Orange Money : deux sortes de comptes »).
9. Les frais Wave suivent-ils la même règle qu'Orange Money (repris par Wave au retrait) ?
10. Le relevé importé dans « Encaissements » est-il celui du compte de l'API ou d'un numéro de la boutique ?
11. Faut-il reclasser les frais Orange Money d'avant le 15/10 (comptés en « autre produit ») ?
12. Quand Orange débite plus que les frais payés par les clients, le surplus est-il bien une charge de la boutique (« Frais bancaires ») ?
