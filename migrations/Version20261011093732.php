<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Historique des prix (maquette 37).
 */
final class Version20261011093732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Historique des prix de la grille tarifaire (maquette 37)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prix_historique (id INT AUTO_INCREMENT NOT NULL, cible VARCHAR(20) NOT NULL, cible_id INT NOT NULL, libelle VARCHAR(255) NOT NULL, champ VARCHAR(30) NOT NULL, ancien VARCHAR(30) DEFAULT NULL, nouveau VARCHAR(30) DEFAULT NULL, change_le DATETIME NOT NULL, par_id INT DEFAULT NULL, INDEX IDX_94DD1D67468486AA (par_id), INDEX idx_prix_historique_le (change_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prix_historique ADD CONSTRAINT FK_94DD1D67468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prix_historique DROP FOREIGN KEY FK_94DD1D67468486AA');
        $this->addSql('DROP TABLE prix_historique');
    }
}
