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
| Soldes clients : chez qui l'argent est resté, dépôts en ligne à valider | `/clients/soldes` (bouton « Soldes clients » de la caisse) | Paiement (encaisser) ; export CSV : Paiements (voir) |
| Compte du client (relevé, dépôt, argent rendu) | fiche client › onglet « Compte », caisse | voir « Compte du client » plus bas |
| Saisie d'un mouvement (entrée, sortie, virement) | `/gestion/tresorerie/mouvements/nouveau` | Trésorerie (saisir) ou Virements entre comptes ; sans Trésorerie (voir) : aucun solde, retour à la caisse |

Le montant des salaires (échéancier F04, sorties prises en compte et repères de la courbe F05) n'apparaît qu'avec le droit RH : les autres voient « salaires (RH) » à la place. Les totaux et la courbe restent justes, car le montant y est compté.

## Qui voit les encaissements et les soldes dus (18/10/2026)

- **Encaissements** (`/finances/encaissements`) : droit Paiements (caisse, Direction, comptable). Les graphistes PAO n'y ont plus accès : la migration `Version20261018220000` leur retire le droit Paiements ajouté sur leur fiche au passage aux droits par personne. Leur menu Finances montre « Factures et créances » à la place, et l'adresse les renvoie aux créances.
- **Solde dû des clients** (liste et fiche client) : droit Paiements, donc la caisse, la Direction et le comptable (« Comptabilité et patrimoine » donne aussi le droit Paiements, `security.yaml`). Les autres rôles ne le voient pas.

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

Les soldes de départ (ce que les clients devaient, l'argent des comptes, ce que l'imprimerie devait aux fournisseurs) se reprennent par Réglages › Import des données (docs/import-donnees.md) :

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
- **Les numéros de l'imprimerie** (paiement à la caisse, transfert direct du client) : rien ne change, la caissière choisit le numéro comme avant. La caisse et le rapprochement du relevé ne proposent jamais le compte en ligne.

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

**Remboursement d'un client** : l'argent laissé sur son compte (monnaie gardée, dépôts) se rend par « Rendre l'argent » (section « Compte du client ») ; un autre remboursement reste une dépense ordinaire, payée depuis la caisse ou la banque.

## Compte du client : monnaie gardée et dépôts (construit le 18/10/2026)

Demande de Yaya du 16/10/2026 : « Il faut tenir compte également des monnaies non rendues du client. Où le client peut faire le dépôt sur son compte sans être utilisé. Et ça sera visible dans l'espace client. Également dans la partie administration. »

