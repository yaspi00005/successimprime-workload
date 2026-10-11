<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011145701 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conception PAO : suivi des travaux du graphiste (chronomètre, frise, points du barème)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conception (id INT AUTO_INCREMENT NOT NULL, difficulte VARCHAR(10) NOT NULL, echeance_bat DATETIME DEFAULT NULL, temps_prevu INT NOT NULL, demarre_le DATETIME DEFAULT NULL, secondes INT NOT NULL, versions INT NOT NULL, erreurs INT NOT NULL, changements_client INT NOT NULL, premier_bat_le DATETIME DEFAULT NULL, dernier_bat_le DATETIME DEFAULT NULL, valide_le DATETIME DEFAULT NULL, points INT DEFAULT NULL, points_possibles INT DEFAULT NULL, faits JSON NOT NULL, evenements JSON NOT NULL, cree_le DATETIME NOT NULL, controle_id INT NOT NULL, graphiste_id INT DEFAULT NULL, INDEX IDX_6F60FFF446EB940A (graphiste_id), INDEX idx_conception_valide_le (valide_le), UNIQUE INDEX uniq_conception_controle (controle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conception ADD CONSTRAINT FK_6F60FFF4758926A6 FOREIGN KEY (controle_id) REFERENCES controle_pre_presse (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conception ADD CONSTRAINT FK_6F60FFF446EB940A FOREIGN KEY (graphiste_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conception DROP FOREIGN KEY FK_6F60FFF4758926A6');
        $this->addSql('ALTER TABLE conception DROP FOREIGN KEY FK_6F60FFF446EB940A');
        $this->addSql('DROP TABLE conception');
    }
}
