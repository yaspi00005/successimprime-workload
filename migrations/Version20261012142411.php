<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012142411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning étape 3 : compte et catégorie de dépense des échéances, dépense préparée des tâches.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE echeance ADD categorie_depense VARCHAR(50) DEFAULT NULL, ADD compte_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893BF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_40D9893BF2C56620 ON echeance (compte_id)');
        $this->addSql('ALTER TABLE tache ADD mouvement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075ECD1C222 FOREIGN KEY (mouvement_id) REFERENCES mouvement_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_93872075ECD1C222 ON tache (mouvement_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893BF2C56620');
        $this->addSql('DROP INDEX IDX_40D9893BF2C56620 ON echeance');
        $this->addSql('ALTER TABLE echeance DROP categorie_depense, DROP compte_id');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075ECD1C222');
        $this->addSql('DROP INDEX IDX_93872075ECD1C222 ON tache');
        $this->addSql('ALTER TABLE tache DROP mouvement_id');
    }
}
