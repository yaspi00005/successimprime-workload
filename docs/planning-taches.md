# Planning et tâches

Yaya a envoyé 4 maquettes le 10/10/2026 avec un descriptif en 5 points. Elles sont dans `/mnt/project-files/maquettes/planning-taches/` :
- T01 calendrier du mois et tâches du jour ;
- T02 tâche récurrente (« impôts le 25 ») ;
- T03 planning annuel 2027 et approvisionnements ;
- T04 mobile : mes tâches et rappels.

Les noms, montants et fournisseurs des maquettes sont fictifs. Le point 2 du descriptif (tâches du jour, échéances avec compte à rebours) est la colonne de droite de T01 et la vue « Jour ».

## Ce qui existe déjà

- `DecaissementRecurrent` : charges fixes passées automatiquement en trésorerie (frais bancaires, crédit, loyer) par une commande planifiée.
- `MouvementTresorerie`, `CompteTresorerie` : la trésorerie.
- `Notification` : les notifications dans l'admin.
- Les liens WhatsApp manuels (`docs/messagerie-marketing.md`).

Le planning s'appuie dessus ; il ne remplace pas les décaissements récurrents, qui restent pour les charges qui se paient seules.

## Les notions

| Notion | Rôle |
|---|---|
| **Tâche** | Une chose à faire un jour donné, éventuellement à une heure, par un responsable. Statut : à faire, en cours, fait. « En retard » est calculé (date passée et pas faite). Elle peut avoir une liste d'étapes à cocher, une pièce jointe et un montant estimé. |
| **Échéance récurrente** | La règle qui crée les tâches : « Impôts chaque mois le 25 ». Les occurrences sont créées à l'avance sur 13 mois glissants, pour que le calendrier et le planning annuel les montrent. Modifier la règle met à jour les occurrences futures pas encore commencées. |
| **Catégorie** | Fiscal, fournisseurs, personnel et paie, maintenance (atelier), approvisionnements (stock), livraisons, commercial, administratif. Chaque catégorie a sa couleur dans le calendrier et ses filtres. |
| **Prévision** | Une ligne du planning annuel (T03) : un achat, une révision, une assurance, un investissement, avec ses mois et son montant. Elle réserve son montant dans la trésorerie prévisionnelle. |
| **Jour férié** | Table des jours fériés du Mali. Les fêtes fixes (1er janvier, 20 janvier, 26 mars, 1er mai, 25 mai, 22 septembre, 25 décembre) sont pré-remplies. Les fêtes religieuses qui bougent chaque année (Ramadan, Tabaski, Maouloud) sont saisies par l'admin quand les dates sont annoncées. |

## T02 Tâche récurrente

- **Répétition** : chaque jour, chaque semaine (jours choisis), chaque mois (le N du mois, ou le dernier jour), chaque trimestre, chaque année, une fois.
- **Week-end ou jour férié** : par défaut, la tâche est avancée au jour ouvré précédent. Le 25 octobre 2026 est un dimanche, donc vendredi 23. Le 1er novembre 2026 est un dimanche, donc le paiement fournisseur passe au vendredi 30 octobre. Le 25 décembre est férié, donc jeudi 24. Deux autres choix sont possibles : reporter au jour ouvré suivant, ou garder la date.
- **Début et fin** : une date de début, puis sans fin, jusqu'à une date ou un nombre de fois.
- **Responsable** : un utilisateur, avec une copie possible (par exemple « Comptable, copie Direction »). **Entreprise** : l'entreprise concernée (MDG, DREPA…), reprise de la table des entreprises.
- **Étapes** : une liste à cocher, recopiée sur chaque occurrence. La tâche affiche « 2 étapes sur 4 faites ».
- **Rappels**, chacun activable :
  - 5 jours avant : WhatsApp au responsable ;
  - la veille : WhatsApp et notification ;
  - le jour même à 8 h : notification à la direction ;
  - en retard : alerte rouge sur le tableau de bord.
- **Prochaines dates** : la colonne de droite montre les 3 prochaines dates calculées, avec la raison du décalage (« le 25 est un dimanche »).
- **Historique** : qui a fait quoi et quand, occurrence par occurrence.

### Modèles prêts

Une liste de modèles pré-remplis, modifiables :

| Modèle | Règle | Catégorie |
|---|---|---|
| Déclaration et paiement des impôts | le 25 de chaque mois | Fiscal |
| Payer un fournisseur | le 1er de chaque mois | Fournisseurs |
| Loyer | le 5 de chaque mois | Administratif |
| Salaires | le 5 de chaque mois | Personnel et paie |
| Relevés des compteurs machines | le 1er de chaque mois | Maintenance |
| Clôture de caisse | chaque jour ouvré à 19 h | Administratif |
| Nettoyage des têtes | chaque jour ouvré à 8 h | Maintenance |

## Lien avec l'ERP

