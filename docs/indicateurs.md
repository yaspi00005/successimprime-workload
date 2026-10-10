# Indicateurs des tableaux de bord

Référence fournie par Yaya le 10/10/2026. Pour chaque indicateur : ce qu'il mesure, comment il se calcule, et quand une alerte se déclenche. Les seuils sont des propositions de départ, réglables dans les paramètres.

## Règles communes

- Comparaison avec le mois précédent et le même mois de l'an dernier (flèche et %).
- Filtre par période, agent, machine, produit et client.
- Un clic ouvre la liste détaillée, puis le document d'origine.
- Seules les opérations validées en local comptent. Les opérations provisoires sont affichées à part.

## 1. Direction (vue d'ensemble)

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| CA facturé (jour, semaine, mois, année) | Somme des factures TTC et HT émises | Comparé à l'objectif mensuel (jauge) |
| CA encaissé | Somme des encaissements validés | — |
| Taux d'encaissement | Encaissé ÷ facturé | < 80 % |
| Marge brute (F et %) | CA HT − coût de revient réel | < 35 % |
| Résultat du mois | Marge brute − charges du mois | Négatif |
| Trésorerie disponible | Banque + Orange Money marchand + caisses | Sous le seuil de sécurité |
| Trésorerie prévue à 30, 60 et 90 jours | Disponible + encaissements attendus − dépenses prévues | Solde prévu négatif |
| Créances totales | Factures non payées | > 20 % du CA mensuel |
| Croissance mensuelle | (CA du mois − CA du même mois N−1) ÷ CA N−1 | Croissance négative |
| Nombre de commandes | Commandes confirmées sur la période | — |
| Panier moyen | CA ÷ nombre de commandes | — |
| Nouveaux clients | Premier achat sur la période | — |
| Top 5 clients et top 5 produits | Classés par CA et par marge | — |
| Commandes en retard | Date promise dépassée et commande non livrée | ≥ 1 |
| Opérations en attente de validation | Opérations provisoires saisies en ligne | En attente depuis plus de 24 h |

## 2. Ventes et commercial

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Devis émis (nombre et montant) | Devis créés sur la période | — |
| Taux de transformation | Devis acceptés ÷ devis émis | < 50 % |
| Délai moyen de transformation | Date d'acceptation − date d'envoi | > 7 jours |
| Devis à relancer | Devis envoyés sans réponse depuis 3 jours | ≥ 1 |
| Devis expirés ou perdus | Avec le motif de perte | — |
| CA par agent | Factures rattachées à chaque agent | — |
| CA par source | Boutique, site, WhatsApp, recommandation | — |
| CA par famille de produits | Grand format, DTF, petit format, signalétique | — |
| Remises accordées | Montant et % du CA, par agent | > 5 % du CA |
| Commandes du site en attente de prise en charge | Créées en ligne, pas encore importées en local | Plus de 2 h |

