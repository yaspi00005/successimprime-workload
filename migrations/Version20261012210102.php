<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012210102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronisation : fichiers de BAT envoyés au site, gestes du client en ligne (BAT).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE synchro_fichier (public_id VARCHAR(36) NOT NULL, signature VARCHAR(40) NOT NULL, empreinte VARCHAR(64) NOT NULL, envoye_le DATETIME NOT NULL, PRIMARY KEY (public_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE synchro_journal ADD donnees JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE synchro_fichier');
        $this->addSql('ALTER TABLE synchro_journal DROP donnees');
    }
}
