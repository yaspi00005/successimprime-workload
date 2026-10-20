# Utilisateurs, rôles et droits

L'application a trois sortes de comptes, chacune sur son pare-feu (`config/packages/security.yaml`) :

| Compte | Entité | Pare-feu | Connexion |
|---|---|---|---|
| Personnel | `User` (table `user`) | `main` | identifiant et mot de passe sur `/login` (`UserAuthenticator`), ou code PIN sur le téléphone enregistré (`/acces/pin`, docs/employes-acces.md). En ligne, en plus : appareil autorisé et code de sécurité (`AccesEnLigneSubscriber`) |
| Client | `CompteClient` (rattaché à un `Client`) | `espace` | téléphone et code reçu, ou mot de passe (`EspaceClientAuthenticator`, docs/espace-client.md) ; rôle unique `ROLE_ESPACE_CLIENT` |
| Programme | jeton d'API | `api` | en-tête `Authorization: Bearer …` (`JetonApiAuthenticator`, docs/api.md), ERP local seulement |

La synchronisation n'a pas de compte : ses requêtes sont signées (chapitre « Deux installations »).

## Les droits du personnel

Un droit est un rôle Symfony `ROLE_…`. La liste complète est dans `src/Security/Droits.php` (`catalogue()`), reprise dans `droits.catalogue` du guide avec son libellé, son groupe et sa description. Il y a trois groupes :

- **Fonctions** : ce que la personne fait (clients, devis, commandes, factures, encaisser, trésorerie, prépresse, production, livraisons, paramètres…) ;
- **Statistiques et chiffres** : ce qu'elle voit en montants (`ROLE_STATS_PERSO`, `ROLE_STATS_VENTES`, `ROLE_STATS_GLOBAL`, `ROLE_COUT_MARGE`, `ROLE_PAIEMENT_VOIR`, `ROLE_TRESORERIE_VOIR`, `ROLE_COMPTABILITE`) ;
- **Lecture seule** : les restrictions « Voir » (`ROLE_LECTURE_VENTES`, `ROLE_LECTURE_CLIENTS`, `ROLE_LECTURE_PAO`, `ROLE_LECTURE_CORBEILLE`).

### Comment se calculent les droits d'une personne

1. La personne a un **rôle** (entité `RoleUtilisateur`, table `role_utilisateur`, Réglages › Rôles et droits). Le rôle porte une liste de droits et la forme du menu (`volets`, `court`, `replie`).
2. Sur sa fiche, la Direction peut **ajouter** ou **retirer** des droits à la personne seule (`User::droitsAjoutes`, `User::droitsRetires`).
3. `User::getDroitsEffectifs()` donne : droits du rôle + droits ajoutés − droits retirés, filtrés par `Droits::filtrer()` (un code inconnu est ignoré).
4. `User::getRoles()` ajoute ces droits aux rôles enregistrés, puis `ROLE_USER`. C'est ce que Symfony utilise pour `#[IsGranted]` et `access_control`.
5. Un administrateur (`ROLE_ADMIN`, ou un rôle marqué administrateur) reçoit `Droits::sansRestriction()`, et la `role_hierarchy` de `security.yaml` lui donne aussi tous les droits. Une autre ligne de la hiérarchie : `ROLE_COMPTABILITE` donne `ROLE_PAIEMENT_VOIR`. Les deux sont dans `droits.role_hierarchy`.

Les rôles posés à l'installation viennent de la migration `Version20261010215311` (Administrateur, Caisse et commercial, Graphiste PAO, Opérateur production, Livreur), puis ont été changés par d'autres migrations (`droits.roles_par_defaut`). Le guide ne lit pas la base : l'état réel d'une installation se voit dans Réglages › Rôles et droits.

### La matrice des modules

`src/Security/ModulesRoles.php` range les droits en modules pour l'écran Rôles et menus (docs/menus-par-role.md) : pour chaque module, les droits qui donnent « Modifier », le droit de lecture qui donne « Voir », et les préfixes des routes du module (`droits.modules_roles`). Les niveaux :

- **Modifier** : tous les droits du module ;
- **Voir** : pour les ventes, les clients, le montage PAO et la corbeille, une restriction de lecture : `LectureSeuleSubscriber` répond « accès refusé » à tout envoi de formulaire et à tout écran de création ou de modification du module ; pour l'atelier et les finances, c'est le droit de lecture qui existait déjà ;
- **—** : le module disparaît du menu.

Les montants se règlent à part (`droits.montants_masquables`) : un rôle peut voir un module sans voir les paiements reçus, les marges, les indicateurs ou le chiffre d'affaires.

### Les cas particuliers

- **Livreur seul** : une personne dont le seul droit est « Livraisons » n'ouvre que ses livraisons et sa tournée (`LivreurSeul`, `LivreurSeulSubscriber`). Toute autre page lui répond « accès refusé ».
- **Mot de passe à changer** : `ForcePasswordChangeSubscriber` renvoie vers le changement de mot de passe tant que `mustChangePassword` est vrai.
- **Compte désactivé** : `ActiveUserSubscriber` et `UserChecker` refusent un compte qui n'est plus actif (fin de contrat, accès suspendu).
- **Poste de machine** : `ProductionMachineSubscriber` contrôle, sur les routes `app_production_…`, que la requête vient d'un poste de machine enregistré (recherché par son adresse IP), sinon l'accès est refusé.

### Les voteurs

Quand la décision dépend de la fiche demandée, un voteur de `src/Security` répond (`droits.voteurs`, avec les attributs de chacun) : fichiers privés (`FichiersPrivesVoter`), corbeille (`CorbeilleVoter`), dossiers clients (`DossiersClientsVoter`), messagerie des clients et de l'équipe (`MessagerieVoter`, `MessagerieInterneVoter`), personnel (`PersonnelVoter`), primes (`PrimesVoter`), livreur (`LivreurVoter`). `CommandeVoter` existe mais n'est appelé nulle part (point d'audit `securite:CommandeVoter`).

## Les droits des comptes clients

Dans une entreprise cliente, chaque compte a ses droits (`CompteClient.droits`, docs/espace-client.md) :

| Droit | Ce qu'il permet |
|---|---|
| `administrateur` (« Gère le compte ») | tout, plus ajouter ou retirer des personnes, changer leurs droits, gérer les adresses |
| `valideur` | accepter ou refuser les demandes des demandeurs, accepter les devis, voir les prix |
| `payeur` | voir l'onglet Factures, le solde et le reste à payer, payer |
| aucun des trois (« Demandeur ») | commander ; ses commandes attendent un valideur, il ne voit pas les prix |
| `lecture` (« Lecture · suivi ») | voir les commandes, les livraisons et les fichiers, ne rien envoyer (`DroitsEspace::ENVOIS_LECTURE` liste les seules exceptions) |

Un particulier n'a qu'un compte et fait tout. `CompteClient::montantsVisibles()` est la seule règle pour afficher les prix : payeur ou valideur, jamais un demandeur ni une lecture seule. `EspaceClientSubscriber` vérifie la lecture seule à chaque requête, en plus des contrôles de chaque page.

## Où le guide le montre

- `routes[].acces` : pour chaque route, le pare-feu, la règle `access_control` qui s'applique, les `#[IsGranted]` et les `denyAccessUnlessGranted()` lus dans l'action ;
- `controleurs[].is_granted_classe` : le droit demandé pour toute la classe ;
- `droits.modules_roles` : les droits de chaque module de la matrice Rôles et menus, avec les préfixes de ses routes.