Le client a un compte à l'imprimerie : l'argent qu'il y laisse (la monnaie qu'il ne reprend pas, un dépôt) paie ses prochaines commandes, ou lui est rendu. Le solde est celui qui existait déjà (`Clients.soldeCredit`, l'« avoir » de « Factures et paiements » en ligne) ; chaque mouvement écrit maintenant une ligne de relevé (`mouvement_solde_client`) : date, opération, montant, solde après, mode, référence, qui l'a fait, et au local seulement la note ou le motif. Le service `App\Service\Paiement\SoldeClient` est le seul à faire monter ou baisser ce solde (avec l'import des soldes d'ouverture) ; il verrouille la fiche du client avant chaque sortie, si bien que **le solde ne descend jamais sous zéro**, même avec deux caisses en même temps.

**Disponible.** Ce que le client peut encore utiliser : son solde moins l'avoir déjà engagé en ligne et pas encore validé par l'imprimerie (repris de `AvoirClient`). La caisse et la fiche affichent « dont X déjà engagés en ligne » quand il y en a.

| Opération | Où | Droit | Reçu |
| --- | --- | --- | --- |
| Garder la monnaie | Caisse, paiement en espèces : « Montant remis par le client », puis la case « Garder la monnaie sur le compte du client » | Paiement (encaisser) | le reçu du paiement dit « Monnaie gardée sur votre compte : X F » et le solde |
| Dépôt à l'imprimerie | Fiche client › Compte › « Dépôt », ou bouton « Dépôt » de la caisse ; espèces, Orange Money ou Wave, compte qui reçoit, référence | Paiement (encaisser) | reçu « Dépôt sur votre compte » D-000123 |
| Payer avec le solde | Caisse, « Payer avec le solde » ; page de paiement d'une commande, mode « Solde client » | Paiement (encaisser) | reçu du paiement |
| Utiliser l'avoir en ligne | Espace client, « Factures et paiements » : paiement « AV … » en attente, validé dans Finances › À valider | Direction (À valider) | reçu du paiement |
| Dépôt Orange Money en ligne | Espace client, « Mon solde » ; arrive « reçu » au local, validé ou refusé sur la fiche › Compte ou dans Finances › À valider | Paiement (encaisser) sur la fiche ; Direction dans À valider | — |
| Rendre l'argent | Fiche client › Compte › « Rendre l'argent » : montant, compte d'où sort l'argent, motif obligatoire, case de confirmation | « Rendre l'argent du compte d'un client » (Direction et rôle « Caisse et commercial » par défaut, depuis le 18/10/2026) **et** Paiement (encaisser) | reçu « Argent rendu » RB-000123, à faire signer |

Règles :

- **Monnaie gardée** : le paiement reste celui de la commande ; la monnaie entre dans la même caisse (l'argent est dans le tiroir) comme « avance client ». Seulement en espèces, seulement si le client a remis plus que le montant payé ; le serveur refait le calcul.
- **Dépôt** : aucun paiement ni facture. Orange Money ou Wave : les frais payés par le client en plus (case cochée d'office) entrent à part (« Frais Orange Money à prélever », ou « autre produit » pour Wave comme aujourd'hui) et **ne vont jamais au solde**. Le compte « Orange Money en ligne » n'est pas proposé.
- **Payer avec le solde** : tout ou partie, jamais plus que le reste dû ni que le disponible. Le paiement « solde client » est validé tout de suite et ne touche aucun compte de trésorerie (l'argent est déjà à l'imprimerie). L'annuler (« À valider » ou fiche du paiement) remet le montant sur le compte (ligne « Paiement par le solde annulé »).
- **Dépôt en ligne** : jamais validé tout seul. Orange confirme, le dépôt monte au local « reçu » ; une notification prévient la caisse (sans montant). La caisse vérifie l'arrivée de l'argent sur le compte « Orange Money en ligne », puis valide (le solde monte du montant, le compte en ligne reçoit le montant et les frais à part) ou refuse avec un motif (le motif reste au local ; l'argent se règle alors avec le client hors application).
- **Rendre l'argent** : jamais plus que le disponible, seulement depuis une caisse, Orange Money ou Wave (jamais depuis le compte « Orange Money en ligne » ni la banque, dont la sortie attendrait la validation alors que le solde baisserait tout de suite). Sans le droit, le bouton n'apparaît pas et l'adresse répond « accès refusé ».
- Un employé sans Paiements (voir) ni Paiement (encaisser) ne voit pas l'onglet « Compte » de la fiche. Le site en ligne ne modifie aucun compte : boutons absents, adresses refusées.

**Écrans.** Fiche client › onglet « Compte » : solde, disponible, boutons, dépôts en ligne à valider, relevé (100 dernières lignes). Caisse : le solde du client choisi, « Payer avec le solde », « Dépôt », lien vers le relevé. « Soldes clients » (bouton de la caisse) : dépôts en ligne à valider, clients avec un solde, filtres (nom, code ou téléphone ; solde d'au moins ; particulier ou entreprise), total, export CSV (Paiements (voir)). Finances › À valider : une ligne « Dépôt sur le compte client » par dépôt en ligne reçu, compte « Orange Money en ligne » imposé.

**Comptabilité.**

- L'argent qui entre (monnaie gardée, dépôt, dépôt en ligne validé) ou sort (argent rendu) passe en trésorerie dans la catégorie « Avance client (argent laissé sur son compte) » : au journal, le compte de trésorerie contre **4191 Clients, avances et acomptes reçus**, avec le nom du client. Ni vente ni charge : hors chiffre d'affaires, résultat, budgets et « Dépenses du mois » de la trésorerie ; compté dans la caisse du jour, les soldes des comptes et les entrées et sorties d'argent de la trésorerie (c'est de l'argent réellement reçu ou rendu). Cette catégorie ne se choisit pas à la main. Un paiement avec le solde n'est pas un nouvel encaissement d'argent (l'argent est entré au dépôt) : il ne compte pas dans « Encaissements » de la trésorerie. En revanche, depuis la réponse de Yaya du 18/10/2026, il **compte dans l'encaissé des objectifs** (taux d'encaissement, rapport de la semaine), au mois où il est enregistré ; le dépôt lui-même n'y compte pas.
- Payer avec le solde : opération diverse (pièce SC-000123), 4191 au débit, 411 au crédit lettré avec la facture de la commande ; l'annulation passe l'écriture inverse.
- Une avance d'un client reprise en solde d'ouverture va maintenant en 4191 (au lieu de 411) et écrit une ligne « Solde d'ouverture » au relevé.
- Depuis la migration, chaque mouvement du compte passe au journal : le solde de 4191 suit le total des soldes clients (vérifié sur la démonstration : 77 000 F des deux côtés après les essais). Un paiement avec le solde fait avant le 18/10/2026 (ancien bouton « Utiliser … de ce solde ») n'était pas au journal : s'il y en a, l'écart entre 4191 et « Soldes clients » se corrige par une opération diverse.

**Paiements avec le solde d'avant le 18/10** (réponse de Yaya du 18/10/2026). L'ancien bouton « avoir » baissait le solde sans écriture : ni le 4191 ni le 411 ne bougeaient. La commande `app:solde-client:aligner-4191` (à lancer une fois sur l'ERP de l'imprimerie, voir docs/mise-en-production.md) passe pour chacun de ces paiements une opération diverse datée du jour où elle est lancée : 4191 au débit, 411 au crédit (lettrée avec la facture de la commande), pièce `OD-SC-<numéro du paiement>`, libellé « Reprise : payé avec le solde le … ». Ces lignes sont gardées dans la table `ecriture_diverse` et paraissent au journal OD, à la balance et au grand livre comme les autres. Relancée, la commande ne repasse rien ; `--dry-run` montre la liste sans rien écrire ; à la fin elle compare le 4191 et le total des soldes clients, et signale un écart qui viendrait d'ailleurs sans le corriger.

**Reprise.** La migration `Version20261018140000` écrit pour chaque client qui avait déjà un solde une ligne « Solde déjà sur la fiche » (le relevé part de là) et reclasse en « avance client » les anciens mouvements « Monnaie non rendue — … ». Elle passe aussi sur le site en ligne : l'identifiant de cette ligne est tiré de celui du client, si bien qu'elle est la même des deux côtés (une initialisation la met à jour au lieu de la doubler).

**Synchronisation** (docs/synchronisation.md). Le relevé descend en lecture seule (`solde_mouvement` : type, montant, solde après, mode, référence, date ; ni l'employé, ni la note, ni le paiement). Les dépôts en ligne (`solde_depot`) montent une fois reçus par Orange, arrivent toujours « reçu » au local, et la décision redescend (vérifié le 18/10/2026 entre les deux bases d'essai).

**Ce qui a été repris de l'existant.** Le solde `soldeCredit` et son garde-fou à zéro ; le paiement mode « solde client » et sa validation (`PaiementService::validerAvoir`, désormais délégué au compte du client) ; l'avoir de « Factures et paiements » (`AvoirClient`, réservations et paiements « AV ») ; la transaction Orange Money et ses frais (`OrangeMoney`, `FraisOrangeMoney`, compte « Orange Money en ligne ») ; « À valider » ; `MouvementTresorerieService` ; le journal comptable ; la page « Soldes clients » qui existait déjà.

**Démonstration** (`app:solde-client:demo`, lancée par `app:demo`) : Coumba Bagayoko (DEMO-0122) monnaie gardée, dépôt, une partie d'une commande payée avec le solde ; Amadou Sow (DEMO-0195, particulier de l'espace) dépôt en espèces et un dépôt Orange Money en ligne à valider ; Salon Espoir (DEMO-0002) dépôt Orange Money, argent rendu en partie, un dépôt en ligne du payeur à valider.

Captures : `/mnt/project-files/captures/compte-client/`.

## Encaisser plusieurs commandes (construit le 19/10/2026)

Demande de Yaya : un client qui doit plusieurs commandes apporte une somme (200 000 F pour 250 000 F dus) ; la caisse la répartit sur ses commandes.

- Caisse › choisir le client › « Encaisser sur plusieurs commandes » (dès qu'il a au moins deux commandes à régler). Ses commandes sont cochées, de la plus ancienne à la plus récente.
- On tape le montant reçu : il se répartit tout seul, la plus ancienne d'abord (chaque commande soldée tant que l'argent suffit, la suivante reçoit le reste). On peut décocher une commande ou corriger un montant ; le bouton reste grisé tant que le total réparti ne fait pas le montant reçu, ou qu'un montant dépasse le reste dû.
- Un paiement par commande (`EncaissementGroupe`), validé tout de suite comme une commande seule (espèces, Orange Money), en attente pour un virement ou un chèque, toujours provisoire saisi en ligne. Mouvement de trésorerie, reste et état de la commande, facture comptabilisée : comme un paiement seul. Les paiements partagent une référence `G-XXXXXX` (observation du paiement, référence du mouvement quand rien n'est saisi).
- Plus que dû : rendre la monnaie, ou la garder sur le compte du client (dépôt, à la caisse de l'atelier seulement).
- Un seul reçu 80 mm (`/caisse/recu-groupe/G-XXXXXX`) : chaque commande, son montant, son reçu R-, soldée ou le reste, le total et le reste dû.
- Les frais Orange Money payés en plus par le client ne se saisissent pas ici : pour eux, encaisser commande par commande.

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
10. Le relevé importé dans « Encaissements » est-il celui du compte de l'API ou d'un numéro de l'imprimerie ?
11. Faut-il reclasser les frais Orange Money d'avant le 15/10 (comptés en « autre produit ») ?
12. Quand Orange débite plus que les frais payés par les clients, le surplus est-il bien une charge de l'imprimerie (« Frais bancaires ») ?
13. Compte du client : faut-il un plafond au solde d'un client (dépôt maximum), comme les 2 000 000 F d'un dépôt en ligne ?
14. Compte du client : un dépôt refusé après l'arrivée de l'argent est rendu comment (Orange Money, espèces) ? Aujourd'hui, l'imprimerie le règle avec le client hors de l'application.
15. ~~Compte du client : le rôle « Caisse et commercial » doit-il pouvoir rendre l'argent ?~~ Réponse du 18/10/2026 : oui, le droit est donné d'office au rôle Caisse (migration `Version20261018175000`) ; la Direction peut le retirer à une personne sur sa fiche.
16. ~~Compte du client : une commande payée avec le solde compte-t-elle dans l'encaissé des objectifs ?~~ Réponse du 18/10/2026 : oui, au mois où le paiement est enregistré (`Objectifs::encaisse`). Le dépôt n'y compte pas.
