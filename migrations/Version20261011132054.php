<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011132054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paramètres en tuiles : table reglage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reglage (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(60) NOT NULL, valeurs JSON NOT NULL, proposition JSON DEFAULT NULL, propose_le DATETIME DEFAULT NULL, modifie_le DATETIME DEFAULT NULL, propose_par_id INT DEFAULT NULL, modifie_par_id INT DEFAULT NULL, INDEX IDX_BA31AAD3168A12A2 (propose_par_id), INDEX IDX_BA31AAD3553B2554 (modifie_par_id), UNIQUE INDEX uniq_reglage_cle (cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reglage ADD CONSTRAINT FK_BA31AAD3168A12A2 FOREIGN KEY (propose_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reglage ADD CONSTRAINT FK_BA31AAD3553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reglage DROP FOREIGN KEY FK_BA31AAD3168A12A2');
        $this->addSql('ALTER TABLE reglage DROP FOREIGN KEY FK_BA31AAD3553B2554');
        $this->addSql('DROP TABLE reglage');
    }
}
