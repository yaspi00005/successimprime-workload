<?php

namespace App\Service\Catalogue;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Démonstration des écrans Produits (app:demo, docs/produits.md) : délai,
 * machine et machine de secours de chaque produit fictif, un produit
 * masqué en ligne avec son motif, des quantités de matière réalistes pour
 * les petits formats (une feuille SRA3 donne 21 cartes ou 8 flyers A5),
 * et cinq prix négociés (dont un en attente de l'accord de la direction,
 * un échu). Ni Salon Espoir (DEMO-0002) ni Amadou Sow, que la recette de
 * l'espace client chiffre, n'ont de prix négocié.
 *
 * Écrit par la connexion de app:demo, dans sa transaction.
 */
final class ProduitsDemo
{
    public const PURGE = [
        'DELETE FROM prix_special WHERE id >= 900000',
    ];

    /** Code produit => [délai en heures, machine, machine de secours]. */
    private const PRODUCTION = [
        'D-BACHE' => [48, 'Solvant 3,20 m', 'Solvant 1,80 m'],
        'D-VINYLE' => [48, 'Solvant 1,80 m', 'Solvant 3,20 m'],
        'D-MICRO' => [48, 'Solvant 1,80 m', null],
        'D-AKYLUX' => [48, 'Solvant 3,20 m', null],
        'D-ROLLUP' => [24, 'Solvant 1,80 m', 'Solvant 3,20 m'],
        'D-KAKEMONO' => [24, 'Solvant 1,80 m', null],
        'D-FLYER' => [48, 'Laser SRA3', 'Copieur Canon C3226i'],
        'D-CARTE' => [24, 'Laser SRA3', 'Copieur Canon C3226i'],
        'D-AFFICHE' => [24, 'Laser SRA3', null],
        'D-BROCHURE' => [72, 'Laser SRA3', 'Copieur Canon C3226i'],
        'D-TSHIRT' => [48, 'DTF XP600', null],
        'D-POLO' => [48, 'Sublimation SC-F500', null],
        'D-CASQUETTE' => [48, 'DTF XP600', null],
    ];

    /** Feuilles SRA3 (ou m² de bâche pour le kakémono) par pièce vendue. */
    private const FEUILLES = ['D-CARTE' => '0.048', 'D-FLYER' => '0.125', 'D-BROCHURE' => '2.000', 'D-KAKEMONO' => '1.500'];

    /** Code client, code produit, prix, fin (jours à partir d'aujourd'hui, null : sans fin), statut, note. */
    private const PRIX = [
        ['DEMO-0020', 'D-BACHE', 2800, 74, 'actif', 'Démo : enseignes de ses trois hôtels'],
        ['DEMO-0020', 'D-CARTE', 38, 74, 'actif', 'Démo : cartes de tout le personnel'],
        ['DEMO-0006', 'D-TSHIRT', 4500, 43, 'actif', 'Démo : tenues des mécaniciens'],
        ['DEMO-0001', 'D-AKYLUX', 13500, null, 'actif', 'Démo : campagne de sensibilisation'],
        ['DEMO-0012', 'D-VINYLE', 5600, 60, 'attente', 'Démo : signalétique de la clinique'],
        ['DEMO-0015', 'D-FLYER', 65, -5, 'actif', 'Démo : promotion du ramadan, terminée'],
    ];

    public static function charger(Connection $c, \DateTimeImmutable $aujourdhui = new \DateTimeImmutable('today')): void
    {
        $machines = array_column($c->fetchAllAssociative('SELECT id, nom FROM machines WHERE id >= 900000'), 'id', 'nom');
        foreach (self::PRODUCTION as $code => [$delai, $machine, $secours]) {
            $c->executeStatement(
                'UPDATE produits SET delai_heures = ?, machine_id = ?, machine_secours_id = ?, visible_en_ligne = 1, motif_hors_ligne = NULL WHERE code = ? AND id >= 900000',
                [$delai, $machines[$machine] ?? null, null === $secours ? null : ($machines[$secours] ?? null), $code],
            );
        }
        // Masqué en ligne comme le fait l'atelier pour la presse en panne (incident 990001) : la résolution le remet en ligne.
        $c->executeStatement("UPDATE produits SET visible_en_ligne = 0, motif_hors_ligne = 'Machine en réparation' WHERE code = 'D-POLO' AND id >= 900000");
        foreach (self::FEUILLES as $code => $coefficient) {
            $c->executeStatement('UPDATE produit_article_stock l JOIN produits p ON p.id = l.produit_id SET l.coefficient = ? WHERE p.code = ? AND p.id >= 900000', [$coefficient, $code]);
        }

        $direction = $c->fetchOne("SELECT id FROM `user` WHERE username = 'demo.kadiatou'") ?: null;
        $commerciale = $c->fetchOne("SELECT id FROM `user` WHERE username = 'demo.aminata'") ?: null;
        $id = 900001;
        foreach (self::PRIX as [$client, $produit, $prix, $fin, $statut, $note]) {
            $clientId = $c->fetchOne('SELECT id FROM clients WHERE code = ?', [$client]);
            $configId = $c->fetchOne('SELECT pc.id FROM produit_configuration pc JOIN produits p ON p.id = pc.produit_id WHERE p.code = ? ORDER BY pc.ordre, pc.id LIMIT 1', [$produit]);
            if (false === $clientId || false === $configId) {
                continue;
            }
            $cree = $aujourdhui->modify('-'.(20 + $id % 7 * 5).' days');
            $c->executeStatement(
                'INSERT INTO prix_special (id, public_id, client_id, configuration_id, prix, fin_le, statut, note, cree_par_id, cree_le, decide_par_id, decide_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $id, Uuid::v7()->toBinary(), $clientId, $configId, $prix,
                    null === $fin ? null : $aujourdhui->modify(\sprintf('%+d days', $fin))->format('Y-m-d'),
                    $statut, $note, 'attente' === $statut ? $commerciale : $direction, $cree->format('Y-m-d H:i:s'), null, null,
                ],
            );
            ++$id;
        }
    }
}
