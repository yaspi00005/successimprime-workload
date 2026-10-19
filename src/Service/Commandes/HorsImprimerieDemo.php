<?php

namespace App\Service\Commandes;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Démonstration des ventes « hors imprimerie » (app:demo,
 * docs/hors-imprimerie.md) : deux ventes de matériel informatique à des
 * entreprises fictives, cochées « Hors imprimerie », lignes sans
 * prépresse ni atelier, facturées ce mois-ci. Elles reprennent le client
 * et l'agent d'une commande fictive facturée (copie de ses colonnes).
 *
 * Écrit par la connexion de app:demo, dans sa transaction ; les id
 * au-dessus de 900000 partent avec la purge de la démonstration.
 */
final class HorsImprimerieDemo
{
    /** Commande fictive facturée qui sert de modèle, id de départ, désignation, quantité, prix unitaire, jours avant aujourd'hui. */
    private const VENTES = [
        [900758, 909500, 'Tablettes Samsung Galaxy Tab A9 (64 Go)', 5, 165000, 3],
        [900757, 909510, 'Ordinateurs portables HP 250 G9 (i5, 8 Go)', 2, 485000, 1],
    ];

    public static function charger(Connection $connexion): void
    {
        $maintenant = new \DateTimeImmutable();
        foreach (self::VENTES as [$modele, $id, $designation, $quantite, $prix, $jours]) {
            $commande = $connexion->fetchAssociative('SELECT * FROM commandes WHERE id = ?', [$modele]);
            $ligne = $connexion->fetchAssociative('SELECT * FROM commandes_details WHERE commande_id = ? ORDER BY id LIMIT 1', [$modele]);
            $facture = $connexion->fetchAssociative('SELECT * FROM factures WHERE commande_id = ? ORDER BY id LIMIT 1', [$modele]);
            if (false === $commande || false === $ligne || false === $facture) {
                continue;
            }
            $total = $quantite * $prix;
            $date = $maintenant->setTime(10, 30)->modify(\sprintf('-%d days', $jours))->format('Y-m-d H:i:s');
            $numero = \sprintf('CMD-HI%04d', $id - 909000);

            $connexion->insert('commandes', array_merge($commande, [
                'id' => $id, 'numero' => $numero, 'public_id' => Uuid::v4()->toBinary(),
                'date_commande' => $date, 'date_livraison' => $date, 'modifie_le' => $date,
                'remise' => 0, 'tva' => 0, 'total_ht' => $total, 'total_ttc' => $total, 'montant_apayer' => $total,
                'reste_apayer' => $total, 'total_paye' => 0, 'statut_paiement' => 'impayee',
                'objet' => 'Matériel informatique', 'hors_imprimerie' => 1, 'frais_livraison' => 0,
                'observation' => 'Démo : vente de matériel, comptée hors imprimerie.',
            ]));
            $connexion->insert('commandes_details', array_merge(self::sansColonnesProduit($ligne), [
                'id' => $id, 'commande_id' => $id, 'public_id' => Uuid::v4()->toBinary(),
                'designation' => $designation, 'type_ligne' => 'libre', 'quantite' => $quantite, 'prix_unitaire' => $prix,
                'remise' => 0, 'tva' => 0, 'total_ht' => $total, 'total_ttc' => $total,
                'statut_production' => 'non_requise', 'pre_presse_necessaire' => 0, 'production_necessaire' => 0,
                'production_debute_le' => null, 'production_terminee_le' => null, 'quantite_livree' => 0, 'quantite_produite' => 0,
                'cout_revient' => 0, 'surface' => null, 'largeur' => null, 'longueur' => null, 'libelle_promotion' => null, 'remise_promotion' => 0, 'montant_promotion' => 0,
            ]));
            $connexion->insert('factures', array_merge($facture, [
                'id' => $id, 'commande_id' => $id, 'numero' => \sprintf('F-HI%04d', $id - 909000), 'type_document' => 'facture', 'etat' => 'emise', 'pourcentage_acompte' => null, 'public_id' => Uuid::v4()->toBinary(),
                'token_authenticite' => bin2hex(random_bytes(32)), 'code_suivi' => \sprintf('HIMA-%02d', $id - 909500),
                'date_facture' => $date, 'date_echeance' => $maintenant->modify('+30 days')->format('Y-m-d 00:00:00'),
                'created_at' => $date, 'updated_at' => $date,
                'total_ht' => $total, 'remise' => 0, 'tva' => 0, 'montant_tva' => 0, 'total_ttc' => $total,
                'montant_paye' => 0, 'reste_apayer' => $total, 'statut_paiement' => 'impayee',
                'pdf_fichier' => null, 'pdf_genere_le' => null, 'pdf_hash' => null, 'objet' => 'Matériel informatique', 'frais_livraison' => 0,
            ]));
        }
    }

    /**
     * Ligne libre : sans produit ni configuration, et sans les colonnes
     * d'atelier de la ligne modèle.
     *
     * @param array<string, mixed> $ligne
     *
     * @return array<string, mixed>
     */
    private static function sansColonnesProduit(array $ligne): array
    {
        foreach (['produit_id', 'produit_configuration_id', 'type_impression_id', 'support_id', 'format_id', 'article_id', 'machine_id', 'production_debutee_par_id', 'production_terminee_par_id', 'produit_client_id', 'promotion_id', 'fichier_lie', 'fichier'] as $colonne) {
            if (\array_key_exists($colonne, $ligne)) {
                $ligne[$colonne] = null;
            }
        }

        return $ligne;
    }
}
