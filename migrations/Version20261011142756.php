<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011142756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actions de caisse : lignes, délai en heures de travail, points';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE action_caisse (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, cause VARCHAR(60) NOT NULL, libelle VARCHAR(160) NOT NULL, detail VARCHAR(255) DEFAULT NULL, cible_type VARCHAR(20) DEFAULT NULL, cible_id INT DEFAULT NULL, telephone VARCHAR(30) DEFAULT NULL, montant INT DEFAULT NULL, cree_le DATETIME NOT NULL, depart_le DATETIME NOT NULL, echeance DATETIME NOT NULL, etat VARCHAR(12) NOT NULL, faite_le DATETIME DEFAULT NULL, resultat VARCHAR(20) DEFAULT NULL, note LONGTEXT DEFAULT NULL, promesse_le DATE DEFAULT NULL, promesse_moyen VARCHAR(30) DEFAULT NULL, contact_le DATETIME DEFAULT NULL, contact_canal VARCHAR(12) DEFAULT NULL, points INT NOT NULL, penalite INT NOT NULL, controle_le DATETIME DEFAULT NULL, points_annules TINYINT NOT NULL, motif_controle VARCHAR(255) DEFAULT NULL, client_id INT DEFAULT NULL, prospect_id INT DEFAULT NULL, assigne_a_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, faite_par_id INT DEFAULT NULL, controle_par_id INT DEFAULT NULL, INDEX IDX_709362A319EB6921 (client_id), INDEX IDX_709362A3D182060A (prospect_id), INDEX IDX_709362A3BB1B0F33 (assigne_a_id), INDEX IDX_709362A3FC29C013 (cree_par_id), INDEX IDX_709362A3178DEB94 (faite_par_id), INDEX IDX_709362A3BA5AD30 (controle_par_id), INDEX idx_action_caisse_cause (cause), INDEX idx_action_caisse_etat (etat, echeance), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A319EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A3D182060A FOREIGN KEY (prospect_id) REFERENCES prospect (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A3BB1B0F33 FOREIGN KEY (assigne_a_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A3FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A3178DEB94 FOREIGN KEY (faite_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE action_caisse ADD CONSTRAINT FK_709362A3BA5AD30 FOREIGN KEY (controle_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A319EB6921');
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A3D182060A');
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A3BB1B0F33');
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A3FC29C013');
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A3178DEB94');
        $this->addSql('ALTER TABLE action_caisse DROP FOREIGN KEY FK_709362A3BA5AD30');
        $this->addSql('DROP TABLE action_caisse');
    }
}
