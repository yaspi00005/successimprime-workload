<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011205345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes et comptes rendus sur la fiche client (K03)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE note_client (id INT AUTO_INCREMENT NOT NULL, le DATETIME NOT NULL, type VARCHAR(10) NOT NULL, texte LONGTEXT NOT NULL, rappel_le DATETIME DEFAULT NULL, client_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_DF96538119EB6921 (client_id), INDEX IDX_DF96538160BB6FE6 (auteur_id), INDEX idx_note_client_le (client_id, le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE note_client ADD CONSTRAINT FK_DF96538119EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_client ADD CONSTRAINT FK_DF96538160BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note_client DROP FOREIGN KEY FK_DF96538119EB6921');
        $this->addSql('ALTER TABLE note_client DROP FOREIGN KEY FK_DF96538160BB6FE6');
        $this->addSql('DROP TABLE note_client');
    }
}
