<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011192313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning : échéances récurrentes, tâches et fêtes mobiles (T01, T02, T04)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE echeance (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, categorie VARCHAR(20) NOT NULL, frequence VARCHAR(20) NOT NULL, jour_mois INT DEFAULT NULL, jours_semaine JSON NOT NULL, heure VARCHAR(5) DEFAULT NULL, decalage VARCHAR(10) NOT NULL, debut DATE NOT NULL, fin_le DATE DEFAULT NULL, fin_nombre INT DEFAULT NULL, etapes JSON NOT NULL, rappels JSON NOT NULL, montant_estime INT DEFAULT NULL, actif TINYINT NOT NULL, cree_le DATETIME NOT NULL, responsable_id INT DEFAULT NULL, copie_id INT DEFAULT NULL, entreprise_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, INDEX IDX_40D9893B53C59D72 (responsable_id), INDEX IDX_40D9893B4C4047D3 (copie_id), INDEX IDX_40D9893BA4AEAFEA (entreprise_id), INDEX IDX_40D9893BFC29C013 (cree_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE jour_ferie (id INT AUTO_INCREMENT NOT NULL, jour DATE NOT NULL, libelle VARCHAR(80) NOT NULL, UNIQUE INDEX UNIQ_122AB5DA17D9C5 (jour), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tache (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(150) NOT NULL, categorie VARCHAR(20) NOT NULL, prevue_le DATE NOT NULL, date_origine DATE DEFAULT NULL, raison_decalage VARCHAR(80) DEFAULT NULL, heure VARCHAR(5) DEFAULT NULL, statut VARCHAR(20) NOT NULL, etapes JSON NOT NULL, montant_estime INT DEFAULT NULL, note LONGTEXT DEFAULT NULL, fait_le DATETIME DEFAULT NULL, rappels_envoyes JSON NOT NULL, cree_le DATETIME NOT NULL, echeance_id INT DEFAULT NULL, responsable_id INT DEFAULT NULL, copie_id INT DEFAULT NULL, entreprise_id INT DEFAULT NULL, fait_par_id INT DEFAULT NULL, INDEX IDX_938720755B318673 (echeance_id), INDEX IDX_9387207553C59D72 (responsable_id), INDEX IDX_938720754C4047D3 (copie_id), INDEX IDX_93872075A4AEAFEA (entreprise_id), INDEX IDX_9387207517C2941B (fait_par_id), INDEX idx_tache_prevue (prevue_le), UNIQUE INDEX uniq_tache_occurrence (echeance_id, date_origine), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B53C59D72 FOREIGN KEY (responsable_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893B4C4047D3 FOREIGN KEY (copie_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893BA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE echeance ADD CONSTRAINT FK_40D9893BFC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720755B318673 FOREIGN KEY (echeance_id) REFERENCES echeance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207553C59D72 FOREIGN KEY (responsable_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_938720754C4047D3 FOREIGN KEY (copie_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_93872075A4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tache ADD CONSTRAINT FK_9387207517C2941B FOREIGN KEY (fait_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B53C59D72');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893B4C4047D3');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893BA4AEAFEA');
        $this->addSql('ALTER TABLE echeance DROP FOREIGN KEY FK_40D9893BFC29C013');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720755B318673');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207553C59D72');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_938720754C4047D3');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_93872075A4AEAFEA');
        $this->addSql('ALTER TABLE tache DROP FOREIGN KEY FK_9387207517C2941B');
        $this->addSql('DROP TABLE echeance');
        $this->addSql('DROP TABLE jour_ferie');
        $this->addSql('DROP TABLE tache');
    }
}
