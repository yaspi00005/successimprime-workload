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
