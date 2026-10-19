<?php

namespace App\Service\Achats;

use Doctrine\DBAL\Connection;

/**
 * Démonstration des écrans Achats (app:demo, docs/achats.md, maquettes
 * SU01 à SU03) : cinq fournisseurs complétés comme sur les maquettes,
 * douze mois de commandes d'achat avec quelques retards et un rouleau
 * abîmé, une commande en route depuis la Chine, deux factures
 * fournisseurs à payer, et une consommation des 12 dernières semaines
 * qui met le micro-perforé en alerte (avec le vinyle à commander dans
 * les 14 jours, chez le même fournisseur).
 *
 * Les commandes fictives générées par demo.sql pour ces fournisseurs
 * sont remplacées. Écrit par la connexion de app:demo, dans sa
 * transaction ; les id au-dessus de 900000 partent avec la purge.
 */
final class AchatsDemo
{
    /*
     * Enlevé avant chaque chargement et par --purger : les dépenses ne
     * sont pas dans demo-purge.sql.
     */
    public const PURGE = [
        'DELETE FROM depense WHERE id >= 905000 AND id < 906000',
    ];

    private const SAHEL = 900001;
    private const DAKAR = 900002;
    private const ENCRES = 900003;
    private const YINGHE = 900006;
    private const KANTE = 900007;

    private const BACHE_510 = 900001;
    private const BACHE_440 = 900002;
    private const VINYLE = 900003;
    private const MICRO = 900004;
    private const AKYLUX = 900005;
    private const ENCRE_CYAN = 900006;
    private const ENCRE_MAGENTA = 900007;
    private const ENCRE_JAUNE = 900008;
    private const ENCRE_NOIR = 900009;
    private const FILM_DTF = 900010;
    private const POUDRE_DTF = 900011;
    private const ROLL_UP = 900015;

    /**
     * Fiche de chaque fournisseur : nom, téléphone, contact, ville, pays,
     * fournit, conditions, acompte, transport, délai promis, couleur.
     */
    private const FOURNISSEURS = [
        self::SAHEL => ['Sahel Supplies (démo)', 60163361, 'Moussa Traoré', '+223 60 16 33 61', 'Bamako', 'Mali', 'Bâches 440 g et 510 g', 'espèces à la livraison', 0, 'livraison camionnette', 2, '#0F4590'],
        self::DAKAR => ['Dakar Signs (démo)', 60895987, 'M. Ndiaye', '+221 77 412 58 10', 'Dakar', 'Sénégal', 'Vinyles, micro-perforé', 'virement, 50 % à la commande', 50, 'camion groupé Dakar → Bamako', 7, '#E95B35'],
        self::ENCRES => ['Encres Pro Bamako (démo)', 60473558, 'Awa Coulibaly', '+223 60 47 35 58', 'Bamako', 'Mali', 'Encres solvant', 'à 15 jours', 0, 'livrées à l\'atelier', 1, '#D6336C'],
        self::YINGHE => ['Yinghe Distribution (démo)', null, 'Lily Chen', '+86 138 0013 8000', 'Canton', 'Chine', 'Film, poudre et encres DTF', 'virement, 100 % à la commande', 100, 'fret aérien Canton → Bamako', 18, '#0F766E'],
        self::KANTE => ['Kanté Bois & Alu (démo)', 60778812, 'Ibrahima Kanté', '+223 60 77 88 12', 'Bamako', 'Mali', 'Structures roll-up, cadres', 'espèces, 30 % à la commande', 30, 'retrait à l\'atelier Kanté', 3, '#7B3FC4'],
    ];

    /**
     * Unité d'achat des articles : unité, quantité de stock par unité,
     * longueur, laize, prix d'une unité d'achat, minimum, maximum,
     * fournisseur, délai noté sur l'article.
     */
    private const ARTICLES = [
        self::BACHE_510 => ['rouleau', 160.0, 50.0, 3.2, 240000, 60, 480, self::SAHEL, 2],
        self::BACHE_440 => ['rouleau', 80.0, 50.0, 1.6, 96000, 40, 400, self::SAHEL, 2],
        self::VINYLE => ['rouleau', 76.0, 50.0, 1.52, 159600, 30, 330, self::DAKAR, 7],
        self::MICRO => ['rouleau', 68.5, 50.0, 1.37, 226050, 15, 150, self::DAKAR, 7],
        self::AKYLUX => ['plaque', 3.0, null, null, 15600, 20, 120, self::DAKAR, 7],
        self::ENCRE_CYAN => ['litre', 1.0, null, null, 28000, 2, 12, self::ENCRES, 1],
        self::ENCRE_MAGENTA => ['litre', 1.0, null, null, 28000, 2, 12, self::ENCRES, 1],
        self::ENCRE_JAUNE => ['litre', 1.0, null, null, 28000, 2, 12, self::ENCRES, 1],
        self::ENCRE_NOIR => ['litre', 1.0, null, null, 26000, 4, 12, self::ENCRES, 1],
        self::FILM_DTF => ['rouleau', 100.0, 100.0, null, 165000, 50, 400, self::YINGHE, 18],
        self::POUDRE_DTF => ['sac', 1.0, null, null, 9500, 3, 30, self::YINGHE, 18],
        self::ROLL_UP => ['unité', 1.0, null, null, 16000, 10, 60, self::KANTE, 3],
    ];

