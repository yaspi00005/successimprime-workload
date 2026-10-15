# Demande de conception (maquettes DC01 à DC05)

Construit le 14/10/2026. Le client fait faire un visuel (kakémono, bâche, flyer, carte, affiche, t-shirt, enseigne, autocollant, logo) depuis son espace en ligne, de deux façons :

- **par un graphiste** de l'atelier : forfait du support, acompte de 50 % pour commencer, premier BAT sous 24 h, 2 corrections incluses, solde à la validation du BAT ;
- **par l'IA** : 1 000 F l'image, payée par le crédit IA. Il choisit ensuite une image et commande l'impression, avec la finalisation par un graphiste en option (+ 5 000 F).

Le local et le site sont synchronisés : le client travaille en ligne, l'atelier au local.

## Parcours du client (en ligne, `/espace/conception`)

1. **Support (DC01)** : les supports actifs, chacun avec « Graphiste dès X F · IA 1 000 F ». Le panneau « Comment ça marche » donne les 5 étapes. En haut, « Mes demandes · N en cours ». Le crédit IA est affiché sur la page IA, avec « + Recharger ».
2. **Informations (DC02, DC05 sur téléphone)** :
   - Champs : titre, sous-titre, date et heure, coordonnées, consignes.
   - Éléments : logos et photos pris dans Mes fichiers, ou envoyés sur place.
   - Couleurs, style, quantité à imprimer ensuite, « Pour quand ».
   - Exemple aimé : lien https seulement, jamais ouvert par le serveur. L'atelier le voit avec `rel="noopener noreferrer nofollow"`.
   - Brouillon gardé sur le serveur à chaque saisie. Numéro `W-DC-AAAA-NNN`.
3. **Graphiste ou IA** : panneau « Qui réalise la conception ? » avec les deux offres.
4. **Paiement de l'acompte (DC03)** :
   - Le bouton « Payer l'acompte de 5 000 F par Orange Money » ouvre le rappel : référence, récapitulatif (acompte, fonds de soutien, frais de retrait, « Total payé avec Orange Money », 5 100 F avec les taux par défaut), étapes, code secret jamais demandé par l'atelier. « Continuer vers Orange Money » mène à la page sécurisée d'Orange Money (WebPay, comme les commandes).
   - Sans clés Orange Money, « Envoyer à l'atelier » : la commande est créée et l'acompte se règle au numéro marchand ou en boutique.
