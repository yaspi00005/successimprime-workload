<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011141408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pointage QR + photo : points, appareils, pointages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appareil_pointage (id INT AUTO_INCREMENT NOT NULL, empreinte VARCHAR(64) NOT NULL, nom VARCHAR(120) NOT NULL, etat VARCHAR(20) NOT NULL, cree_le DATETIME NOT NULL, decide_le DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, decide_par_id INT DEFAULT NULL, INDEX IDX_B0CFBE60FB88E14F (utilisateur_id), INDEX IDX_B0CFBE602E54F2F1 (decide_par_id), UNIQUE INDEX uniq_appareil_pointage_empreinte (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE point_pointage (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, nom VARCHAR(80) NOT NULL, secret VARCHAR(64) NOT NULL, latitude DOUBLE PRECISION DEFAULT NULL, longitude DOUBLE PRECISION DEFAULT NULL, rayon_metres INT NOT NULL, actif TINYINT NOT NULL, renouvele_le DATETIME NOT NULL, UNIQUE INDEX uniq_point_pointage_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pointage (id INT AUTO_INCREMENT NOT NULL, arrivee_le DATETIME NOT NULL, depart_le DATETIME DEFAULT NULL, photo_arrivee VARCHAR(120) DEFAULT NULL, photo_depart VARCHAR(120) DEFAULT NULL, position_arrivee JSON DEFAULT NULL, position_depart JSON DEFAULT NULL, heures_sup_validees TINYINT NOT NULL, ajustement_points INT NOT NULL, motif_ajustement VARCHAR(255) DEFAULT NULL, motif_correction VARCHAR(255) DEFAULT NULL, corrige TINYINT NOT NULL, utilisateur_id INT NOT NULL, point_arrivee_id INT DEFAULT NULL, point_depart_id INT DEFAULT NULL, appareil_arrivee_id INT DEFAULT NULL, appareil_depart_id INT DEFAULT NULL, INDEX IDX_7591B20FB88E14F (utilisateur_id), INDEX IDX_7591B2036855DE8 (point_arrivee_id), INDEX IDX_7591B20D2C9B65 (point_depart_id), INDEX IDX_7591B20BC35D1B6 (appareil_arrivee_id), INDEX IDX_7591B20D869297E (appareil_depart_id), INDEX idx_pointage_arrivee (arrivee_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appareil_pointage ADD CONSTRAINT FK_B0CFBE60FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appareil_pointage ADD CONSTRAINT FK_B0CFBE602E54F2F1 FOREIGN KEY (decide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pointage ADD CONSTRAINT FK_7591B20FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pointage ADD CONSTRAINT FK_7591B2036855DE8 FOREIGN KEY (point_arrivee_id) REFERENCES point_pointage (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pointage ADD CONSTRAINT FK_7591B20D2C9B65 FOREIGN KEY (point_depart_id) REFERENCES point_pointage (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pointage ADD CONSTRAINT FK_7591B20BC35D1B6 FOREIGN KEY (appareil_arrivee_id) REFERENCES appareil_pointage (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE pointage ADD CONSTRAINT FK_7591B20D869297E FOREIGN KEY (appareil_depart_id) REFERENCES appareil_pointage (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appareil_pointage DROP FOREIGN KEY FK_B0CFBE60FB88E14F');
        $this->addSql('ALTER TABLE appareil_pointage DROP FOREIGN KEY FK_B0CFBE602E54F2F1');
        $this->addSql('ALTER TABLE pointage DROP FOREIGN KEY FK_7591B20FB88E14F');
        $this->addSql('ALTER TABLE pointage DROP FOREIGN KEY FK_7591B2036855DE8');
        $this->addSql('ALTER TABLE pointage DROP FOREIGN KEY FK_7591B20D2C9B65');
        $this->addSql('ALTER TABLE pointage DROP FOREIGN KEY FK_7591B20BC35D1B6');
        $this->addSql('ALTER TABLE pointage DROP FOREIGN KEY FK_7591B20D869297E');
        $this->addSql('DROP TABLE appareil_pointage');
        $this->addSql('DROP TABLE point_pointage');
        $this->addSql('DROP TABLE pointage');
    }
}
