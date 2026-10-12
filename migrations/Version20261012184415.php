<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012184415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronisation local et en ligne : file d envoi, envois recus, echanges, etat (J4, ecran 39).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE synchro_echange (id INT AUTO_INCREMENT NOT NULL, le DATETIME NOT NULL, sens VARCHAR(12) NOT NULL, type VARCHAR(30) DEFAULT NULL, libelle VARCHAR(255) NOT NULL, statut VARCHAR(10) NOT NULL, message LONGTEXT DEFAULT NULL, resolu TINYINT DEFAULT 0 NOT NULL, INDEX idx_synchro_echange_le (le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE synchro_etat (cle VARCHAR(40) NOT NULL, valeur VARCHAR(1000) DEFAULT NULL, PRIMARY KEY (cle)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE synchro_journal (id INT AUTO_INCREMENT NOT NULL, evenement VARCHAR(36) NOT NULL, type VARCHAR(30) NOT NULL, public_id VARCHAR(36) NOT NULL, operation VARCHAR(10) NOT NULL, cree_le DATETIME NOT NULL, envoye_le DATETIME DEFAULT NULL, tentatives INT DEFAULT 0 NOT NULL, erreur LONGTEXT DEFAULT NULL, ignore_manuel TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_64AE22D5B26681E (evenement), INDEX idx_synchro_journal_attente (envoye_le, id), INDEX idx_synchro_journal_donnee (type, public_id, envoye_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE synchro_recu (evenement VARCHAR(36) NOT NULL, recu_le DATETIME NOT NULL, PRIMARY KEY (evenement)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE synchro_echange');
        $this->addSql('DROP TABLE synchro_etat');
        $this->addSql('DROP TABLE synchro_journal');
        $this->addSql('DROP TABLE synchro_recu');
    }
}
