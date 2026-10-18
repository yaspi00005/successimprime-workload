# Menus selon le rôle (maquettes R01 à R05)

Chaque personne voit un menu taillé pour son travail. La forme du menu se
règle par rôle dans Réglages › Utilisateurs et droits › Rôles et menus.

## Les trois formes

| Forme | Pour qui (par défaut) | Ce qu'on voit |
|---|---|---|
| Volets | Direction (toujours) | Les 7 modules (Accueil, Ventes, Clients, Atelier, Finances, Équipe, Réglages). Un seul volet ouvert à la fois ; la barre d'onglets au-dessus de la page est masquée tant que le menu est ouvert. |
| Court | Caisse, production, livreur | Les écrans du quotidien à plat (Ma journée, Caisse, Mes actions, Clients, Devis et commandes, Encaissements…) puis « Mon espace ». |
| Replié | Graphiste PAO | Le menu court en icônes ; le libellé et les sous-écrans s'ouvrent au survol. « » » le rouvre. |

Commun à toutes les formes :

- carte de la personne (nom et rôle) en haut du menu ;
- pastilles jaunes : ce qui attend une décision (paiements à valider,
  actions de caisse, commandes en ligne à confirmer, demandes du personnel),
  comptées seulement pour qui a le droit d'ouvrir l'écran ;
- « Aller à… » (Ctrl K) : toutes les pages autorisées, filtrées au clavier
  (flèches, Entrée, Échap), et la recherche dans les clients, commandes et
  factures ;
- « Replier le menu » : le choix est gardé sur l'appareil, par forme de menu.
- « ? Aide » en haut de chaque écran (ERP local seulement) : le guide des
  fiches que le rôle peut suivre (`docs/guide-utilisateur.md`), sans entrée de
  plus dans le menu de gauche ; aussi « Aide et guide » dans le menu de la personne.

Sur tablette (768 à 1023 px), le menu est toujours en icônes.

## Livreur seul

Le livreur dont le seul droit est « Livraisons » ne voit que sa tournée et
ses livraisons (décision de Yaya du 15/10/2026,
`docs/documents-livraisons.md`) : son menu a « Ma tournée » et « Mes
livraisons », puis « Mon espace » sans « Mes statistiques ». Il n'a ni la
recherche, ni la messagerie, ni « Aller à… ». Les autres pages lui sont
refusées même tapées à la main. Avec un droit de plus coché pour lui, il
retrouve le menu de ses droits.

« Suivi des livraisons » (menu Ventes) est dans le menu de la Direction, de
la caisse (droit Commandes) et de la prépresse.

## Poste du graphiste (R03)

Atelier › Conception PAO › « Poste » (`/atelier/pao/poste`) : trois volets,
avec le menu replié pour laisser la place au fichier.

- À gauche, « Mes travaux » : ceux de la personne et ceux que personne n'a
  pris, celui en cours d'abord, puis par échéance, ceux chez le client à la
  fin. Pastille de délai verte, orange, rouge ou bleue (attente client).
- Au centre, l'aperçu de la dernière version préparée par l'atelier (sinon
  le fichier du client), avec la version, le format et le fond perdu mesuré ;
  zoom et plein écran.
- À droite, le chronomètre (qui tourne en direct), Démarrer / Pause,
  l'échéance du BAT et les échanges (brief, retours du client, versions
  envoyées), la réponse du client quand le BAT est chez lui, et le lien vers
  les heures et les points.
- « Envoyer le BAT » en haut à droite.

Les cartes du tableau de conception ouvrent ce poste.

## Téléphone (R05)

La barre du bas suit le rôle :

- Direction : Résumé, Ventes, Caisse, Clients, Plus ;
- caisse : Accueil, Caisse, Actions, Clients, Plus ;
- livreur : Tournée, Livraisons, Pointage, Moi.