5. **IA (DC04, et « Proposition IA » de DC05 sur téléphone)** :
   - Résumé de la demande, consigne de 300 caractères au plus, « Générer une image · 1 000 F ».
   - Grande image avec zoom et « Voir sur le support ». Mes images (vignettes) et récapitulatif.
   - « Choisir et commander l'impression » : commande en ligne « à confirmer » par l'atelier. Le prix est recalculé par le serveur avec le produit du catalogue lié au support (« prix confirmé par l'atelier » s'il n'y en a pas). L'image devient un fichier du client, avec la finalisation en option.
6. **Crédit IA** (`/espace/conception/credit`) : solde, packs (1, 3, 5, 10 images par défaut), relevé (achats, images, remboursements, rejets). « Payer » ouvre d'abord le récapitulatif du pack choisi (prix du pack, fonds de soutien, frais de retrait, total) ; le relevé montre, pour un achat Orange Money, ce qui a été payé frais compris.
7. **Suivi d'une demande envoyée** :
   - Frise graphiste : envoyée, acompte, prise en charge, BAT envoyé, BAT validé.
   - Frise IA : images générées, impression commandée, textes replacés, BAT envoyé, BAT validé.
   - Le BAT suit le circuit habituel (Fichiers et BAT). Le fil Notifications reçoit « prise en charge » puis « terminée » ou « annulée », sans montant (catégorie Production, pastille PAO, bouton « Voir la demande »), aussi pour un compte en lecture seule.

## Droits (comptes entreprise)

Chaque page et chaque envoi de formulaire est vérifié côté serveur (`src/Service/Conception/DroitsConception.php`). Un compte ne voit que les demandes de son client.

| Compte | Ce qu'il peut faire |
|---|---|
| Particulier, administrateur, payeur | tout : préparer, payer l'acompte, acheter du crédit IA, générer des images, commander l'impression |
| Autre compte (demandeur) | préparer la demande. « Demander le paiement » prévient les payeurs par SMS, sans montant, et leur nom lui est affiché. La génération par IA lui montre qui peut payer. Il peut commander l'impression d'une image déjà générée : la commande reste à confirmer. Un demandeur ne voit aucun prix (forfait, acompte, image, impression) ; un valideur les voit sans payer (`CompteClient::montantsVisibles`, docs/espace-client.md). |
| Lecture seule | suivre : la liste de ses demandes et leur suivi, même en préparation, sans montant (comme ses commandes). Le choix du support, les étapes, la page IA et le crédit IA le renvoient à la liste. |

Ni coût ni marge ne sont montrés au client. Le coût d'une image chez le fournisseur ne quitte pas le local.

## Argent : les règles

- **Acompte et packs** :
  - Orange Money WebPay, mêmes protections que le paiement des commandes : jeton de notification dont seule l'empreinte est gardée, état relu chez Orange au retour, lien repris pendant 10 minutes.
  - Quand l'argent est reçu, la commande est créée (« Conception · Kakémono 85 × 200 cm », ou « Crédit IA · 3 images ») avec un paiement Orange Money **en attente**, jamais validé tout seul. La caisse le valide au local.
  - Frais Orange Money (docs/espace-client.md) : le client paie en plus le fonds de soutien et le frais de retrait (Réglages › Paiements, 1 % chacun par défaut). Orange reçoit le total ; le paiement porte l'acompte ou le prix du pack, et les frais à part. Le forfait, l'acompte, le crédit IA et la facture ne changent pas : un pack de 3 000 F payé 3 060 F donne 3 000 F de crédit.
- **Brouillon** : une demande dont un paiement Orange Money a réussi, ou est ouvert depuis moins de 30 minutes, ne peut plus être supprimée. Un deuxième acompte, payé depuis un autre onglet, va sur la même commande.
- **Crédit IA** :
  - Un pack payé en ligne est utilisable tout de suite. S'il a été payé au numéro marchand, il compte quand la caisse a validé le paiement.
  - Si la caisse rejette le paiement, le crédit est retiré, quitte à passer sous zéro, et la génération est bloquée.
  - Une commande de pack supprimée annule le pack en attente.
  - Le relevé (`mouvement_credit_ia`) monte au local. Le solde (`credit_ia`) est calculé en ligne.
- **Image** :
  - Le crédit est débité avant l'appel au fournisseur, et jamais au-delà du solde, même avec deux générations lancées en même temps.
  - Si la génération échoue, l'image est remboursée une fois et sa place du jour est rendue.
  - Limites : une génération à la fois par client, 10 images par client et par heure, 200 images par jour pour tout le site.
- **Prompt** : il est écrit par le serveur, en anglais, à partir des champs de la demande. Les marques et les personnes célèbres sont refusées, et le texte du client est neutralisé. Aucune adresse renvoyée par le fournisseur n'est suivie : seule l'image est acceptée (PNG, JPEG ou WebP vérifiés).
- **Fichiers** : les images IA et les éléments sont rangés hors de `public/` (`var/uploads/ia/<client>/`) et servis seulement à leur client et au personnel qui a le droit.

## Côté atelier (local)

- **Menu Atelier › Demandes de conception** (`/atelier/demandes-conception`) pour la PAO (`ROLE_PREPRESSE`) et les ventes (`ROLE_COMMANDE`). La pastille du menu et le bandeau rose de la prépresse et du tableau de conception comptent les demandes reçues que personne n'a prises. La synchronisation prévient ces deux rôles à l'arrivée d'une demande.
- **Fiche** :
  - On y trouve le brief, les couleurs, le style, les éléments, l'exemple, l'image IA choisie, le client et la commande.
  - L'acompte n'y est affiché qu'avec le droit Commandes ou Paiements (voir).
  - Actions : « Prendre en charge », « Ouvrir le travail pré-presse et BAT » (circuit habituel), « Marquer terminée », « Annuler ». Elles ne se font qu'au local.
  - Une demande au graphiste arrive avec sa commande confirmée au forfait. Une impression IA arrive en commande en ligne à confirmer.
- **Réglages › Conception** (direction seulement) :
  - Réglages globaux : IA active, prix d'une image, finalisation, packs, plafond du jour, limite par heure, coût d'une image.
  - Bouton « Supports » : nom, format, taille, forfait, acompte, délai du BAT, corrections, couleur, ordre, actif, et produits du catalogue utilisés pour le prix de l'impression.
  - Les supports descendent sur le site. Le coût d'une image reste au local, et la direction voit la dépense du mois en tête de la liste.
- **Synchronisation** :
  - `support_conception` descend.
  - `demande_conception` monte une fois envoyée (acompte reçu, impression commandée), puis son suivi redescend.
  - `mouvement_credit_ia` monte.
  - Les images IA, le solde et le compteur du jour restent en ligne.

## Fournisseur d'images

Interface `GenerateurImages`, choisie par les variables d'environnement :

| Variable | Rôle |
|---|---|
| `IA_IMAGES_FOURNISSEUR` | `openai` (production, par défaut) ou `demo` (affiche dessinée par le serveur, développement et essais seulement, refusée en production) |
| `IA_IMAGES_CLE` | clé de l'API. Sans clé, la génération par IA est « bientôt disponible » et le graphiste reste proposé. |
| `IA_IMAGES_MODELE` | `gpt-image-1` par défaut (`dall-e-3` possible) |
| `IA_IMAGES_URL` | `https://api.openai.com/v1` par défaut, https obligatoire |

Les essais automatiques n'appellent jamais le réseau.

## À faire par Yaya

1. Créer un compte développeur OpenAI sur platform.openai.com, au nom de l'entreprise.
2. Ajouter un moyen de paiement (Billing) et fixer une **limite de dépense** mensuelle. Garder aussi le plafond d'images par jour dans Réglages › Conception.
3. Créer un **projet** puis une **clé d'API** de projet (API keys › Create new secret key).
4. Mettre la clé **seulement** dans `.env.local` du serveur en ligne, jamais dans le dépôt, un message ou une discussion :

   ```
   IA_IMAGES_FOURNISSEUR=openai
   IA_IMAGES_CLE=la-clé-du-projet
   IA_IMAGES_MODELE=gpt-image-1    # facultatif
   ```

5. Vider le cache (`php bin/console cache:clear`), puis faire une demande d'essai en ligne avec un compte payeur : acheter un pack d'une image, générer, vérifier l'image et le relevé.
6. Indiquer dans Réglages › Conception le coût d'une image chez OpenAI, pour suivre la dépense.
7. Après le déploiement, sur le local : `php bin/console app:synchro --initialiser=support_conception`, pour envoyer les supports et leurs réglages au site.

## Écarts avec les maquettes

- Orange Money passe par la page WebPay d'Orange (code secret saisi chez Orange), et non par une demande poussée sur le téléphone (USSD). Le numéro affiché dans le rappel DC03 est celui du compte, et le client peut payer avec un autre numéro sur la page d'Orange.
- Pas de reçu par WhatsApp : le client est prévenu par la chaîne des notifications (application, SMS ou e-mail), sans montant.
- Comme tout numéro donné sur le site, celui d'une demande porte le préfixe `W-` (`W-DC-2026-014` au lieu de `DC-2026-014` sur la maquette). Ce préfixe évite qu'il se confonde avec un numéro du local.

## Code

- Entités : `SupportConception`, `DemandeConception`, `ImageIa`, `CreditIa`, `MouvementCreditIa`, `CompteurIaJour`. Migration `Version20261014152410`.
- Services : `src/Service/Conception/` (droits, tarif, prompt, réglages, crédit, images, commandes, demandes, générateurs).
- Contrôleurs :
  - `EspaceConceptionController` (client) ;
  - `DemandesConceptionController` (atelier) ;
  - `SupportsConceptionController` (réglages).
- Gabarits :
  - `templates/espace/conception/` et `templates/demandes_conception/` ;
  - styles `public/css/conception.css` et `public/css/demandes-conception.css`.
- Essais :
  - `tests/Unit/Service/Conception/` ;
  - `tests/Unit/Service/Paiement/OrangeMoneyConceptionTest.php` ;
  - `tests/Unit/Service/Synchro/ConceptionSynchroTest.php`.
- Démonstration (`app:demo`) : 4 demandes vues par l'atelier.
  - `DC-D001` : kakémono de DEMO-0002, acompte à valider par la caisse, à prendre en charge.
  - `DC-D002` : flyer en cours chez Seydou.
  - `DC-D003` : cartes de visite terminées.
  - `DC-D004` : affiche faite par l'IA, impression commandée avec finalisation. DEMO-0002 a aussi son relevé de crédit (pack de 3 images, 2 utilisées). Comme les autres fichiers de démonstration, l'image n'est qu'un nom : la fiche affiche une vignette vide.

Captures : `/mnt/project-files/captures/conception/`.
