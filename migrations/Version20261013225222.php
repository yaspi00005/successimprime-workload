<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013225222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site vitrine : demandes de devis et messages sans compte (montent au local, deviennent prospect et devis brouillon)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demande_devis (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) DEFAULT NULL, nature VARCHAR(10) NOT NULL, nom VARCHAR(120) NOT NULL, telephone VARCHAR(15) NOT NULL, email VARCHAR(180) DEFAULT NULL, objet VARCHAR(20) DEFAULT NULL, designation VARCHAR(255) DEFAULT NULL, largeur_cm NUMERIC(7, 1) DEFAULT NULL, hauteur_cm NUMERIC(7, 1) DEFAULT NULL, quantite INT DEFAULT NULL, message LONGTEXT DEFAULT NULL, cree_le DATETIME NOT NULL, accuse_envoye_le DATETIME DEFAULT NULL, preparee_le DATETIME DEFAULT NULL, traitee_le DATETIME DEFAULT NULL, traitee_par VARCHAR(80) DEFAULT NULL, public_id BINARY(16) NOT NULL, configuration_id INT DEFAULT NULL, fichier_id INT DEFAULT NULL, client_id INT DEFAULT NULL, prospect_id INT DEFAULT NULL, devis_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_7DF94602B5B48B91 (public_id), INDEX IDX_7DF9460273F32DD8 (configuration_id), INDEX IDX_7DF94602F915CFE (fichier_id), INDEX IDX_7DF9460219EB6921 (client_id), INDEX IDX_7DF94602D182060A (prospect_id), INDEX IDX_7DF9460241DEFADA (devis_id), INDEX idx_demande_devis_traitee (traitee_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE demande_devis_finition (demande_devis_id INT NOT NULL, finition_id INT NOT NULL, INDEX IDX_89FE3FBF787B318 (demande_devis_id), INDEX IDX_89FE3FBFCB56F5AF (finition_id), PRIMARY KEY (demande_devis_id, finition_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF9460273F32DD8 FOREIGN KEY (configuration_id) REFERENCES produit_configuration (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF94602F915CFE FOREIGN KEY (fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF9460219EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF94602D182060A FOREIGN KEY (prospect_id) REFERENCES prospect (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF9460241DEFADA FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis_finition ADD CONSTRAINT FK_89FE3FBF787B318 FOREIGN KEY (demande_devis_id) REFERENCES demande_devis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_devis_finition ADD CONSTRAINT FK_89FE3FBFCB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF9460273F32DD8');
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF94602F915CFE');
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF9460219EB6921');
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF94602D182060A');
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF9460241DEFADA');
        $this->addSql('ALTER TABLE demande_devis_finition DROP FOREIGN KEY FK_89FE3FBF787B318');
        $this->addSql('ALTER TABLE demande_devis_finition DROP FOREIGN KEY FK_89FE3FBFCB56F5AF');
        $this->addSql('DROP TABLE demande_devis');
        $this->addSql('DROP TABLE demande_devis_finition');
    }
}
