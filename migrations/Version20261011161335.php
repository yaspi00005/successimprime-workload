<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011161335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paie : bulletins de paie mensuels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bulletin_paie (id INT AUTO_INCREMENT NOT NULL, mois DATE NOT NULL, salaire_base INT NOT NULL, lignes JSON NOT NULL, etat VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, valide_le DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, valide_par_id INT DEFAULT NULL, mouvement_id INT DEFAULT NULL, INDEX IDX_4F971F47FB88E14F (utilisateur_id), INDEX IDX_4F971F476AF12ED9 (valide_par_id), INDEX IDX_4F971F47ECD1C222 (mouvement_id), UNIQUE INDEX uniq_bulletin_mois (utilisateur_id, mois), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bulletin_paie ADD CONSTRAINT FK_4F971F47FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE bulletin_paie ADD CONSTRAINT FK_4F971F476AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE bulletin_paie ADD CONSTRAINT FK_4F971F47ECD1C222 FOREIGN KEY (mouvement_id) REFERENCES mouvement_tresorerie (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bulletin_paie DROP FOREIGN KEY FK_4F971F47FB88E14F');
        $this->addSql('ALTER TABLE bulletin_paie DROP FOREIGN KEY FK_4F971F476AF12ED9');
        $this->addSql('ALTER TABLE bulletin_paie DROP FOREIGN KEY FK_4F971F47ECD1C222');
        $this->addSql('DROP TABLE bulletin_paie');
    }
}
