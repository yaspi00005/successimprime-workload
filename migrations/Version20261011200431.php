<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011200431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning : créneau, checklist de fermeture, photo et contrôle des tâches d\'entretien (W05)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echeance ADD heure_fin VARCHAR(5) DEFAULT NULL, ADD fermeture TINYINT NOT NULL');
        $this->addSql('ALTER TABLE tache ADD heure_fin VARCHAR(5) DEFAULT NULL, ADD fermeture TINYINT NOT NULL, ADD commence_le DATETIME DEFAULT NULL, ADD photo VARCHAR(60) DEFAULT NULL, ADD controle VARCHAR(20) DEFAULT NULL, ADD controle_le DATETIME DEFAULT NULL, ADD controle_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075BA5AD30 FOREIGN KEY (controle_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_93872075BA5AD30 ON tache (controle_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echeance DROP heure_fin, DROP fermeture');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075BA5AD30');
        $this->addSql('DROP INDEX IDX_93872075BA5AD30 ON tache');
        $this->addSql('ALTER TABLE tache DROP heure_fin, DROP fermeture, DROP commence_le, DROP photo, DROP controle, DROP controle_le, DROP controle_par_id');
    }
}
