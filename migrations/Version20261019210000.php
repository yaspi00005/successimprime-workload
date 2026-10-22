<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Livraisons de l'espace client (maquettes DB08 à DB13) : code de
 * réception, photo de remise pour le client, livreur et sa position
 * pendant la tournée, repère et personne qui reçoit si absente, avis du
 * client ; programmation choisie en ligne sur la commande.
 */
final class Version20261019210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraisons : code de réception, photo de remise, position du livreur, avis du client, programmation en ligne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD code_reception VARCHAR(4) DEFAULT NULL, ADD code_verifie_le DATETIME DEFAULT NULL, ADD code_essais SMALLINT DEFAULT 0 NOT NULL, ADD photo_client LONGTEXT DEFAULT NULL, ADD photo_client_le DATETIME DEFAULT NULL, ADD livreur_nom VARCHAR(80) DEFAULT NULL, ADD livreur_telephone VARCHAR(20) DEFAULT NULL, ADD livreur_latitude DOUBLE PRECISION DEFAULT NULL, ADD livreur_longitude DOUBLE PRECISION DEFAULT NULL, ADD livreur_position_le DATETIME DEFAULT NULL, ADD repere VARCHAR(160) DEFAULT NULL, ADD absent_nom VARCHAR(120) DEFAULT NULL, ADD conforme_le DATETIME DEFAULT NULL, ADD note_livraison SMALLINT DEFAULT NULL, ADD note_tags JSON DEFAULT NULL, ADD note_le DATETIME DEFAULT NULL');
        // Un code pour chaque livraison pas encore remise.
        $this->addSql("UPDATE bon_livraison SET code_reception = LPAD(FLOOR(RAND() * 10000), 4, '0') WHERE statut IN ('brouillon', 'valide')");
        $this->addSql('ALTER TABLE commandes ADD programmation_livraison JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison DROP code_reception, DROP code_verifie_le, DROP code_essais, DROP photo_client, DROP photo_client_le, DROP livreur_nom, DROP livreur_telephone, DROP livreur_latitude, DROP livreur_longitude, DROP livreur_position_le, DROP repere, DROP absent_nom, DROP conforme_le, DROP note_livraison, DROP note_tags, DROP note_le');
        $this->addSql('ALTER TABLE commandes DROP programmation_livraison');
    }
}
