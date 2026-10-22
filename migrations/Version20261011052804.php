<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Créances : historique des relances faites à la main.
 */
final class Version20261011052804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relances manuelles des créances (maquette 30).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE relance_creance (id INT AUTO_INCREMENT NOT NULL, canal VARCHAR(20) NOT NULL, envoyee_le DATETIME NOT NULL, commande_id INT NOT NULL, par_id INT DEFAULT NULL, INDEX IDX_EBF434E082EA2E54 (commande_id), INDEX IDX_EBF434E0468486AA (par_id), INDEX idx_relance_creance_date (envoyee_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE relance_creance ADD CONSTRAINT FK_EBF434E082EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance_creance ADD CONSTRAINT FK_EBF434E0468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE relance_creance DROP FOREIGN KEY FK_EBF434E082EA2E54');
        $this->addSql('ALTER TABLE relance_creance DROP FOREIGN KEY FK_EBF434E0468486AA');
        $this->addSql('DROP TABLE relance_creance');
    }
}
