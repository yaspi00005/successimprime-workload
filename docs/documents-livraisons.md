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

## Ordre proposé

1. **D01 à D04 :** les documents servent tous les jours. Le montant en lettres et les mentions par société sont la base.
2. **L02 et L03 :** la remise sur mobile et l'encaissement provisoire.
3. **L01 :** le planning avec la carte.
4. **L04 :** le suivi par le client, avec l'espace client (J5).
