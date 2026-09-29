<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Cree les tables du module Communications : modele_message, "
            . "regle_rappel_paiement, rappel_paiement_envoye (campagnes "
            . "SMS/email/WhatsApp et rappels de paiement automatiques).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE modele_message (
                id INT AUTO_INCREMENT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                canal VARCHAR(20) NOT NULL,
                sujet VARCHAR(255) DEFAULT NULL,
                contenu LONGTEXT NOT NULL,
                actif TINYINT(1) NOT NULL,
                date_creation DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'CREATE TABLE regle_rappel_paiement (
                id INT AUTO_INCREMENT NOT NULL,
                modele_message_id INT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                delai_jours INT NOT NULL,
                actif TINYINT(1) NOT NULL,
                date_creation DATETIME NOT NULL,
                INDEX idx_regle_rappel_paiement_modele (modele_message_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE regle_rappel_paiement ADD CONSTRAINT FK_regle_rappel_paiement_modele
             FOREIGN KEY (modele_message_id) REFERENCES modele_message (id) ON DELETE RESTRICT'
        );

        $this->addSql(
            'CREATE TABLE rappel_paiement_envoye (
                id INT AUTO_INCREMENT NOT NULL,
                commande_id INT NOT NULL,
                regle_id INT NOT NULL,
                canal VARCHAR(20) NOT NULL,
                date_envoi DATETIME NOT NULL,
                UNIQUE INDEX uniq_commande_regle (commande_id, regle_id),
                INDEX idx_rappel_paiement_envoye_commande (commande_id),
                INDEX idx_rappel_paiement_envoye_regle (regle_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );

        $this->addSql(
            'ALTER TABLE rappel_paiement_envoye ADD CONSTRAINT FK_rappel_paiement_envoye_commande
             FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE'
        );

        $this->addSql(
            'ALTER TABLE rappel_paiement_envoye ADD CONSTRAINT FK_rappel_paiement_envoye_regle
             FOREIGN KEY (regle_id) REFERENCES regle_rappel_paiement (id) ON DELETE CASCADE'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rappel_paiement_envoye');
        $this->addSql('DROP TABLE regle_rappel_paiement');
        $this->addSql('DROP TABLE modele_message');
    }
}
