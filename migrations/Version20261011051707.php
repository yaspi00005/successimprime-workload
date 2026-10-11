<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caisse : clôture de fin de journée.
 */
final class Version20261011051707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clôtures de caisse (maquette 28) : compté face au théorique, écart noté.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cloture_caisse (id INT AUTO_INCREMENT NOT NULL, cloture_le DATETIME NOT NULL, montant_theorique INT NOT NULL, montant_compte INT NOT NULL, observation LONGTEXT DEFAULT NULL, compte_id INT NOT NULL, cloture_par_id INT DEFAULT NULL, INDEX IDX_21B102FDF2C56620 (compte_id), INDEX IDX_21B102FDD596D79F (cloture_par_id), INDEX idx_cloture_caisse_date (cloture_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cloture_caisse ADD CONSTRAINT FK_21B102FDF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_tresorerie (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cloture_caisse ADD CONSTRAINT FK_21B102FDD596D79F FOREIGN KEY (cloture_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cloture_caisse DROP FOREIGN KEY FK_21B102FDF2C56620');
        $this->addSql('ALTER TABLE cloture_caisse DROP FOREIGN KEY FK_21B102FDD596D79F');
        $this->addSql('DROP TABLE cloture_caisse');
    }
}
