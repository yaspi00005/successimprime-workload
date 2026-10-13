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

Sur tablette (768 à 1023 px), le menu est toujours en icônes.

## Téléphone (R05)

La barre du bas suit le rôle :

- Direction : Résumé, Ventes, Caisse, Clients, Plus ;
- caisse : Accueil, Caisse, Actions, Clients, Plus ;
- livreur : Tournée, Livrer, Pointage, Moi.

Règle : les quatre premiers écrans du menu court (la tournée remplace
l'accueil pour qui en a une), le pointage s'il reste une place, puis
« Moi » quand la barre montre déjà tout le menu, sinon « Plus ».

## Rôles et menus (R04)

Une ligne par module, une colonne par rôle. Cliquer sur une case la fait
passer à l'état suivant :

- **Modifier** : tous les droits du module ;
- **Voir** : la personne consulte sans rien changer. Pour l'atelier et les
  finances, c'est le droit de lecture qui existait déjà (planning, soldes).
  Pour les ventes, les clients et le montage PAO, c'est une restriction
  « lecture seule » : tout envoi de formulaire et tout écran de création ou
  de modification du module répond « accès refusé », et un bandeau le dit
  en haut de la page ;
- **—** : le module disparaît du menu de ce rôle.

Le stock et les achats (prix d'achat) et les réglages restent à la
Direction ; « Mon espace » est ouvert à tout le personnel. L'équipe et la
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

## Pour les développeurs

- Menu : `NavigationExtension::menu()` (volets ou court), `MENU_COURT`,
  `barreMobile()` ; gabarits `partials/_menu.html.twig` et
  `partials/_barre_mobile.html.twig`.
- Pastilles : `App\Service\PastillesMenu`.
- Matrice : `App\Security\ModulesRoles` ; lecture seule :
  `App\EventSubscriber\LectureSeuleSubscriber` (droits
  `ROLE_LECTURE_VENTES`, `ROLE_LECTURE_CLIENTS`, `ROLE_LECTURE_PAO`).
- Forme du menu : colonne `role_utilisateur.menu` (`volets`, `court`,
  `replie`).
