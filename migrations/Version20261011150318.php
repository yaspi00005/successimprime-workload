<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011150318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'File machine : journal des gâches, incidents, entretien des têtes et réimpressions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE journal_machine (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(15) NOT NULL, quantite DOUBLE PRECISION DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, le DATETIME NOT NULL, machine_id INT NOT NULL, operateur_id INT NOT NULL, ordre_id INT DEFAULT NULL, INDEX IDX_C5BE9161F6B75B26 (machine_id), INDEX IDX_C5BE91613F192FC (operateur_id), INDEX IDX_C5BE91619291498C (ordre_id), INDEX idx_journal_machine_le (le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE journal_machine ADD CONSTRAINT FK_C5BE9161F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_machine ADD CONSTRAINT FK_C5BE91613F192FC FOREIGN KEY (operateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE journal_machine ADD CONSTRAINT FK_C5BE91619291498C FOREIGN KEY (ordre_id) REFERENCES ordre_production (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE journal_machine DROP FOREIGN KEY FK_C5BE9161F6B75B26');
        $this->addSql('ALTER TABLE journal_machine DROP FOREIGN KEY FK_C5BE91613F192FC');
        $this->addSql('ALTER TABLE journal_machine DROP FOREIGN KEY FK_C5BE91619291498C');
        $this->addSql('DROP TABLE journal_machine');
    }
}