Règle : les quatre premiers écrans du menu court (la tournée remplace
l'accueil pour qui en a une), le pointage s'il reste une place, puis
« Moi » quand la barre montre déjà tout le menu, sinon « Plus ».

Contrôle prépresse d'un travail (`/pre-presse/travail/{id}`) : sous
1 280 px de large, la version affichée, « Envoyer le BAT au client » et
« Monter » (tablette seulement) passent de l'en-tête à une barre au-dessus
de l'aperçu ; l'aperçu, les cartes et les boutons se suivent l'un sous
l'autre. Sur grand écran, rien ne change.

## Rôles et menus (R04)

Une ligne par module, une colonne par rôle. Cliquer sur une case la fait
passer à l'état suivant :

- **Modifier** : tous les droits du module ;
- **Voir** : la personne consulte sans rien changer. Pour l'atelier et les
  finances, c'est le droit de lecture qui existait déjà (planning, soldes).
  Pour les ventes, les clients, le montage PAO et la corbeille, c'est une
  restriction « lecture seule » : tout envoi de formulaire et tout écran de création ou
  de modification du module répond « accès refusé », et un bandeau le dit
  en haut de la page ;
- **—** : le module disparaît du menu de ce rôle.

Le stock et les achats (prix d'achat) et les réglages restent à la
Direction ; la corbeille aussi par défaut, et le module « Corbeille »
permet de la confier à un rôle, qui n'y voit que les fiches de ses autres
droits (docs/corbeille.md) ; « Mon espace » est ouvert à tout le personnel. L'équipe et la
paie n'ont pas de « Voir » : les salaires restent à l'équipe RH et à la
personne concernée.

Les montants (paiements reçus, marges et coûts, indicateurs, chiffre
d'affaires) se règlent à part : on peut les masquer même quand le module
est visible.

Une astérisque sur « Modifier » signale un rôle qui n'a qu'une partie des
droits du module (cochés à la main dans les droits détaillés). Chaque
changement est enregistré dans le journal d'activité (avant, après,
module, niveau).

Un aperçu du menu du rôle choisi (clic sur le nom du rôle) est affiché à
droite.

## Rôle « Caisse et commercial » : sans les soldes des comptes

Décision de Yaya (15/10/2026) : le rôle caisse ne voit plus la trésorerie
ni les soldes des comptes. Ses droits par défaut sont : clients, devis,
commandes, factures, encaisser et voir les paiements, journal et clôture
de caisse, dépenses et recettes (saisir), virements entre comptes, ses
statistiques et les indicateurs commerciaux. « Trésorerie et soldes » n'y
est plus (migration `Version20261015090000`, pour les bases existantes
comme pour une nouvelle installation).

Dans la matrice, la case Finances du rôle caisse affiche donc
« Modifier* » : il a la saisie et les virements, pas la lecture des
soldes. La Direction donne la trésorerie à une personne précise sur sa
fiche (Réglages › Utilisateurs et droits, droits détaillés : « Trésorerie
et soldes » ajouté), comme pour `demo.fanta` ; ce droit ajouté n'est pas
touché par la migration.

Ce que la caisse garde sans ce droit :

- Caisse : encaisser, le reçu, la caisse du jour (fond, encaissé, sorties,
  théorique) et la clôture de sa caisse : compter son tiroir fait partie
  de son travail, ce n'est pas le solde des comptes ;
- Finances › Dépenses : saisir une dépense et voir les siennes seulement
  (« Aller à… › Nouveau › Dépense » ouvre ce formulaire, pour tout le
  monde) ;
- Caisse › « Virement » (et « Aller à… › Nouveau › Virement entre
  comptes ») : le formulaire de saisie des mouvements (entrée, sortie,
  virement), par exemple verser la caisse du jour sur le compte de la
  boutique. Les comptes y sont proposés par leur nom, sans aucun solde, et
  l'enregistrement ramène à la caisse avec un message (montant, comptes,
  référence). Les règles des comptes ne changent pas, et les listes ne
  proposent que les comptes que l'enregistrement acceptera pour le type
  choisi : l'argent sort de sa caisse personnelle ou d'un compte partagé
  (jamais de la banque ni de la caisse d'un collègue) ; il va vers une
  caisse personnelle, un compte partagé ou la banque pour un virement,
  vers sa caisse ou un compte partagé pour une entrée ;
- payer une demande de remboursement : comptes proposés sans solde.

Ce qu'elle ne voit plus (« accès refusé », et absent de son menu) :
Finances › Trésorerie (prévision à 90 jours et son export), Fournisseurs,
la liste et le détail des mouvements (même les siens), le planning annuel
des échéances et les tâches fiscales ou de paie qui ne la concernent pas.

## Pour les développeurs

- Menu : `NavigationExtension::menu()` (volets ou court), `MENU_COURT`,
  `barreMobile()` ; gabarits `partials/_menu.html.twig` et
  `partials/_barre_mobile.html.twig`.
- Pastilles : `App\Service\PastillesMenu`.
- Matrice : `App\Security\ModulesRoles` ; lecture seule :
  `App\EventSubscriber\LectureSeuleSubscriber` (droits
  `ROLE_LECTURE_VENTES`, `ROLE_LECTURE_CLIENTS`, `ROLE_LECTURE_PAO`,
  `ROLE_LECTURE_CORBEILLE`).
- Forme du menu : colonne `role_utilisateur.menu` (`volets`, `court`,
  `replie`).
