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

## État (11/10/2026)

Étape 1 construite : Accueil › Planning (`/planning`).
- **Entretien** : une échéance de catégorie Entretien a un créneau (début et fin) et peut être une fermeture avec checklist. Elle garde sa date (pas de décalage). La cocher ouvre la validation par photo de l'agent (docs/suivi-metiers.md, étape 5).
- **Calcul des dates** (`src/Service/Planning/Calendrier.php`, testé seul) : répétitions, décalage au jour ouvré précédent ou suivant (lundi à vendredi, hors fériés), ou date gardée. Les fêtes fixes du Mali sont dans la classe ; les fêtes mobiles se saisissent dans Planning › Jours fériés (direction), et les tâches à venir non commencées sont recalculées. « Chaque jour » suit les jours d'ouverture de la tuile Horaires (le samedi compte pour la clôture de caisse), sans décalage.
- **Échéances et tâches** (tables `echeance`, `tache`, `jour_ferie`) : une échéance crée ses tâches sur 13 mois glissants. Modifier la règle réécrit les tâches futures pas encore commencées ; « Arrêter l'échéance » retire les futures non commencées. Une tâche datée à la main garde sa date ; les modèles avancent au jour ouvré précédent.
- **T01** : vues Jour, Semaine, Mois, filtres par catégorie, tâche décalée barrée à sa date d'origine, colonnes Aujourd'hui (à cocher), À ne pas manquer (compte à rebours) et En retard. **T02** : formulaire avec modèles prêts, étapes, rappels, montant estimé et les 3 prochaines dates calculées pendant la saisie, historique des occurrences faites. **T04** : Planning › Mes tâches (Aujourd'hui, Semaine, En retard, prochain rappel).
- **Droits** : chacun voit les tâches dont il est responsable ou en copie ; la direction (administrateur) voit et gère tout ; Fiscal et Personnel et paie s'ouvrent aussi au droit trésorerie (comptable). Les montants demandent le droit trésorerie. Hors direction, on ne crée des tâches que pour soi. Le « chef d'équipe » n'existe pas encore dans les rôles : il voit ses propres tâches seulement.
- **Rappels dans l'application** : la commande `app:planning:rappels`, à lancer chaque heure, prolonge les échéances et envoie les notifications (5 jours avant et la veille au responsable, le jour même dès 8 h à la direction). Les tâches en retard s'affichent en alerte rouge sur le tableau de bord.

```
0 * * * * cd /chemin/du/site && php bin/console app:planning:rappels
```

Étape 2 construite (12/10/2026) : rappels WhatsApp et notifications sur le téléphone.
- **Rappels WhatsApp (par lien)** : à chaque rappel au responsable (5 jours avant, veille, jour même, et en retard depuis 7 jours au plus), la commande `app:planning:rappels` prépare un message WhatsApp quand le dossier de la personne a un téléphone, sauf pour les tâches de chaque jour. La direction les voit dans Planning › Rappels à envoyer (`/planning/rappels`, administrateur) ; le bouton WhatsApp ouvre la conversation avec le message prêt et note l'heure et qui l'a ouvert. Un rappel non envoyé disparaît de la liste après 7 jours ou quand la tâche est faite. Le message ne contient jamais de montant. Décision du 12/10/2026 : on laisse la clé Meta de côté, l'envoi automatique par l'API n'est pas fait.
- **Alerte de retard** : la case « En retard » de l'échéance envoie maintenant aussi une notification au responsable.
- **Notifications sur le téléphone** : Planning › Mes tâches, bloc « Notifications sur ce téléphone » → Activer. Chaque notification de la cloche part alors aussi sur les téléphones de la personne, même verrouillés (envoi à la fin de la page ou de la commande). Chiffrement et signature faits sans bibliothèque (`src/Service/Push/WebPush.php`, vérifié sur l'exemple de la RFC 8291). Il faut une adresse https (ou localhost) ; sur iPhone, iOS 16.4 ou plus et l'application ajoutée à l'écran d'accueil (Partager › Sur l'écran d'accueil). Une fois par serveur :

```
php bin/console app:push:cles
```

puis copier les deux lignes affichées dans `.env.local` (jamais dans le dépôt) et `php bin/console cache:clear`. Sans clés, le bloc indique que ce n'est pas encore disponible.
- Le texte de la notification est celui de la cloche : il s'affiche sur l'écran verrouillé.

Étape 3 construite (12/10/2026), sauf le rapport TVA :
- **Dépense préparée** : une tâche avec un montant estimé, cochée faite, prépare un décaissement **en attente** (montant estimé, compte et catégorie choisis sur l'échéance ; par défaut la première caisse active et une catégorie tirée de la catégorie de la tâche). Il apparaît dans Finances › À valider et n'est jamais passé tout seul : celui qui valide vérifie le montant réel et le reçu, ou rejette avec un motif. Décocher la tâche annule la dépense tant qu'elle n'est pas validée ; une dépense validée reste. La fiche de la tâche montre la dépense et son état (droit trésorerie).
- **Trésorerie prévisionnelle** : chaque tâche avec un montant, pas encore faite, compte dans les charges prévues (30 jours) à sa date, avec le libellé « Planning · … ».
- Attention aux doublons : une charge déjà saisie en décaissement récurrent (ancien écran) et aussi en échéance du planning compte deux fois dans la prévision. Garder l'une des deux.

Reste : le montant « TVA du mois » calculé et le rapport TVA joint (à voir avec le comptable), puis l'étape 4 (planning annuel T03).
