<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Atelier de production (docs/atelier-production.md, maquettes AT01 à AT05) :
 * ce que l'atelier ajoute aux machines (opérateurs affectés, niveaux d'encre
 * saisis à la main, ordre de la file, pose des têtes), les pannes et
 * incidents, et l'entretien préventif. Aucune table existante n'est modifiée.
 */
final class Version20261018174810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atelier de production : tables atelier_machine, atelier_machine_operateur, incident_machine, entretien_machine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entretien_machine (id INT AUTO_INCREMENT NOT NULL, tache VARCHAR(120) NOT NULL, frequence VARCHAR(10) NOT NULL, intervalle INT NOT NULL, derniere_le DATETIME DEFAULT NULL, actif TINYINT NOT NULL, machine_id INT NOT NULL, derniere_par_id INT DEFAULT NULL, INDEX IDX_684FF6DDF6B75B26 (machine_id), INDEX IDX_684FF6DD74F628A8 (derniere_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE atelier_machine (id INT AUTO_INCREMENT NOT NULL, niveaux_encre JSON DEFAULT NULL, niveaux_encre_le DATETIME DEFAULT NULL, ordre_file JSON DEFAULT NULL, tetes_posees_le DATE DEFAULT NULL, machine_id INT NOT NULL, niveaux_encre_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_273C843CF6B75B26 (machine_id), INDEX IDX_273C843C758161D1 (niveaux_encre_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE atelier_machine_operateur (atelier_machine_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_68252A553BB0F82B (atelier_machine_id), INDEX IDX_68252A55A76ED395 (user_id), PRIMARY KEY (atelier_machine_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE incident_machine (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, detail LONGTEXT DEFAULT NULL, etat VARCHAR(20) NOT NULL, arret TINYINT NOT NULL, signale_le DATETIME NOT NULL, suite VARCHAR(255) DEFAULT NULL, devis INT DEFAULT NULL, resolu_le DATETIME DEFAULT NULL, note_resolution VARCHAR(255) DEFAULT NULL, machine_id INT NOT NULL, signale_par_id INT DEFAULT NULL, ordre_id INT DEFAULT NULL, resolu_par_id INT DEFAULT NULL, INDEX IDX_B2CFC559F6B75B26 (machine_id), INDEX IDX_B2CFC559AE190A20 (signale_par_id), INDEX IDX_B2CFC5599291498C (ordre_id), INDEX IDX_B2CFC5595E3DAC49 (resolu_par_id), INDEX idx_incident_machine_etat (etat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE entretien_machine ADD CONSTRAINT FK_684FF6DDF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entretien_machine ADD CONSTRAINT FK_684FF6DD74F628A8 FOREIGN KEY (derniere_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE atelier_machine ADD CONSTRAINT FK_273C843CF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE atelier_machine ADD CONSTRAINT FK_273C843C758161D1 FOREIGN KEY (niveaux_encre_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE atelier_machine_operateur ADD CONSTRAINT FK_68252A553BB0F82B FOREIGN KEY (atelier_machine_id) REFERENCES atelier_machine (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE atelier_machine_operateur ADD CONSTRAINT FK_68252A55A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_machine ADD CONSTRAINT FK_B2CFC559F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE incident_machine ADD CONSTRAINT FK_B2CFC559AE190A20 FOREIGN KEY (signale_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE incident_machine ADD CONSTRAINT FK_B2CFC5599291498C FOREIGN KEY (ordre_id) REFERENCES ordre_production (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE incident_machine ADD CONSTRAINT FK_B2CFC5595E3DAC49 FOREIGN KEY (resolu_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE atelier_machine_operateur');
        $this->addSql('DROP TABLE incident_machine');
        $this->addSql('DROP TABLE entretien_machine');
        $this->addSql('DROP TABLE atelier_machine');
    }
}
