<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Cree les tables du module Campagnes : campagne, "
            . "campagne_destinataire (envoi en masse SMS/email/WhatsApp a "
            . "une liste de clients).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE campagne (
                id INT AUTO_INCREMENT NOT NULL,
                modele_message_id INT NOT NULL,
                cree_par_id INT DEFAULT NULL,
                nom VARCHAR(255) NOT NULL,
                statut VARCHAR(20) NOT NULL,
                nombre_total INT NOT NULL,
                date_creation DATETIME NOT NULL,
                INDEX idx_campagne_modele (modele_message_id),
                INDEX idx_campagne_cree_par (cree_par_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE campagne ADD CONSTRAINT FK_campagne_modele
             FOREIGN KEY (modele_message_id) REFERENCES modele_message (id) ON DELETE RESTRICT'
        );

        $this->addSql(
            'ALTER TABLE campagne ADD CONSTRAINT FK_campagne_cree_par
             FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL'
        );

        $this->addSql(
            'CREATE TABLE campagne_destinataire (
                id INT AUTO_INCREMENT NOT NULL,
                campagne_id INT NOT NULL,
                client_id INT DEFAULT NULL,
                statut VARCHAR(20) NOT NULL,
                erreur LONGTEXT DEFAULT NULL,
                date_envoi DATETIME DEFAULT NULL,
                INDEX idx_campagne_destinataire_statut (statut),
                INDEX idx_campagne_destinataire_campagne (campagne_id),
                INDEX idx_campagne_destinataire_client (client_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE campagne_destinataire ADD CONSTRAINT FK_campagne_destinataire_campagne
             FOREIGN KEY (campagne_id) REFERENCES campagne (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE campagne_destinataire ADD CONSTRAINT FK_campagne_destinataire_client
             FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE campagne_destinataire');
        $this->addSql('DROP TABLE campagne');
    }
}