    /**
     * Stock voulu aujourd'hui et consommation par semaine sur les 12
     * dernières semaines (sorties de production fictives).
     */
    private const STOCK = [
        self::BACHE_510 => [38.4, 22.0],
        self::BACHE_440 => [260.0, 15.0],
        self::VINYLE => [57.8, 14.5],
        self::MICRO => [24.7, 9.0],
        self::ENCRE_CYAN => [6.0, 0.3],
        self::ENCRE_MAGENTA => [6.5, 0.3],
        self::ENCRE_JAUNE => [7.0, 0.25],
        self::ENCRE_NOIR => [0.8, 0.35],
        self::FILM_DTF => [300.0, 50.0],
        self::POUDRE_DTF => [14.0, 1.5],
        self::ROLL_UP => [134.0, 4.0],
    ];

    /**
     * Commandes : fournisseur, jours avant aujourd'hui, jours de transport
     * réels (null : en route), lignes [article, quantité en unité
     * d'achat, prix, abîmées], transport, transit.
     */
    private static function commandes(): array
    {
        $commandes = [];

        // Sahel Supplies : bâches toutes les trois semaines, livrées en 2 jours (une fois 4).
        foreach ([350, 329, 308, 287, 266, 245, 224, 203, 182, 161, 140, 119, 98, 77, 56, 35, 14, 6] as $i => $jours) {
            $prix = 236000 + ($i > 8 ? 4000 : 0);
            $commandes[] = [self::SAHEL, $jours, $i === 7 ? 4 : 2, [[self::BACHE_510, $i % 3 === 0 ? 3 : 2, $prix, 0], [self::BACHE_440, 1, 96000, 0]], 15000, 0, 'Camionnette Sahel', 'livraison atelier', null, null];
        }

        // Dakar Signs : un camion groupé par mois, promis en 7 jours ; deux retards (frontière), un rouleau écrasé.
        $dakar = [[340, 7], [312, 7], [284, 7], [253, 12], [222, 7], [190, 7], [161, 7], [131, 14], [102, 7], [67, 7], [26, 7]];
        foreach ($dakar as $i => [$jours, $delai]) {
            $prixVinyle = [150600, 150600, 151200, 151200, 153000, 154800, 154800, 156600, 157200, 158400, 159600][$i];
            $prixMicro = 226050;
            $lignes = [[self::VINYLE, $i % 2 === 0 ? 4 : 3, $prixVinyle, $i === 3 ? 1 : 0]];
            if ($i % 3 !== 1) {
                $lignes[] = [self::MICRO, 2, $prixMicro, 0];
            }
            $commandes[] = [self::DAKAR, $jours, $delai, $lignes, 0, 0, 'Transport camion Dakar → Bamako', 'groupage, 2 colis', 'Transit et frontière', 'transitaire Kayes'];
        }

        // Encres Pro Bamako : deux fois par mois, livrées le lendemain (une fois en 3 jours).
        for ($jours = 352, $i = 0; $jours > 3; $jours -= 15, ++$i) {
            $commandes[] = [self::ENCRES, $jours, $i === 11 ? 3 : 1, [[self::ENCRE_NOIR, 2, 26000, 0], [[self::ENCRE_CYAN, self::ENCRE_MAGENTA, self::ENCRE_JAUNE][$i % 3], 1, 28000, 0]], 0, 0, null, null, null, null];
        }

        // Yinghe Distribution : fret aérien depuis la Chine, promis en 18 jours, souvent plus.
        foreach ([[330, 18], [270, 24], [210, 18], [150, 21], [95, 17], [45, 22]] as [$jours, $delai]) {
            $commandes[] = [self::YINGHE, $jours, $delai, [[self::FILM_DTF, 3, 165000, 0], [self::POUDRE_DTF, 10, 9500, 0]], 0, 0, 'Fret aérien Canton → Bamako', 'au kilo, 1 colis', 'Dédouanement aéroport', 'transitaire Sénou'];
        }
        // … et une commande en route, passée il y a 10 jours.
        $commandes[] = [self::YINGHE, 10, null, [[self::FILM_DTF, 2, 168000, 0], [self::POUDRE_DTF, 10, 9500, 0]], 0, 0, 'Fret aérien Canton → Bamako', 'au kilo, 1 colis', 'Dédouanement aéroport', 'transitaire Sénou'];

        // Kanté Bois & Alu : structures de roll-up, retirées en 3 jours.
        foreach ([[300, 3], [240, 3], [180, 4], [120, 3], [60, 3], [12, 3]] as [$jours, $delai]) {
            $commandes[] = [self::KANTE, $jours, $delai, [[self::ROLL_UP, 20, 16000, 0]], 0, 0, null, null, null, null];
        }

        return $commandes;
    }

