<?php

namespace App\Service\Achats;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Démonstration de « Coûts et marges » (app:demo, maquette SU04,
 * docs/achats.md) : le transfert DTF A4 (film de Yinghe, vendu à la
 * pièce, comme sur la maquette) et quelques gros travaux de vinyle, de
 * micro-perforé et de kakémonos ce mois-ci et le mois d'avant, pour que
 * les produits des fournisseurs des maquettes aient du volume.
 *
 * Les commandes reprennent le client et l'agent d'une commande fictive
 * (copie de ses colonnes), comme HorsImprimerieDemo. Écrit par la
 * connexion de app:demo, dans sa transaction, après AchatsDemo ; les id
 * au-dessus de 900000 partent avec la purge de la démonstration.
 */
final class CoutsMargesDemo
{
    /** Commande fictive qui sert de modèle. */
    private const MODELE = 900724;

    private const PRODUIT_DTF = 900014;
    private const FILM_DTF = 900010;
    private const POUDRE_DTF = 900011;
    private const MACHINE_DTF = 900003;

    /**
     * Ventes : id, jours avant aujourd'hui (0 : le 2 du mois en cours au plus tôt), lignes
     * [produit, désignation, largeur m, hauteur m, quantité, prix unitaire, machine].
     */
    private const VENTES = [
        [909601, 6, [[900002, 'Lettrage vitrine, vinyle adhésif', 2.5, 1.2, 4, 7200, 900002], [900003, 'Vitrophanie micro-perforé', 1.8, 1.2, 3, 8100, 900002]]],
        [909602, 9, [[self::PRODUIT_DTF, 'Transferts DTF A4 · logo poitrine et dos', 0.21, 0.297, 120, 1500, self::MACHINE_DTF]]],
        [909603, 12, [[900006, 'Kakémonos salon de l\'emploi', null, null, 6, 26000, 900002], [900002, 'Habillage véhicule, vinyle', 3.0, 1.5, 2, 6500, 900002]]],
        [909604, 15, [[self::PRODUIT_DTF, 'Transferts DTF A4 · tenues d\'équipe', 0.21, 0.297, 140, 1500, self::MACHINE_DTF]]],
        [909605, 40, [[900002, 'Signalétique bureaux, vinyle', 1.2, 0.8, 12, 6800, 900002], [self::PRODUIT_DTF, 'Transferts DTF A4', 0.21, 0.297, 200, 1500, self::MACHINE_DTF]]],
        [909606, 48, [[900003, 'Micro-perforé agence', 2.0, 1.5, 3, 7800, 900002], [900006, 'Kakémonos', null, null, 4, 27000, 900002]]],
    ];