## 3. Caisse et encaissements

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Encaissements du jour par moyen | Espèces, Orange Money Mali, Moov, virement, chèque | — |
| Encaissements par caisse et par caissier | — | — |
| Solde théorique de caisse | Fond de caisse + entrées − sorties | — |
| Écart de caisse | Montant compté − montant théorique | Écart différent de 0 |
| Clôture de caisse | Faite ou non faite, heure | Non faite après 20 h |
| Paiements Orange Money à rapprocher | Reçus mais pas encore lettrés | ≥ 1 |
| Acomptes non encaissés | Commandes confirmées sans acompte | ≥ 1 (bloque l'impression) |

## 4. Production et atelier

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Commandes par statut | PAO, BAT envoyé, impression, finition, prête, livrée | — |
| m² imprimés | Par jour et par machine | Sous l'objectif du jour |
| Taux d'occupation des machines | Heures planifiées ÷ heures disponibles | > 90 % (surcharge) ou < 40 % |
| Délai moyen commande → livraison | En jours, par famille de produits | Au-delà du délai annoncé |
| Taux de respect des délais | Livrées à temps ÷ livrées | < 95 % |
| BAT en attente client | Envoyés et non validés | Plus de 48 h |
| Nombre moyen de BAT par commande | Versions envoyées ÷ commandes | > 2 |
| Taux de réimpression | Réimpressions ÷ travaux, avec la cause | > 3 % |
| Fichiers clients refusés | Rejetés au contrôle automatique | — |
| Travaux du jour et de demain | Par machine et par opérateur | — |
| Livraisons et poses planifiées | Par jour et par livreur | — |

## 5. Machines et consommables

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Compteur de m² par machine | Solvant 3,20 m, solvant 1,80 m, DTF | — |
| Consommation d'encre (ml/m²) | Encre consommée ÷ m² imprimés | Hausse de plus de 15 % par rapport à la moyenne |
| Encre restante par couleur | C, M, J, N et blanc DTF | < 25 % |
| Mètres de support restants | Par rouleau et par laize | Sous le seuil |
| Taux de chute | Surface perdue ÷ surface consommée | > 10 % |
| Temps d'arrêt | Pannes et maintenances, par machine | > 2 h par semaine |
| Prochaine maintenance | Nettoyage des têtes, calibrage, graissage | Échéance dépassée |
| Coût de maintenance | Par machine et par mois | — |

## 6. Stock et achats

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Valeur du stock | Quantité × coût moyen pondéré | — |
| Articles sous le seuil | Quantité < seuil minimum | ≥ 1 |
| Couverture du stock | Stock ÷ consommation moyenne journalière | < 7 jours |
| Rotation du stock | Consommation ÷ stock moyen | — |
| Écarts d'inventaire | Stock compté − stock théorique | Écart supérieur à 2 % |
| Achats du mois | Par fournisseur et par catégorie | — |
| Commandes fournisseurs en attente | Commandées mais pas encore reçues | Retard de livraison |

## 7. Finances et trésorerie

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Soldes par compte | Banque, Orange Money marchand, chaque caisse | — |
| Dépenses du mois par catégorie | Consommables, salaires, loyer, électricité, carburant, maintenance | Dépassement du budget |
| Charges fixes et charges variables | Avec leur part dans le CA | — |
| Seuil de rentabilité | Charges fixes ÷ taux de marge | CA en dessous du seuil |
| TVA collectée, déductible et à payer | Selon les écritures validées | Échéance proche |
| Factures fournisseurs à payer | Classées par échéance | Échues |
| Rapprochement bancaire en attente | Lignes de relevé non pointées | ≥ 1 |
| Écritures comptables en attente | Opérations validées non comptabilisées | ≥ 1 |

## 8. Créances clients

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Balance âgée | 0–30, 31–60, 61–90, plus de 90 jours | Toute somme à plus de 60 jours |
| Délai moyen de paiement (DSO) | Créances ÷ CA × 30 | > 30 jours |
| Clients au-dessus de leur plafond | Encours > plafond de crédit | Blocage automatique |
| Relances envoyées et résultat | Relances suivies d'un paiement dans les 7 jours | — |
| Top 10 des débiteurs | — | — |

## 9. Clients et relation client

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Clients actifs | Au moins un achat sur 12 mois | — |
| Clients inactifs | Aucun achat depuis 90 jours | Liste à relancer |
| Taux de fidélité | Clients ayant acheté au moins 2 fois ÷ clients | — |
| Prospects par étape | Nouveau, devis, négociation, gagné, perdu | — |
| Taux de gain des prospects | Gagnés ÷ (gagnés + perdus) | — |
| Satisfaction moyenne | Note sur 5 après livraison | < 4,2 |
| Avis Google | Note et nombre d'avis | — |
| Réclamations ouvertes | Avec leur délai de résolution | Plus de 3 jours |
| Résultats des campagnes | Envoyés, lus, devis, commandes | — |
| Points fidélité | Distribués et utilisés | — |

## 10. Équipe

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Commandes traitées par employé | — | — |
| m² imprimés par opérateur | — | — |
| Présences et absences | — | Absence non justifiée |
| Notes de frais en attente | — | — |
| Actions sensibles | Suppressions, remises, annulations, depuis le journal d'activité | Action inhabituelle |

## 11. Système, en ligne et synchronisation

| Indicateur | Calcul | Alerte / objectif |
|---|---|---|
| Dernière synchronisation réussie | Heure | Plus de 15 minutes |
| File d'attente de synchronisation | Éléments en attente d'envoi ou de réception | > 0 depuis plus de 10 minutes |
| Erreurs de synchronisation | Sur les dernières 24 h | ≥ 1 non résolue |
| Opérations provisoires en ligne | Nombre et montant | Plus de 24 h |
| Connexions refusées | Appareils inconnus | ≥ 1 |
| Sauvegarde quotidienne | Faite ou non faite | Non faite |
| Visites du site, demandes de devis en ligne, taux de conversion | — | — |

## 12. Écran TV public

Uniquement des volumes et des pourcentages, jamais de montants ni de noms de clients :

- commandes livrées (cumul et croissance sur 12 mois) ;
- m² imprimés (cumul et croissance) ;
- clients servis (cumul et croissance) ;
- note de satisfaction et nombre d'avis Google ;
- courbe des commandes par mois sur 12 mois ;
- commandes prêtes à retirer, par numéro seulement.

## Tableaux de bord par rôle

| Rôle | Tableaux de bord |
|---|---|
| Direction | Tous |
| Caissier et commercial | 2, 3, et 9 en partie |
| Graphiste PAO et production | 4 et 5 |
| Comptable | 3, 7 et 8 |
| Livreur | Livraisons du jour seulement |