    public static function charger(Connection $connexion): void
    {
        $aujourdhui = new \DateTimeImmutable('today');

        self::fournisseurs($connexion);
        self::articles($connexion);

        // Les bons fictifs de demo.sql pour ces fournisseurs laissent la place à l'historique ci-dessous.
        $ids = implode(',', array_keys(self::FOURNISSEURS));
        $connexion->executeStatement('DELETE d FROM achat_detail d JOIN achats a ON a.id = d.achat_id WHERE a.id >= 900000 AND a.fournisseur_id IN ('.$ids.')');
        $connexion->executeStatement('DELETE FROM achats WHERE id >= 900000 AND fournisseur_id IN ('.$ids.')');
        // Les autres bons fictifs : commandés à leur date, promis en 5 jours.
        $connexion->executeStatement('UPDATE achats SET date_commande = date_demande, date_promise = DATE(date_demande + INTERVAL 5 DAY) WHERE id >= 900000 AND date_commande IS NULL');

        self::commandesFictives($connexion, $aujourdhui);
        self::stock($connexion, $aujourdhui);
        self::dettes($connexion, $aujourdhui);
    }

    private static function fournisseurs(Connection $connexion): void
    {
        foreach (self::FOURNISSEURS as $id => [$nom, $telephone, $contact, $tel, $ville, $pays, $fournit, $conditions, $acompte, $transport, $delai, $couleur]) {
            $colonnes = [
                'nom' => $nom, 'telephone' => $telephone, 'adresse' => $ville.', '.$pays, 'actif' => 1,
                'contact_nom' => $contact, 'contact_telephone' => $tel, 'ville' => $ville, 'pays' => $pays, 'fournit' => $fournit,
                'conditions_paiement' => $conditions, 'acompte_pourcentage' => $acompte, 'transport' => $transport,
                'delai_promis_jours' => $delai, 'couleur' => $couleur,
            ];
            if (false === $connexion->fetchOne('SELECT id FROM fournisseurs WHERE id = ?', [$id])) {
                $connexion->insert('fournisseurs', ['id' => $id] + $colonnes);
            } else {
                $connexion->update('fournisseurs', $colonnes, ['id' => $id]);
            }
        }
    }

    /**
     * Unités d'achat réalistes (un rouleau de vinyle de 1,52 × 50 m fait
     * 76 m²), prix par unité d'achat et fournisseur habituel.
     */
    private static function articles(Connection $connexion): void
    {
        $prochainLien = 905001;

        foreach (self::ARTICLES as $article => [$unite, $facteur, $longueur, $laize, $prix, $minimum, $maximum, $fournisseur, $delai]) {
            $connexion->update('articles', [
                'unite_achat' => $unite, 'quantite_par_unite_achat' => $facteur, 'longueur_unite_achat' => $longueur, 'laize' => $laize,
                'prix_achat' => $prix, 'stock_min' => $minimum, 'stock_max' => $maximum, 'fournisseur_id' => $fournisseur,
            ], ['id' => $article]);

            // Le fournisseur de la maquette devient le principal, au prix d'une unité d'achat.
            $connexion->executeStatement('UPDATE article_fournisseur SET principal = 0 WHERE article_id = ?', [$article]);
            $lien = $connexion->fetchOne('SELECT id FROM article_fournisseur WHERE article_id = ? AND fournisseur_id = ?', [$article, $fournisseur]);
            if (false === $lien) {
                $lien = $connexion->fetchOne('SELECT id FROM article_fournisseur WHERE article_id = ? AND principal = 0 AND fournisseur_id IN (?, ?) ORDER BY id LIMIT 1', [$article, self::ENCRES, self::DAKAR]);
            }
            $valeurs = ['fournisseur_id' => $fournisseur, 'prix_achat' => $prix, 'delai_jours' => $delai, 'principal' => 1];
            if (false === $lien) {
                $connexion->insert('article_fournisseur', ['id' => $prochainLien++, 'article_id' => $article, 'reference_fournisseur' => null] + $valeurs);
            } else {
                $connexion->update('article_fournisseur', $valeurs, ['id' => $lien]);
            }
        }
    }

