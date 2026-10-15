# Documents et livraisons

Yaya a envoyé 8 maquettes le 10/10/2026. Elles sont dans `/mnt/project-files/maquettes/documents-livraisons/` :
- D01 à D04 pour les documents ;
- L01 à L04 pour les livraisons.

Les montants, noms et numéros sont fictifs. Comme pour l'admin, on garde les données et les calculs qui marchent, pas l'ancienne présentation.

## Les documents (D01 à D04)

Les quatre documents partagent la même mise en page A4 :
- l'en-tête Succès Imprim ;
- les 4 bandes de couleur du logo ;
- en pied de page, les mentions légales de la société : MDG SARL, RCCM et NIF.

Les mentions viennent des réglages de la société (`docs/societes.md`), jamais du gabarit. La future boutique de consommables aura donc les siennes sans copier les modèles.

| Maquette | Déjà là | À ajouter |
|---|---|---|
| **D01 Devis** | PDF (Dompdf), QR d'authenticité (`/devis/verifier/{token}`), lien public du PDF | TVA 18 % détaillée, conditions (acompte 50 %, délai, BAT obligatoire), zone « Bon pour accord ». Le QR « Accepter en ligne » ouvre une page publique sécurisée où le client accepte le devis : l'acceptation est horodatée et le devis devient une commande. |
| **D02 Facture** | PDF, lien public, paiements et acomptes | Acompte déduit, reste à payer, montant en lettres (« Arrêtée la présente facture à la somme de… francs CFA »), tampon « Acompte payé » ou « Payée ». Le QR de paiement ouvre le paiement Orange Money du reste à payer. |
| **D03 Bon de commande** | Commande, lignes, fichiers, BAT | Spécifications de production (machine, fichier, version du BAT validé), calendrier jusqu'à la livraison, engagements, signature du client. |
| **D04 Bon de livraison** | `BonLivraison` avec quantités commandées et livrées par ligne | Quantité restante et livraison partielle (un second bon pour le reste), signature, photos de preuve, solde encaissé. Le QR permet au livreur d'ouvrir la livraison sur son téléphone. |

Le montant en lettres est calculé par un petit service testé, en français, sans décimales.

## Les livraisons (L01 à L04)

| Maquette | Contenu |
|---|---|
| **L01 Planning du jour** | Livraisons du jour, livrées, montant à encaisser à la livraison, partielles ou reportées. Une carte de la tournée avec la position du livreur, les arrêts numérotés et les créneaux. Boutons « Réordonner la tournée » et « Prévenir les clients (WhatsApp) ». |
| **L02 Détail d'une livraison** | Les étapes : préparée, chargée, en route, livrée. Avec les heures, les photos, la signature et l'encaissement. |
| **L03 Livreur sur mobile** | Sa tournée, puis la remise : articles à cocher, photos, signature au doigt, encaissement Orange Money ou espèces. |
| **L04 Client sur mobile** | Son livreur sur la carte, l'heure d'arrivée estimée et le paiement du solde. Le client ouvre un lien sécurisé, sans compte. |

### Choix techniques

- **Carte :** Leaflet avec OpenStreetMap, gratuit et sans clé. Les adresses des clients utilisent les coordonnées prévues dans `docs/geolocalisation.md`.
- **Position du livreur :** elle vient de son téléphone, tant que la page de tournée est ouverte. Une page web ne peut pas suivre le téléphone en arrière-plan. La carte affiche donc « position il y a N min ». Une vraie application mobile ne sera nécessaire que si ce suivi ne suffit pas.
- **Heure d'arrivée estimée :** distance à vol d'oiseau et vitesse moyenne réglable (moto en ville). C'est une estimation simple, sans service payant.
- **Encaissement par le livreur :** il reste provisoire jusqu'à sa validation à la caisse, à la boutique. Le livreur remet l'argent ou la preuve Orange Money, et le caissier valide. Avant validation, rien n'entre dans la trésorerie.
- **Photos et signature :** elles sont jointes au bon de livraison. Elles sont visibles par l'admin et le client, pas sur un lien public.

### Droits

- Le livreur voit seulement ses tournées du jour : adresse, téléphone du réceptionnaire, articles et montant à encaisser. Il ne voit ni les prix d'achat, ni les marges, ni les autres clients.
- Le lien de suivi du client montre sa livraison seulement : la position du livreur pendant la tournée, jamais son historique.

### État (11/10/2026)

