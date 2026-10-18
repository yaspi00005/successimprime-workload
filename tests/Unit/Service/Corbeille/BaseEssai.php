<?php

namespace App\Tests\Unit\Service\Corbeille;

use App\Service\Corbeille\Instantane;
use App\Service\Corbeille\SchemaCorbeille;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Petite base SQLite en mémoire pour les tests de la corbeille, avec les
 * vrais noms de tables (ce qui compte pour TypesCorbeille) et les mêmes
 * règles de clés étrangères qu'en production :
 *
 *   clients ─┬─ compte_client      (CASCADE, mot de passe : sensible)
 *            ├─ adresse_livraison  (CASCADE)
 *            ├─ promotion_client   (CASCADE, table de liaison sans entité) ─ promotion
 *            ├─ notifications      (CASCADE, table technique exclue)
 *            ├─ action_caisse      (SET NULL : reste en place, lien à rebrancher)
 *            └─ devis              (refus) ─ devis_details (CASCADE, rattaché)
 *   commandes_details ─ commande_detail_fichier (CASCADE)
 *   tache ─ rappel_whatsapp (CASCADE, refaits par le planning)
 *   site_page ─ site_image (SET NULL : la page utilise l'image)
 */
final class BaseEssai
{
    /** Tables qui portent une entité (promotion_client n'en a pas). */
    public const ENTITES = ['clients', 'compte_client', 'adresse_livraison', 'promotion', 'notifications', 'action_caisse', 'devis', 'devis_details', 'commandes_details', 'commande_detail_fichier', 'tache', 'rappel_whatsapp', 'site_image', 'site_page'];

    public static function connexion(): Connection
    {
        $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $c->executeStatement('PRAGMA foreign_keys = ON');
        foreach ([
            'CREATE TABLE clients (id INTEGER PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, nom VARCHAR(60), prenom VARCHAR(60), raison_sociale VARCHAR(120), public_id BLOB)',
            'CREATE TABLE compte_client (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, telephone VARCHAR(20) NOT NULL, nom VARCHAR(60), mot_de_passe VARCHAR(255))',
            'CREATE TABLE adresse_livraison (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, libelle VARCHAR(60) NOT NULL, adresse VARCHAR(255) NOT NULL)',
            'CREATE TABLE promotion (id INTEGER PRIMARY KEY, nom VARCHAR(60) NOT NULL)',
            'CREATE TABLE promotion_client (promotion_id INTEGER NOT NULL REFERENCES promotion(id) ON DELETE CASCADE, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE, PRIMARY KEY (promotion_id, client_id))',
            'CREATE TABLE notifications (id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE, texte VARCHAR(255))',
            'CREATE TABLE action_caisse (id INTEGER PRIMARY KEY, client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL, note VARCHAR(255))',
            'CREATE TABLE devis (id INTEGER PRIMARY KEY, numero VARCHAR(30) NOT NULL, clients_id INTEGER REFERENCES clients(id))',
            'CREATE TABLE devis_details (id INTEGER PRIMARY KEY, devis_id INTEGER NOT NULL REFERENCES devis(id) ON DELETE CASCADE, designation VARCHAR(255))',
            'CREATE TABLE commandes_details (id INTEGER PRIMARY KEY, designation VARCHAR(255))',
            'CREATE TABLE commande_detail_fichier (id INTEGER PRIMARY KEY, commande_detail_id INTEGER REFERENCES commandes_details(id) ON DELETE CASCADE, client_id INTEGER, nom_original VARCHAR(255), nom_stockage VARCHAR(255), actif BOOLEAN NOT NULL DEFAULT 1)',
            'CREATE TABLE tache (id INTEGER PRIMARY KEY, titre VARCHAR(120))',
            'CREATE TABLE rappel_whatsapp (id INTEGER PRIMARY KEY, tache_id INTEGER NOT NULL REFERENCES tache(id) ON DELETE CASCADE, cle VARCHAR(10))',
            'CREATE TABLE site_image (id INTEGER PRIMARY KEY, public_id BLOB, texte_alternatif VARCHAR(255))',
            'CREATE TABLE site_page (id INTEGER PRIMARY KEY, titre VARCHAR(120), image_id INTEGER REFERENCES site_image(id) ON DELETE SET NULL)',
        ] as $sql) {
            $c->executeStatement($sql);
        }

        return $c;
    }

    /** Un client et tout ce qui dépend de lui. */
    public static function remplir(Connection $c): void
    {
        $c->insert('clients', ['id' => 7, 'code' => 'CLI-7', 'nom' => 'Traoré', 'prenom' => 'Awa', 'public_id' => hex2bin('0192aabbccddeeff00112233445566ff')]);
        $c->insert('compte_client', ['id' => 70, 'client_id' => 7, 'telephone' => '+22376000007', 'nom' => 'Awa', 'mot_de_passe' => '$2y$13$empreinteDuMotDePasse']);
        $c->insert('adresse_livraison', ['id' => 71, 'client_id' => 7, 'libelle' => 'Boutique', 'adresse' => 'Hamdallaye ACI 2000']);
        $c->insert('adresse_livraison', ['id' => 72, 'client_id' => 7, 'libelle' => 'Maison', 'adresse' => 'Badalabougou']);
        $c->insert('promotion', ['id' => 5, 'nom' => 'Rentrée']);
        $c->insert('promotion_client', ['promotion_id' => 5, 'client_id' => 7]);
        $c->insert('notifications', ['id' => 90, 'client_id' => 7, 'texte' => 'Votre commande est prête']);
        $c->insert('action_caisse', ['id' => 80, 'client_id' => 7, 'note' => 'Appel de relance']);
    }

    public static function instantane(Connection $c): Instantane
    {
        return new Instantane($c, new SchemaCorbeille($c), array_fill_keys(self::ENTITES, true));
    }
}
