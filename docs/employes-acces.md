# Employés avec et sans accès (maquettes E01 à E05)

Chaque employé a un dossier : contrat, paie, horaires, présence et demandes. L'accès à l'application vient en plus. Sans accès, l'employé pointe sur la tablette de l'entrée, et la RH saisit ses demandes à sa place.

## Écrans

| Maquette | Adresse | Qui |
|---|---|---|
| E01 Employés (cartes, filtres, export) | Réglages › Employés, `/equipe/employes` | RH (`ROLE_RH`) |
| E02 Nouvel employé et accès | `/equipe/employes/nouveau`, `…/{id}/modifier` | RH ; panneau Accès : administrateur |
| E03 Dossier | `/equipe/employes/{id}` | RH |
| E04 Accès à l'application | `/equipe/acces` | administrateur |
| E05 Activation par lien et PIN | `/acces/activer/{lien}`, `/acces/pin` | l'employé, sans connexion |
| E05 Tablette de l'entrée | `/tablette` | la tablette branchée |

Les anciennes adresses `/employes/…` mènent aux nouveaux écrans. Un employé ne se supprime plus : on retire son accès, et son dossier et son historique restent.

## Accès à l'application

- **Donner un accès.** Un administrateur ouvre la fiche, active « Accès à l'application » et choisit le rôle. L'écran suivant donne le message WhatsApp avec le lien d'activation.
  - Le lien marche 7 jours et ne s'affiche qu'une fois. Seule son empreinte est gardée en base.
  - « Renvoyer » fait un nouveau lien, et l'ancien ne marche plus.
- **Activer.** L'employé ouvre le lien sur son téléphone et choisit un code PIN à 4 chiffres, tapé deux fois.
  - Les codes trop faciles sont refusés (0000, 1234…).
  - Ce téléphone est enregistré pour se connecter et pour pointer. Il remplace l'ancien.
- **Se connecter.** Sur le téléphone enregistré : `/acces/pin` (lien « Se connecter avec mon code PIN » sur la page de connexion).
  - 5 codes faux bloquent le PIN 15 minutes.
  - L'identifiant et le mot de passe restent possibles pour les comptes qui en ont un.
- **Ce que la personne voit** dépend de son rôle (Réglages › Utilisateurs et droits). La fiche montre ce qu'elle verra avant d'enregistrer.
- **Gestion des accès (E04) :**
  - « Suspendre » (téléphone perdu) bloque tout de suite. La personne est déconnectée à sa page suivante.
  - « Réinitialiser le PIN » envoie un nouveau lien.
  - « Passer au PIN » s'affiche pour un compte à mot de passe.
  - « Annuler » coupe une invitation.
- **Fin de contrat.** La « Fin prévue » de la fiche coupe l'accès ce jour-là, automatiquement.
- Chaque changement d'accès est inscrit dans le journal d'activité.

Les comptes qui existaient avant restent actifs avec leur mot de passe.

## Horaires propres

Dans la fiche, on peut donner à un employé ses horaires : l'heure d'arrivée, l'heure de départ et les jours travaillés.

- Si on les laisse vides, l'employé a ceux de l'atelier (Paramètres › Horaires).
- Le retard, les heures sup et l'alerte « départ non pointé » en tiennent compte. L'alerte vient 2 h après la fin.
- Si la fin est avant le début, c'est un poste de nuit, comme un gardien de 19 h à 7 h.
  - L'arrivée du soir et le départ du matin forment une seule présence.
  - La pause de midi ne compte pas pour ce poste.
- Les horaires propres servent aussi au calcul des points (`docs/pointage.md`, « Calcul des points ») : les heures dues sont de la fin moins le début.

**Horaire des points** : sans horaires propres, l'employé suit un horaire type, « Accueil et caisse » (9 h – 17 h) ou « Atelier et autres » (9 h – 18 h), choisi d'après son poste. Le champ « Horaire des points » de la fiche permet de choisir l'autre ; le dossier montre celui qui s'applique.

## Tablette de l'entrée

1. Brancher la tablette.
   - Sur la tablette, un responsable RH se connecte.
   - Il ouvre Réglages › Pointage › Réglages.
   - Il touche « Brancher cette tablette » sur le point de l'entrée.
2. Il se déconnecte. La tablette garde un cookie : elle affiche l'heure et les noms, sans connexion.
3. Pour pointer, l'employé touche son nom. La caméra s'ouvre, puis il prend une photo.
   - L'heure vient du serveur.
   - La photo est obligatoire.
   - Il n'y a ni contrôle de téléphone ni zone GPS : c'est l'appareil de l'atelier.
4. Rebrancher la tablette ou une autre débranche l'ancienne. « Débrancher » la coupe tout de suite.

Les photos de pointage ne sont visibles qu'avec le droit RH, comme pour le pointage par téléphone.

## Dossier (E03)

- Le salaire et la prochaine paie ne sont affichés qu'avec le droit RH.
- « Saisir une absence » l'enregistre comme acceptée. Un congé saisi ici attend la validation habituelle (Demandes du personnel).
- « Enregistrer une avance » ajoute une ligne de retenue sur le bulletin du mois. La sortie d'argent se saisit en Trésorerie.