- **Rapport TVA joint** : pour une tâche fiscale, le montant estimé peut être « Calculé : TVA à payer du mois ». Il est calculé à partir des factures validées du mois (TVA collectée), moins la TVA des achats quand elle est saisie. Le rapport PDF du mois est joint automatiquement à la tâche. Le taux reste à confirmer avec le comptable (18 % au Mali).
- **Décaissement préparé** : quand la tâche est marquée faite, un décaissement est préparé avec le montant, la catégorie et le compte. Il reste **à valider** par quelqu'un qui a le droit trésorerie ; il n'est jamais passé tout seul. On peut joindre le reçu à ce moment-là.
- **Trésorerie prévisionnelle** : chaque montant estimé (tâche ou prévision) apparaît dans la trésorerie prévisionnelle à sa date, jusqu'à ce qu'il soit payé ou annulé.
- **Stock** : quand un article passe sous son seuil, une tâche « Commander… » peut être créée pour le responsable des achats, avec le bon de commande prêt (lié au stock S01–S09).

## T01 Calendrier du mois

- Vues Jour, Semaine, Mois, Année ; bouton « + Tâche ».
- Filtres par catégorie (les pastilles de couleur en haut).
- Aujourd'hui est entouré. Une tâche décalée est notée à sa date d'origine, barrée, avec « dimanche → avancé au 23 ».
- Colonne de droite :
  - **Aujourd'hui** : les tâches du jour, avec qui, à quelle heure et une case à cocher. Une tâche faite est barrée avec l'heure (« fait 08:10 »).
  - **À ne pas manquer** : les prochaines échéances importantes, avec le compte à rebours (« dans 13 jours »).
  - **En retard** : un encadré orange avec le nombre et la plus ancienne.

## T03 Planning annuel

- Une ligne par échéance ou prévision, 12 colonnes de mois, une colonne Budget (« 4 × 1,4 M F », « mensuel », « 2 interventions »).
- Les saisons sont des repères pour placer les achats : rentrée (septembre), Ramadan, Tabaski, fêtes de fin d'année. Les dates des fêtes viennent de la table des jours fériés.
- **Approvisionnements proposés** : les quantités sont calculées à partir de la consommation de l'année passée et des délais des fournisseurs. Ce calcul a besoin des mouvements de stock (S03, S04) ; avant ça, on saisit les quantités à la main.
- **Budget annuel** : le total des achats prévus, relié à la trésorerie prévisionnelle.
- Boutons : année précédente, PDF, « + Échéance ».

## T04 Mobile

- **Mes tâches** : onglets Aujourd'hui, Semaine, En retard ; cocher une tâche ou une étape d'un doigt ; en bas, le prochain rappel important.
- **Notifications sur le téléphone** (écran verrouillé) : notifications web (« push ») de l'application installée sur l'écran d'accueil. Sur iPhone, il faut iOS 16.4 ou plus et l'application ajoutée à l'écran d'accueil. Les clés de notification se mettent dans `.env.local` sur le serveur, jamais dans le dépôt.
- Les notifications du stock (« Bâche 510 g sous le seuil ») passent par le même canal.

## Les rappels WhatsApp

Comme décidé pour la messagerie : tant que la clé WhatsApp (API Meta) est vide, l'envoi se fait par lien.
- **Sans clé** : à l'heure du rappel, le responsable reçoit une notification dans l'application, et la direction voit une liste « Rappels à envoyer » avec un bouton WhatsApp par rappel (lien `wa.me` avec le message prêt). Un lien ne part pas tout seul : quelqu'un doit toucher le bouton.
- **Avec la clé** : les rappels partent seuls. Meta impose des modèles de message approuvés pour écrire en premier ; il faudra faire valider les 3 modèles (5 jours avant, veille, jour même).

## Droits

- Chacun voit ses propres tâches. Le chef d'équipe voit celles de son équipe ; la direction voit tout.
- Les montants (encres 275 000 F, salaires, impôts) ne s'affichent qu'avec le droit trésorerie ou finances. Sans ce droit, la tâche reste visible sans le montant.
- Les catégories Fiscal et Personnel et paie ne sont visibles que du comptable et de la direction, sauf pour le responsable désigné.
- Le planning annuel et le budget sont réservés à la direction et à la finance.

## Ordre de construction

1. **Tâches et calendrier** : tâches, étapes, échéances récurrentes avec le décalage week-end et fériés, modèles, T01 et la vue Jour, « Mes tâches » sur mobile, notifications dans l'application, alerte de retard au tableau de bord.
2. **Rappels** : WhatsApp par lien puis par API, notifications sur le téléphone.
3. **Lien ERP** : rapport TVA joint, décaissement préparé, trésorerie prévisionnelle.
4. **Planning annuel** : prévisions, budget, PDF, puis les quantités proposées quand le stock aura un an de mouvements.

Le calcul des dates (répétition, week-end, fériés) est une classe sans base de données, testée seule, comme le programme de l'écran TV.
