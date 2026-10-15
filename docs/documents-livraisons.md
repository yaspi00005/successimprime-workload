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

- Le livreur voit seulement ses tournées du jour : adresse, téléphone du réceptionnaire, articles et montant à encaisser. Il ne voit ni les prix d'achat, ni les marges, ni les autres clients. Depuis le 15/10/2026, il ne voit rien d'autre que sa tournée et ses livraisons (voir « Écrans du livreur » plus bas).
- Le lien de suivi du client montre sa livraison seulement : la position du livreur pendant la tournée, jamais son historique.

### État (11/10/2026)

Fait (L02, L03) :
- Le bon de livraison porte la tournée : livreur, jour, créneau, ordre. Le bureau (droit Commandes ou admin) les règle sur « Tournée et remise » (`/livraisons/bl/{id}`), ouvert depuis le bon.
- **Ma tournée** (`/livraisons/tournee`, mobile) : les livraisons du jour du livreur, la carte avec les arrêts numérotés (positions des fiches clients), les boutons Chargée, Je pars, Je suis arrivé, Itinéraire, Appeler, Remise. Un livreur arrive sur cette page à la connexion. Le bureau peut voir la tournée de chaque livreur.
- **Remise** : quantités remises, au plus le reste à livrer de la commande (remise partielle possible ; un article décoché sort du bon et revient « prêt à livrer »), position GPS du téléphone, photos, signature au doigt (obligatoire), encaissement Orange Money, espèces ou crédit client. Photos et signature sont rangées dans `var/uploads/livraisons`, hors du dossier public, visibles par le livreur de la livraison et le bureau.
- **Encaissement** : provisoire sur le bon. À la boutique, une personne qui a le droit Encaissements choisit le compte et valide : le paiement est créé et validé, il entre en trésorerie. Avant, rien n'y entre.
- Le détail (L02) montre les étapes avec leurs heures, le contenu commandé, livré et le reste, les preuves, l'encaissement et la planification.

Fait (L01) : **Planning des livraisons** (`/livraisons/planning`, bureau) : livraisons du jour, livrées et à l'heure (avant la fin du créneau), montant à encaisser et déjà encaissé (droit Paiements), partielles et reportées (bons validés d'un jour passé non livrés). Carte de toutes les tournées depuis la boutique (premier lieu de pointage placé sur la carte), liste par livreur avec l'état et l'encaissement de chaque arrêt. « Réordonner la tournée » range les arrêts restants au plus proche, à vol d'oiseau. « Prévenir les clients » ouvre un message WhatsApp prêt par client (envoi groupé avec l'API WhatsApp).

### Écrans du livreur et suivi des livraisons (15/10/2026)

Décision de Yaya : « Pour le rôle livreur, il faut dédier des vues pour eux, car ils ne voient absolument rien sauf les livraisons et leur tournée, rien d'autre. » Et : « pour les admins, la caisse et les prépresses, ils ont un aperçu détaillé, savoir si c'est livré ou pas, à quel moment et par qui. »

**Le livreur seul.** Une personne dont le seul droit est « Livraisons » est un livreur seul (`LivreurSeul`). Son droit « Ses propres statistiques » ne compte pas : il montrait ses ventes et encaissements, et le rôle Livreur ne l'a plus (migration `Version20261016081500`). Dès qu'un autre droit est coché pour la personne (clients, entretien…), elle n'est plus livreur seul et retrouve les écrans de ce droit.

- Son menu : **Ma tournée** et **Mes livraisons**, plus « Mon espace » (pointage, absences, prime, bulletins). Sur le téléphone, la barre du bas montre Tournée, Livraisons, Pointage et Moi. Il n'a ni la recherche, ni la messagerie, ni « Aller à… », ni ses statistiques.
- Toute autre page lui répond « accès refusé », même tapée à la main : tableau de bord (il arrive sur sa tournée), clients, commandes, statistiques, recherche, messagerie, planning, liste générale des livraisons, « À retirer », suivi des livraisons. La règle est dans `LivreurSeulSubscriber`, avant le contrôleur. Les pages ouvertes à tout le personnel pour lui-même restent : Mon espace, pointage, mot de passe, guide de son rôle, ses notifications.
- Ses notifications : seulement celles qui mènent à une page qu'il peut ouvrir. Il ne reçoit plus « Production terminée » ni les autres alertes de la boutique.
- **Ma tournée** (téléphone d'abord) : les livraisons du jour dans l'ordre de la tournée. Pour chacune : le client, le téléphone à toucher pour appeler, l'adresse et « Voir sur la carte », les articles, et le reste à encaisser s'il y en a un (sur ses propres livraisons seulement). Puis l'étape suivante (Chargée, Je pars, Je suis arrivé), **Livrée** (la remise : nom de la personne qui reçoit, obligatoire, signature, photos, encaissement) ou **Échec** avec la raison.
- **Échec** : la raison se choisit dans une liste (client absent, client injoignable, adresse introuvable, client refuse la livraison, paiement pas prêt, autre avec une précision). On garde la date et l'heure, le livreur et la raison. Les étapes du passage sont effacées : le colis revient à la boutique. Le bureau (droit Commandes) et la Direction reçoivent une notification « à replanifier », sans montant. La livraison redevient « à livrer » quand le bureau lui donne un jour, quand le livreur touche « Réessayer » (le client a rappelé), ou à la remise. Le dernier échec reste dans l'historique.
- **Mes livraisons** (`/livraisons/mes-livraisons`) : ses livraisons des 30 derniers jours (ou 90), jour par jour : remises (heure, articles, reçue par) et échecs (raison). Aucun montant. Une remise s'ouvre sur son détail.

**Le suivi pour le bureau.** Direction, caisse (droit Commandes) et prépresse (droit Prépresse), sans droit nouveau :

- **Suivi des livraisons** (`/livraisons/suivi`, menu Ventes) : filtres par dates (aujourd'hui, 7 jours, 30 jours, à venir), par livreur et par statut (à livrer, en tournée, livrée, échec). Pour chaque livraison : jour prévu, numéro du bon et de la commande, client et articles, statut, le moment (remise, échec ou dernière étape), le livreur, la personne qui a reçu ou la raison de l'échec. Sans montant.
- **Bloc « Livraison »** sur la page d'une commande : chaque bon avec son statut, le moment, le livreur et la personne qui a reçu ; « à livrer » quand la commande n'a pas encore de bon.
- Le planning du jour (L01) compte les échecs du jour et montre la raison sur l'arrêt ; le détail d'une livraison (L02) résume le statut, le moment, le livreur et la personne qui a reçu.

**En ligne.** Ma tournée, la remise, Mes livraisons et le suivi n'existent que sur l'ERP local : en ligne, ils répondent « page introuvable » (le site ne connaît ni le livreur ni le réceptionnaire). Un livreur qui se connecte en ligne arrive sur son espace. L'espace client voit « Reportée » et la date après un échec (`echecLe` et « en cours » passent au site), jamais la raison ni le livreur ; rien de plus n'est envoyé au client.

Reste : la position du livreur en direct, L04 (suivi par le client, avec l'espace client J5) et l'étape « Pose » de la maquette.

## Ordre proposé

1. **D01 à D04 :** les documents servent tous les jours. Le montant en lettres et les mentions par société sont la base.
2. **L02 et L03 :** la remise sur mobile et l'encaissement provisoire.
3. **L01 :** le planning avec la carte.
4. **L04 :** le suivi par le client, avec l'espace client (J5).
