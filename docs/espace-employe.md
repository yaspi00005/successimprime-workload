# Espace employé

Demande de Yaya le 10/10/2026. Aucune maquette dédiée pour l'instant ; ce document fixe le périmètre retenu.

Chaque employé qui a un compte voit son espace : ses chiffres, ses demandes et leurs réponses. L'administrateur valide tout depuis Réglages, Équipe et droits.

## Mes chiffres

Les statistiques de l'agent, selon son métier, comparées au mois précédent (indicateurs 10 de `docs/indicateurs.md`) :

| Métier | Chiffres |
|---|---|
| Commercial, caissier | Devis envoyés, taux de transformation, commandes saisies, CA facturé, encaissements, remises accordées |
| Graphiste PAO | BAT envoyés, BAT validés du premier coup, délai moyen de BAT |
| Opérateur | m² imprimés, travaux terminés, taux de réimpression |
| Livreur | Livraisons faites, livrées à l'heure |

L'administrateur voit les mêmes chiffres pour chaque agent, et le classement de l'équipe.

## Absences

- L'employé déclare une absence : date, journée ou demi-journée, motif, justificatif (photo).
- Un responsable la valide ou la refuse. Une absence non justifiée déclenche l'alerte de l'indicateur 10.
- Un calendrier montre qui est absent, pour planifier l'atelier.

## Congés

- Le droit aux congés est **activé par l'administrateur**, employé par employé, avec son solde de jours.
- L'employé demande une période ; l'administrateur accepte ou refuse.
- L'administrateur peut aussi **demander à un employé de choisir ses dates** : il fixe une plage (ex. du 01/07 au 31/08) et un nombre de jours à ne pas dépasser. L'employé choisit dans cette plage, l'application refuse un choix trop long ou hors plage, puis l'administrateur confirme.
- Le solde se met à jour à chaque congé accepté.

## Réclamations de sommes dues

L'employé réclame une somme qu'il estime lui être due : heures supplémentaires, prime, frais avancés pour le travail (carburant, achat urgent), reste de salaire.

- Montant, motif, date et pièce jointe (reçu, photo).
- L'administrateur accepte (en totalité ou en partie) ou refuse, avec un commentaire.
- Une réclamation acceptée devient une dépense à payer dans Trésorerie et dépenses, rattachée à l'employé.
- Les réclamations en attente apparaissent dans les indicateurs (« notes de frais en attente »).

## État (11/10/2026)

Construit :

- **Mon espace** (`/mon-espace`, lien dans le menu du profil) : présence et ponctualité du mois (pointage), points d'actions de caisse pour les caissiers, solde de congés, et l'historique des demandes avec la réponse de la RH. Chacun ne voit que ses demandes.
- **Demandes** : absence (jour, demi-journée ou période), congé (contrôlé contre le solde et la plage fixée par la RH ; les jours fériés et les jours non travaillés de la tuile Horaires ne comptent pas), réclamation (heures sup, prime, frais avancés, reste de salaire) et frais médicaux (justificatif obligatoire, photo ou PDF de 5 Mo au plus, rangé hors du dossier public).
- **Côté RH** (Réglages › Demandes du personnel, droit « Administrateur RH ») : demandes à traiter, acceptation totale ou en partie, refus avec commentaire obligatoire, calendrier des absents du mois, droits aux congés par employé (activation, solde, plage et nombre de jours max). Les frais médicaux proposent le taux et le plafond annuel de la tuile Avantages.
- **Paiement** des sommes accordées (droit de saisie en trésorerie) : décaissement de catégorie salaire, marqué confidentiel, à valider comme tout mouvement.
- L'employé est prévenu à chaque réponse et à chaque paiement ; la RH à chaque nouvelle demande.

Reste : les statistiques « Mes chiffres » par métier arrivent avec le suivi des métiers (docs/suivi-metiers.md).

## Paie (11/10/2026)

Équipe › Paie (`/equipe/paie`, droit Administrateur RH), ouvert aussi depuis la tuile Paie.

- « Préparer la paie du mois » crée un bulletin par personne active avec le salaire de sa fiche employé ; les fiches sans salaire sont signalées.
- La RH complète le bulletin : prime, heures supplémentaires, indemnité, retenue pour absence (les jours d'absence acceptés du mois sont rappelés), avance, autre. Les lignes ITS et INPS n'apparaissent que si l'option est active dans la tuile ; leur montant est saisi, aucun taux n'est appliqué (décision de Yaya : pas de taux pour le moment, l'ITS s'active ou se désactive).
- Valider rend le bulletin visible par l'employé dans son espace (« Mes bulletins de paie », imprimable) et le prévient. Un bulletin validé peut être remis en brouillon tant qu'il n'est pas payé.
- Payer (droit Dépenses et recettes) crée un décaissement « Salaire » confidentiel sur le compte choisi ; la date de versement suit le jour de paie de la tuile, le mode de paiement est noté sur le mouvement.
- Les salaires ne sont visibles que par la RH et par l'employé concerné.
