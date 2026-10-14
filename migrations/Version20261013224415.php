<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013224415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statistiques : objectifs du mois par indicateur, trace d\'envoi du rapport de la semaine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE objectif (id INT AUTO_INCREMENT NOT NULL, mois DATE NOT NULL, indicateur VARCHAR(30) NOT NULL, axe VARCHAR(20) NOT NULL, cible INT NOT NULL, valeur INT NOT NULL, modifie_le DATETIME NOT NULL, modifie_par_id INT DEFAULT NULL, INDEX IDX_E2F86851553B2554 (modifie_par_id), UNIQUE INDEX uniq_objectif_mois_indicateur_cible (mois, indicateur, axe, cible), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rapport_hebdomadaire (id INT AUTO_INCREMENT NOT NULL, semaine VARCHAR(8) NOT NULL, debut DATE NOT NULL, envoye_le DATETIME NOT NULL, destinataires INT NOT NULL, courriels INT NOT NULL, UNIQUE INDEX uniq_rapport_hebdomadaire_semaine (semaine), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE objectif ADD CONSTRAINT FK_E2F86851553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objectif DROP FOREIGN KEY FK_E2F86851553B2554');
        $this->addSql('DROP TABLE objectif');
        $this->addSql('DROP TABLE rapport_hebdomadaire');
    }
}