Fait (L02, L03) :
- Le bon de livraison porte la tournée : livreur, jour, créneau, ordre. Le bureau (droit Commandes ou admin) les règle sur « Tournée et remise » (`/livraisons/bl/{id}`), ouvert depuis le bon.
- **Ma tournée** (`/livraisons/tournee`, mobile) : les livraisons du jour du livreur, la carte avec les arrêts numérotés (positions des fiches clients), les boutons Chargée, Je pars, Je suis arrivé, Itinéraire, Appeler, Remise. Un livreur arrive sur cette page à la connexion. Le bureau peut voir la tournée de chaque livreur.
- **Remise** : quantités remises, au plus le reste à livrer de la commande (remise partielle possible ; un article décoché sort du bon et revient « prêt à livrer »), position GPS du téléphone, photos, signature au doigt (obligatoire), encaissement Orange Money, espèces ou crédit client. Photos et signature sont rangées dans `var/uploads/livraisons`, hors du dossier public, visibles par le livreur de la livraison et le bureau.
- **Encaissement** : provisoire sur le bon. À la boutique, une personne qui a le droit Encaissements choisit le compte et valide : le paiement est créé et validé, il entre en trésorerie. Avant, rien n'y entre.
- Le détail (L02) montre les étapes avec leurs heures, le contenu commandé, livré et le reste, les preuves, l'encaissement et la planification.

Fait (L01) : **Planning des livraisons** (`/livraisons/planning`, bureau) : livraisons du jour, livrées et à l'heure (avant la fin du créneau), montant à encaisser et déjà encaissé (droit Paiements), partielles et reportées (bons validés d'un jour passé non livrés). Carte de toutes les tournées depuis la boutique (premier lieu de pointage placé sur la carte), liste par livreur avec l'état et l'encaissement de chaque arrêt. « Réordonner la tournée » range les arrêts restants au plus proche, à vol d'oiseau. « Prévenir les clients » ouvre un message WhatsApp prêt par client (envoi groupé avec l'API WhatsApp).

Reste : la position du livreur en direct, L04 (suivi par le client, avec l'espace client J5) et l'étape « Pose » de la maquette.

## Frais de livraison et objet (15/10/2026)

Demande de Yaya : des boutons pour les frais de livraison au lieu de taper le montant, et l'objet de la commande sur les devis et les factures.

**Paliers.** Réglages › Ventes › « Livraison et objet » (direction seulement, `ReglagesSaisie`). Par défaut 1 000, 1 500, 2 000 et 2 500 F : le plus grand est le maximum. De 1 à 100 000 F, 8 paliers au plus ; vide : pas de frais. La tuile ne part pas sur le site.

**Saisie.**
- Commande : dans « Livraison et délai », en mode Livraison seulement, des boutons « Sans frais · 1 000 F · 1 500 F · 2 000 F · 2 500 F ». Sur téléphone, à l'étape 3 (récapitulatif).
- Devis : une carte « Livraison » avec les mêmes boutons. Un devis avec des frais devient une commande à livrer quand il est accepté.
- Le récapitulatif montre une ligne « Livraison » et l'ajoute au total.

**Contrôles du serveur.**
- Seuls 0 et les paliers sont acceptés (le formulaire refuse un autre montant avec « Frais de livraison non permis »). Un montant enregistré avant un changement de réglage reste permis sur ce document.
- Retrait boutique : les frais sont remis à 0.
- Les totaux du devis et de la commande sont refaits par le serveur depuis les lignes et les frais (`recalculerTotaux`), jamais repris du navigateur.

**Montants.** Les frais sont sans TVA et compris dans le total HT et le total TTC. Ils comptent donc dans le reste à payer, le solde du client et les statistiques. C'est toujours une ligne « Livraison » visible (devis, commande, proforma, facture), jamais un prix de ligne gonflé. Aucun montant dans les notifications ni les SMS.

**Suivi.** Devis → commande → facture : les frais et l'objet sont copiés. Sur la facture, la copie est figée avec les montants.

**Commande passée en ligne.** Le client ne choisit pas les frais. La commande arrive au local sans frais (`imposeAuLocal` de `CatalogueSynchro`). La boutique les choisit dans le formulaire avant de confirmer le brouillon. Dans l'espace client, la ligne « Frais de livraison » montre :
- le montant, si le compte voit les montants (comme le total : payeur et valideur) ;
- « comprise » sinon (demandeur) ;
- « fixés par l'atelier à la confirmation » tant que la commande est à confirmer.

La recette des accès accepte ce montant pour le valideur (`RecetteEspaceClient::MONTANTS_VOULUS`), comme le total.

**Objet.**
- Champ libre et facultatif, 150 caractères au plus, sous le client du devis et de la commande.
- Suggestions réglables dans la même tuile : Impressions, Prestations informatiques, Conception graphique, Signalétique, Textile personnalisé.
- Imprimé « Objet : … » en haut du devis, de la proforma et de la facture.
- Montré sur les fiches et dans l'espace client.

## Ordre proposé

1. **D01 à D04 :** les documents servent tous les jours. Le montant en lettres et les mentions par société sont la base.
2. **L02 et L03 :** la remise sur mobile et l'encaissement provisoire.
3. **L01 :** le planning avec la carte.
4. **L04 :** le suivi par le client, avec l'espace client (J5).
