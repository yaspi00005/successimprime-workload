# Clients (maquettes K01 à K06, fiche FC01 à FC04)

Maquettes : `maquettes/clients/K01` à `K06`, puis `maquettes/fiche-client-3d/` (FC01 à FC04, 18/10/2026) pour la fiche, dans les fichiers du projet.

## Écrans

| Maquette | Écran | Route |
|---|---|---|
| K01 | Liste et recherche | `app_clients_index` (`/clients?vue=liste`) |
| K06 | Vue cartes | `app_clients_index` (`/clients?vue=cartes`, gardée en cookie) |
| K02 | Nouveau client, modifier | `app_clients_nouveau`, `app_clients_modifier` |
| FC01 à FC04 | Fiche client (remplace K03 et l'ancienne fiche détaillée) | `app_clients_show` (`/clients/fiche/{publicId}`) |
| K04 | Journal des modifications | `app_clients_journal` (export CSV avec `format=csv`) |
| K05 | Ajout terrain (mobile) | `app_clients_nouveau?mode=terrain` (bouton « Nouveau » sur téléphone) |

Toutes les saisies passent par `src/Service/Clients/EditionClient.php`.

## Règles

- **Types** : Entreprise, ONG, Administration, Particulier. Pour une organisation :
  - la raison sociale est obligatoire ;
  - le téléphone de la fiche est celui du contact principal.
- **Doublons** : pendant la saisie d'un numéro, l'écran cherche ce numéro dans les fiches, les contacts et les prospects ouverts.
  - Un numéro déjà pris par une fiche bloque l'enregistrement.
  - Un prospect peut être transformé en client : il passe alors en « Gagné » et il est lié à la fiche.
- **Seuils de la direction** : les demandes suivantes ne sont pas appliquées si elles viennent de quelqu'un sans `ROLE_ADMIN` :
  - une remise au-delà de 10 % ;
  - un plafond au-delà de 500 000 F ;
  - le passage en grille Revendeur (B2B) ;
  - la dispense d'acompte, ou un acompte à 0 % (et la fin de la dispense).

  La grille Revendeur est réservée aux gens du métier de l'impression, démarchés et qui connaissent les prix (précision de Yaya, 14/10/2026). Une entreprise, une ONG ou une administration paie le prix normal (B2C) tant qu'elle n'est pas dans ce cas ; elle garde alors la fidélité et les promotions.

  La fiche garde sa valeur. La direction reçoit une notification, et le journal note une ligne « Demande à valider ».
- **Dispensé d'acompte** (case du bloc « Conditions commerciales », 18/10/2026) : certains clients ne paient rien d'avance. Cochée par la direction (`ROLE_ADMIN`) ; pour les autres, le changement part en demande à la direction. Partout où un acompte est proposé, il vaut alors 0 % (`DelaisCommande::pourcentageAcompte`) :
  - panier de « Mes produits » : pas d'acompte, bouton « Valider la commande », la commande est lancée dès son arrivée au local (docs/mes-produits.md) ;
  - nouvelle commande en ligne : paiement « Payer plus tard (sans acompte) » choisi par défaut ;
  - « Factures et paiements » de l'espace client : aucun acompte à payer ;
  - comptoir : la caisse ne s'ouvre pas sur un acompte après la saisie, et l'écran de saisie l'annonce.
  La fiche affiche « dispensé d'acompte » dans ses conditions.
- **Journal** : chaque changement est enregistré dans `modification_client`, avec l'ancienne valeur, la nouvelle, l'auteur, l'heure et l'écran d'origine. La provenance vaut ERP local, Admin en ligne, Espace client ou Synchronisation. Rien ne s'efface.
- **Montants selon les droits** :
  - le CA 12 mois demande `ROLE_STATS_VENTES` ;
  - les soldes et les créances demandent `ROLE_PAIEMENT_VOIR` ;
  - le filtre « Paiements » de l'historique demande `ROLE_PAIEMENT_VOIR` ;
  - l'export de la liste est réservé à `ROLE_ADMIN`.
- **Statut** :
  - « Bloqué » : fiche désactivée.
  - « Relance » : une créance est échue.
  - « Nouveau » : aucune commande.
  - « Actif » : une commande dans les 60 derniers jours.
  - « Inactif » : sinon.
- **Historique (K03, onglet Historique de FC04)** : il regroupe dans un même fil les éléments suivants, filtrables par catégorie :
  - devis et commandes ;
  - paiements ;
  - relances, notes et actions faites ;
  - BAT et livraisons ;
  - modifications de la fiche.
- **Notes** : une note peut porter une date de rappel. Le rappel crée une ligne d'action, à cette date, pour la personne qui a écrit la note.
- **Ajout terrain (K05)** :
  - la position GPS est prise automatiquement ;
  - « Enregistrer et créer un devis » ouvre le devis avec le client déjà choisi ;
  - une ligne « Prospection » faite est créée au nom du commercial, ce qui lui donne ses points.
- **Import** : Réglages › Import des données (Direction, ERP local) crée les fiches en passant par les mêmes contrôles que la saisie (`EditionClient`), sans aucun message au client, et repère les doublons par code, téléphone (fiche, second numéro, contacts) puis nom dans n'importe quel ordre. Les soldes de départ des clients s'importent à part (« Solde d'ouverture »). Voir docs/import-donnees.md.
- **Invitation à l'espace client** : la case cochée à la création affiche, sur la fiche, un bouton WhatsApp avec le lien de connexion. Le client se connecte ensuite avec son numéro et un code.
- **Suspendre l'accès à l'espace client** (bloc « Accès à l'espace client » de la fiche, droit Clients, ERP local) : tout le compte ou une personne (son numéro), avec un motif gardé à l'atelier ; « Rétablir » rend l'accès. Chaque geste est au journal de la fiche ; rien n'est effacé. Sans rapport avec le statut « Bloqué ». Voir docs/espace-client.md, « Suspendre l'accès à l'espace client ».
- **Plusieurs interlocuteurs** : une entreprise a souvent deux ou trois personnes qui commandent. Le devis note « À l'attention de », la commande « Commandé par » (liste proposée : contacts de la fiche et noms déjà notés) ; la fiche les montre sous le numéro, dans les onglets Commandes (« par X ») et Devis (« pour X »). Voir docs/espace-client.md, « Plusieurs interlocuteurs pour une entreprise ».

## Fiche client (FC01 à FC04)

Une seule page, `templates/clients/fiche.html.twig`, nourrie par `src/Service/Clients/FicheClient.php` ; les calculs (panier moyen, plafond utilisé, CA par mois, délai de paiement, activité, satisfaction) sont dans `src/Service/Clients/CalculsFicheClient.php`, testés dans `tests/Unit/Service/Clients/CalculsFicheClientTest.php`. L'ancienne fiche détaillée (`app_clients_detail`) renvoie vers l'onglet « Factures et paiements ».

**En-tête** : nom, pastilles (type, VIP si l'étiquette « VIP » est posée, secteur, Revendeur, espace client, statut), code, client depuis, lieu, NIF, responsable. Boutons « Message », « + Devis », « + Commande » (la nouvelle commande s'ouvre avec le client déjà choisi). Le menu « ⋯ » garde Appeler, WhatsApp, Modifier, Journal et Supprimer (Direction, seulement sans commande ni devis).

**Onglets** (l'adresse garde l'onglet : `#commandes`, `#factures`… ; `#compte` et `#fidelite` restent valables) :

| Onglet | Maquette | Contenu |
|---|---|---|
| Aperçu | FC01 | Chiffres clés (CA de l'année, panier moyen, solde dû, devis en attente, dernière commande), commandes en cours avec leur étape, CA par mois, contacts, conditions commerciales, coordonnées, plafond de crédit, notes internes, fidélité, accès à l'espace client |
| Commandes | | Toutes les commandes, avec l'étape et ce qui est payé |
| Devis | | Tous les devis non supprimés, statut et validité |
| Factures et paiements | FC02 | Solde, échu, payé dans l'année, délai moyen ; sous-onglets Échéancier, Paiements, Documents émis, Relances, Compte du client ; relevé de compte et impayés en PDF ; relances automatiques prévues, derniers paiements, règle de blocage |
| Produits | FC03 | « Mes produits » du client (ProduitClient) : caractéristiques, fichier lié, prix, nombre de commandes ; « Commander pour lui » ; dossier sur le serveur et charte du client |
| Fichiers | DS01 | Dossier du client sur le serveur de l'atelier : sous-dossiers, vignettes, détail, versions, « Ouvrir avec Illustrator », « Lier à une commande », « Envoyer au client » (docs/dossiers-clients.md), puis les fichiers reçus avec les commandes et les BAT. En ligne : seulement les fichiers publiés |
| Messages | | Conversations (une par sujet), selon le droit Messagerie de chaque conversation |
| Historique | FC04 | Fil filtrable (commandes, paiements, messages, appels et notes, fichiers et BAT, modifications), « + Noter un appel », activité sur 12 mois, satisfaction (sondages), délais de réponse |

**Conditions commerciales** : délai de paiement, acompte (ou « dispensé d'acompte »), grille de prix et remise, plafond de crédit, envoi des factures. Le plafond et la remise étaient déjà sur la fiche ; deux champs ont été ajoutés (migration `Version20261018171810`) et se règlent dans « Modifier » :
- **Factures envoyées par** (`facturesCanaux`) : WhatsApp, e-mail, papier, plusieurs possibles. Le destinataire est le contact « Factures » de la fiche, sinon le contact principal.
- **Charte du client** (`charteCouleurs`, `charteTexte`) : jusqu'à 6 couleurs (#RRGGBB) et une consigne, montrées dans l'onglet Produits pour le prépresse.

Ces champs restent dans l'ERP local : ils ne partent pas vers le site.

**Plafond de crédit** : jauge de ce que le client doit sur son plafond (orange dès 60 %, rouge dès 90 % ou au-delà du plafond). La règle de blocage montrée est celle appliquée à la production (`ControleCreditClientService`) : une commande ne part pas en production si ce qui reste dû sur les commandes précédentes dépasse le plafond. Boutons « Relancer » (droit Factures, ouvre les créances du client) et « Encaisser » (droit Encaisser, ouvre la caisse sur le client).

**Notes internes** : notes de l'équipe (`NoteClient`), sous l'observation épinglée de la fiche. Jamais montrées au client, ni dans l'espace client, ni envoyées au site.

**Commander pour lui** (onglet Produits, droit Commandes) : choisir la quantité (et la taille pour un produit au m²) crée une commande au comptoir « à confirmer », au nom du client, avec la ligne du produit (même calcul que « Mes produits » dans l'espace client). La commande s'ouvre ensuite pour être vérifiée et confirmée. Route `app_clients_produit_commander`.

**Droits sur la fiche** : tout le monde avec le droit Clients voit la fiche ; ce qui suit ne s'affiche qu'avec le droit voulu, sans trou dans la page :
- CA, panier moyen et barres du CA par mois : `ROLE_STATS_VENTES` (sinon le nombre de commandes de l'année) ;
- solde, échu, plafond, échéancier, paiements, relances, colonne « Payé », paiements et relances de l'historique, lignes du journal touchant au solde, au plafond ou au bonus : `ROLE_PAIEMENT_VOIR` (sinon l'onglet « Factures et paiements » ne montre que les documents émis) ;
- compte du client : `ROLE_PAIEMENT_VOIR` et `ROLE_PAIEMENT_ENCAISSER` ;
- liens vers commandes, devis, factures : droits de ces modules ; « Réglages » des relances et « Supprimer » : Direction.

**Démo** : `app:fiche-client:demo` (lancée par `app:demo`) complète Salon Espoir (DEMO-0002) : VIP, factures par WhatsApp et e-mail à la comptable (contact « Factures »), charte, trois notes internes. Avec `demo.seydou` (graphiste, sans Paiements ni Stats) la même fiche s'affiche sans aucun montant de paiement ni CA.

## Reste à faire

- La recherche dans les noms et les numéros des contacts : aujourd'hui, elle ne porte que sur la fiche.
- Les modifications faites en ligne qui attendent leur validation sur l'ERP local : elles dépendent de la synchronisation (J4).
- L'acompte qui dépend du montant de la commande (« 50 % au-delà de 500 000 F » de FC01) : un seul pourcentage par client aujourd'hui.
- Les avoirs (FC02) : l'application n'en émet pas encore ; le sous-onglet montre les documents émis (devis, factures, reçus).
- Un écran pour accepter ou refuser les demandes à la direction. Pour l'instant, la direction modifie la fiche depuis la notification.
