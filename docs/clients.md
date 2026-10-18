# Clients (maquettes K01 à K06)

Maquettes : `maquettes/clients/K01` à `K06` dans les fichiers du projet.

## Écrans

| Maquette | Écran | Route |
|---|---|---|
| K01 | Liste et recherche | `app_clients_index` (`/clients?vue=liste`) |
| K06 | Vue cartes | `app_clients_index` (`/clients?vue=cartes`, gardée en cookie) |
| K02 | Nouveau client, modifier | `app_clients_nouveau`, `app_clients_modifier` |
| K03 | Fiche et historique d'activité | `app_clients_show` |
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
- **Historique (K03)** : il regroupe dans un même fil les éléments suivants, filtrables par catégorie :
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

## Reste à faire

- La recherche dans les noms et les numéros des contacts : aujourd'hui, elle ne porte que sur la fiche.
- La note de satisfaction (KPI de K03), qui viendra avec les sondages (J5).
- Les modifications faites en ligne qui attendent leur validation sur l'ERP local : elles dépendent de la synchronisation (J4).
- Un écran pour accepter ou refuser les demandes à la direction. Pour l'instant, la direction modifie la fiche depuis la notification.