    private static function commandesFictives(Connection $connexion, \DateTimeImmutable $aujourdhui): void
    {
        $commandes = self::commandes();
        // Numérotées dans l'ordre des dates, CA-AAAA-NNNN par année.
        usort($commandes, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $rangs = [];
        $id = 905000;
        $ligneId = 905000;
        $facteurs = [];
        foreach (self::ARTICLES as $article => $a) {
            $facteurs[$article] = $a[1];
        }

        foreach ($commandes as [$fournisseur, $jours, $delai, $lignes, $transport, $transit, $transportLibelle, $transportBase, $transitLibelle, $transitBase]) {
            ++$id;
            $commande = $aujourdhui->setTime(9, 30)->modify(sprintf('-%d days', $jours));
            $annee = (int) $commande->format('Y');
            $rangs[$annee] = ($rangs[$annee] ?? 0) + 1;
            $promis = self::FOURNISSEURS[$fournisseur][10];
            $recue = $delai !== null ? $commande->setTime(15, 0)->modify(sprintf('+%d days', $delai)) : null;

            // Frais rendus Bamako : Dakar au m², Yinghe au kilo (≈ la marchandise).
            $pourRepartir = [];
            $marchandise = 0;
            foreach ($lignes as [$article, $quantite, $prix]) {
                $pourRepartir[] = ['quantite' => $quantite * $facteurs[$article], 'unite' => self::ARTICLES[$article][0] === 'litre' ? 'L' : ($facteurs[$article] > 1 && $article !== self::FILM_DTF ? 'm²' : self::ARTICLES[$article][0]), 'montant' => $quantite * $prix];
                $marchandise += $quantite * $prix;
            }
            $m2 = array_sum(array_column($pourRepartir, 'quantite'));
            if ($fournisseur === self::DAKAR) {
                $transport = (int) (round($m2 * 260 / 100) * 100);
                $transit = (int) (round($m2 * 90 / 100) * 100);
            } elseif ($fournisseur === self::YINGHE) {
                $transport = (int) (round($marchandise * 0.12 / 1000) * 1000);
                $transit = (int) (round($marchandise * 0.05 / 1000) * 1000);
            }
            $partsTransport = CalculsAchat::repartirFrais($pourRepartir, $transport);
            $partsTransit = CalculsAchat::repartirFrais($pourRepartir, $transit);

            $connexion->insert('achats', [
                'id' => $id,
                'numero' => sprintf('CA-%d-%04d', $annee, $rangs[$annee]),
                'statut' => $recue !== null ? 'recu' : 'en_route',
                'date_demande' => $commande->format('Y-m-d H:i:s'),
                'date_commande' => $commande->format('Y-m-d H:i:s'),
                'date_promise' => $commande->modify(sprintf('+%d days', $promis))->format('Y-m-d'),
                'date_reception' => $recue?->format('Y-m-d H:i:s'),
                'observation' => null,
                'total_ht' => $marchandise, 'tva' => 0, 'total_ttc' => $marchandise,
                'frais_transport' => $transport, 'frais_transport_libelle' => $transportLibelle, 'frais_transport_base' => $transportBase,
                'frais_transit' => $transit, 'frais_transit_libelle' => $transitLibelle, 'frais_transit_base' => $transitBase,
                'acompte_pourcentage' => self::FOURNISSEURS[$fournisseur][8],
                'fournisseur_id' => $fournisseur,
            ]);

            foreach ($lignes as $i => [$article, $quantite, $prix, $abimees]) {
                $quantiteStock = $pourRepartir[$i]['quantite'];
                $cout = CalculsAchat::coutRevientUnitaire($quantite * $prix, $partsTransport[$i] + $partsTransit[$i], $quantiteStock);
                $connexion->insert('achat_detail', [
                    'id' => ++$ligneId, 'achat_id' => $id, 'article_id' => $article,
                    'quantite' => $quantite, 'prix_unitaire' => $prix, 'total_ht' => $quantite * $prix,
                    'quantite_stock' => $quantiteStock, 'cout_revient_unitaire' => $cout, 'quantite_abimee' => $abimees,
                ]);
                // Reçue : l'entrée en stock correspondante, au coût de revient.
                if ($recue !== null) {
                    $connexion->insert('stock_entrees', ['id' => $ligneId, 'article_id' => $article, 'quantites' => $quantiteStock - $abimees * $facteurs[$article], 'prix' => (int) $cout, 'date' => $recue->format('Y-m-d H:i:s')]);
                }
            }
        }
    }

    /**
     * Sorties de production des 12 dernières semaines au rythme voulu,
     * puis un ajustement d'inventaire daté d'avant pour arriver au stock voulu.
     */
    private static function stock(Connection $connexion, \DateTimeImmutable $aujourdhui): void
    {
        $id = 905500;

        foreach (self::STOCK as $article => [$cible, $parSemaine]) {
            // Les sorties récentes déjà chargées comptent dans la consommation.
            $depuis = $aujourdhui->modify(sprintf('-%d days', CalculsAchat::JOURS_CONSOMMATION))->format('Y-m-d H:i:s');
            $deja = (float) $connexion->fetchOne('SELECT COALESCE(SUM(quantite), 0) FROM stock_sorties WHERE article_id = ? AND date >= ?', [$article, $depuis]);
            $reste = max(0.0, $parSemaine * 12 - $deja);

            for ($semaine = 12; $semaine >= 1 && $reste > 0; --$semaine) {
                $quantite = round(min($reste, $reste / $semaine), 3);
                $reste -= $quantite;
                $connexion->insert('stock_sorties', [
                    'id' => ++$id, 'article_id' => $article, 'quantite' => $quantite, 'origine' => 'production',
                    'reference_origine' => 'Démo achats · consommation de la semaine',
                    'date' => $aujourdhui->setTime(11, 0)->modify(sprintf('-%d days', $semaine * 7 - 3))->format('Y-m-d H:i:s'),
                ]);
            }

            $physique = (float) $connexion->fetchOne('SELECT (SELECT COALESCE(SUM(quantites), 0) FROM stock_entrees WHERE article_id = ?) - (SELECT COALESCE(SUM(quantite), 0) FROM stock_sorties WHERE article_id = ?)', [$article, $article]);
            $reserve = (float) $connexion->fetchOne('SELECT COALESCE(SUM(quantite), 0) FROM stock_reservation WHERE article_id = ? AND statut = ?', [$article, 'active']);
            $ecart = round($physique - $reserve - $cible, 3);
            $date = $aujourdhui->setTime(18, 0)->modify(sprintf('-%d days', CalculsAchat::JOURS_CONSOMMATION + 6))->format('Y-m-d H:i:s');

            if ($ecart > 0) {
                $connexion->insert('stock_sorties', ['id' => ++$id, 'article_id' => $article, 'quantite' => $ecart, 'origine' => 'ajustement', 'reference_origine' => 'Ajustement · Comptage (démo achats)', 'date' => $date]);
            } elseif ($ecart < 0) {
                $connexion->insert('stock_entrees', ['id' => ++$id, 'article_id' => $article, 'quantites' => -$ecart, 'prix' => 0, 'date' => $date]);
            }
        }
    }

    /**
     * Deux factures fournisseurs à payer (écran Finances › Fournisseurs).
     */
    private static function dettes(Connection $connexion, \DateTimeImmutable $aujourdhui): void
    {
        foreach ([[905001, self::SAHEL, 'Bâches 510 g · 2 rouleaux', 285000, 6, 13], [905002, self::ENCRES, 'Encres solvant · octobre', 110000, 3, 20]] as [$id, $fournisseur, $objet, $montant, $il_y_a, $echeance]) {
            $connexion->insert('depense', [
                'id' => $id, 'objet' => $objet, 'categorie' => 'matieres', 'montant' => $montant,
                'date_depense' => $aujourdhui->modify(sprintf('-%d days', $il_y_a))->format('Y-m-d'),
                'echeance' => $aujourdhui->modify(sprintf('+%d days', $echeance))->format('Y-m-d'),
                'justificatif' => '', 'statut' => 'a_payer', 'cree_le' => $aujourdhui->modify(sprintf('-%d days', $il_y_a))->format('Y-m-d 10:00:00'),
                'fournisseur_id' => $fournisseur, 'note' => 'Démo : facture fournisseur à payer.',
            ]);
        }
    }
}