    public static function charger(Connection $connexion): void
    {
        self::produitDtf($connexion);

        $commande = $connexion->fetchAssociative('SELECT * FROM commandes WHERE id = ?', [self::MODELE]);
        $modele = $connexion->fetchAssociative('SELECT * FROM commandes_details WHERE commande_id = ? ORDER BY id LIMIT 1', [self::MODELE]);
        if (false === $commande || false === $modele) {
            return;
        }
        $aujourdhui = new \DateTimeImmutable('today');
        $debutMois = $aujourdhui->modify('first day of this month');

        foreach (self::VENTES as [$id, $jours, $lignes]) {
            $date = $aujourdhui->setTime(10, 15)->modify(sprintf('-%d days', $jours));
            // Ce mois-ci, jamais avant le 2 : les ventes « du mois » restent dans le mois.
            if ($jours < 20 && $date < $debutMois->modify('+1 day')) {
                $date = $debutMois->modify('+1 day')->setTime(10, 15);
            }
            $total = 0;
            $details = [];
            foreach ($lignes as $i => [$produit, $designation, $largeur, $hauteur, $quantite, $prix, $machine]) {
                $surface = null !== $largeur ? round($largeur * $hauteur, 4) : null;
                $ht = (int) round(null !== $surface && \in_array($produit, [900002, 900003], true) ? $surface * $quantite * $prix : $quantite * $prix);
                $total += $ht;
                $details[] = array_merge($modele, [
                    'id' => 908600 + ($id - 909600) * 10 + $i, 'commande_id' => $id, 'public_id' => Uuid::v4()->toBinary(),
                    'designation' => $designation, 'type_ligne' => 'produit', 'produit_id' => $produit, 'machine_id' => $machine,
                    'produit_configuration_id' => null, 'type_impression_id' => null, 'support_id' => null, 'format_id' => null, 'article_id' => null,
                    'produit_client_id' => null, 'promotion_id' => null, 'fichier_lie' => null, 'fichier' => null,
                    'largeur' => $largeur, 'longueur' => $hauteur, 'surface' => $surface, 'quantite' => $quantite, 'nombre_faces' => 1,
                    'prix_unitaire' => $ht / $quantite, 'remise' => 0, 'tva' => 0, 'total_ht' => $ht, 'total_ttc' => $ht, 'cout_revient' => 0,
                    'statut_production' => 'livree', 'quantite_produite' => $quantite, 'quantite_livree' => $quantite, 'quantite_rebut' => 0,
                    'production_debute_le' => null, 'production_terminee_le' => null, 'production_debutee_par_id' => null, 'production_terminee_par_id' => null,
                    'libelle_promotion' => null, 'remise_promotion' => 0, 'montant_promotion' => 0, 'remise_code_promo' => 0,
                    'quantite_demandee' => null, 'unite_demandee' => null, 'espacement_pieces_m2' => null,
                ]);
            }
            $connexion->insert('commandes', array_merge($commande, [
                'id' => $id, 'numero' => sprintf('CMD-CM%04d', $id - 909000), 'public_id' => Uuid::v4()->toBinary(),
                'date_commande' => $date->format('Y-m-d H:i:s'), 'date_livraison' => $date->modify('+2 days')->format('Y-m-d H:i:s'), 'modifie_le' => $date->format('Y-m-d H:i:s'),
                'remise' => 0, 'tva' => 0, 'total_ht' => $total, 'total_ttc' => $total, 'montant_apayer' => $total,
                'reste_apayer' => $total, 'total_paye' => 0, 'statut_paiement' => 'impayee', 'hors_imprimerie' => 0, 'frais_livraison' => 0,
                'objet' => $lignes[0][1], 'observation' => 'Démo : vente pour l\'écran Coûts et marges.',
            ]));
            foreach ($details as $d) {
                $connexion->insert('commandes_details', $d);
            }
        }
    }

    /**
     * Transfert DTF A4 : vendu à la pièce, film DTF de Yinghe (0,15 m par
     * transfert, deux A4 de front sur 60 cm) et poudre (6 g).
     */
    private static function produitDtf(Connection $connexion): void
    {
        $modele = $connexion->fetchAssociative('SELECT * FROM produits WHERE id = 900011');
        if (false === $modele || false !== $connexion->fetchOne('SELECT id FROM produits WHERE id = ?', [self::PRODUIT_DTF])) {
            return;
        }
        $connexion->insert('produits', array_merge($modele, [
            'id' => self::PRODUIT_DTF, 'nom' => 'Transfert DTF A4 (démo)', 'code' => 'DEMO-DTF-A4', 'public_id' => Uuid::v4()->toBinary(),
            'description' => 'Transfert DTF au format A4, prêt à presser sur le textile.', 'mode_calcul' => 'unite', 'prix_base' => 1500, 'prix_b2_b' => null,
            'article_stock_id' => self::FILM_DTF, 'machine_id' => self::MACHINE_DTF, 'machine_secours_id' => null, 'nom_commercial' => null, 'cout_revient_saisi' => null,
        ]));
        $lien = $connexion->fetchAssociative('SELECT * FROM produit_article_stock WHERE produit_id = 900011 ORDER BY id LIMIT 1') ?: [];
        foreach ([[900091, self::FILM_DTF, '0.150', 10], [900092, self::POUDRE_DTF, '0.006', 20]] as [$id, $article, $coefficient, $ordre]) {
            $connexion->insert('produit_article_stock', array_merge($lien, [
                'id' => $id, 'produit_id' => self::PRODUIT_DTF, 'article_id' => $article, 'coefficient' => $coefficient,
                'mode_calcul' => 'quantite', 'actif' => 1, 'obligatoire' => 1, 'ordre' => $ordre, 'observation' => null,
            ]));
        }
    }
}
