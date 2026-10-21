<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Production au rouleau (docs/production-rouleau.md, maquettes PD01 à PD03) :
 * dossier, tête, usages, miroir et profils d'impression de chaque machine ;
 * tirages (rouleaux) et pièces posées ; nom du fichier de production et
 * copie dans le dossier de la machine sur l'ordre ; code de production des
 * supports et des finitions. Tout reste au local (rien n'est synchronisé).
 */
final class Version20261020231700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production au rouleau : tirages, pièces, dossiers et profils des machines, noms des fichiers de production';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tirage_rouleau (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) NOT NULL, libelle_support VARCHAR(160) NOT NULL, code_support VARCHAR(30) NOT NULL, laize_m NUMERIC(6, 3) NOT NULL, marge_cm NUMERIC(6, 2) NOT NULL, espace_cm NUMERIC(6, 2) NOT NULL, mode VARCHAR(10) NOT NULL, statut VARCHAR(20) NOT NULL, imprime_m2 NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, consomme_m NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, consomme_m2 NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, chute_m2 NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL, pourcent SMALLINT DEFAULT 0 NOT NULL, duree_impression_min INT DEFAULT NULL, sechage_min INT DEFAULT NULL, encre_ml INT DEFAULT NULL, fichier_rip_chemin VARCHAR(255) DEFAULT NULL, fichier_rip_nom VARCHAR(180) DEFAULT NULL, fichier_rip_empreinte VARCHAR(64) DEFAULT NULL, envoi_mode VARCHAR(12) DEFAULT NULL, envoye_le DATETIME DEFAULT NULL, copie_machine VARCHAR(255) DEFAULT NULL, envois JSON DEFAULT NULL, controles JSON DEFAULT NULL, rouleau_confirme_le DATETIME DEFAULT NULL, demarre_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME DEFAULT NULL, machine_id INT NOT NULL, support_id INT DEFAULT NULL, envoye_par_id INT DEFAULT NULL, rouleau_confirme_par_id INT DEFAULT NULL, demarre_par_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_8BFE7423F55AE19E (numero), INDEX IDX_8BFE7423F6B75B26 (machine_id), INDEX IDX_8BFE7423315B405 (support_id), INDEX IDX_8BFE7423D603292 (envoye_par_id), INDEX IDX_8BFE7423A16399AD (rouleau_confirme_par_id), INDEX IDX_8BFE7423E6889AD8 (demarre_par_id), INDEX IDX_8BFE7423FC29C013 (cree_par_id), INDEX idx_tirage_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE piece_tirage (id INT AUTO_INCREMENT NOT NULL, rang INT NOT NULL, exemplaire INT NOT NULL, x_cm NUMERIC(8, 2) NOT NULL, y_cm NUMERIC(8, 2) NOT NULL, largeur_cm NUMERIC(8, 2) NOT NULL, hauteur_cm NUMERIC(8, 2) NOT NULL, tourne TINYINT DEFAULT 0 NOT NULL, statut VARCHAR(12) NOT NULL, imprimee_le DATETIME DEFAULT NULL, tirage_id INT NOT NULL, ordre_id INT NOT NULL, INDEX IDX_F06651AE82579054 (tirage_id), INDEX IDX_F06651AE9291498C (ordre_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE piece_tirage ADD CONSTRAINT FK_F06651AE82579054 FOREIGN KEY (tirage_id) REFERENCES tirage_rouleau (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE piece_tirage ADD CONSTRAINT FK_F06651AE9291498C FOREIGN KEY (ordre_id) REFERENCES ordre_production (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423315B405 FOREIGN KEY (support_id) REFERENCES articles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423D603292 FOREIGN KEY (envoye_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423A16399AD FOREIGN KEY (rouleau_confirme_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423E6889AD8 FOREIGN KEY (demarre_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE7423FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE atelier_machine ADD code_dossier VARCHAR(40) DEFAULT NULL, ADD tete_impression VARCHAR(80) DEFAULT NULL, ADD usages VARCHAR(160) DEFAULT NULL, ADD miroir TINYINT DEFAULT 0 NOT NULL, ADD profils JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE finition ADD code_production VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE supports ADD code_production VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE ordre_production ADD nom_fichier_production VARCHAR(160) DEFAULT NULL, ADD nom_fichier_decoupe VARCHAR(160) DEFAULT NULL, ADD fichier_machine_chemin VARCHAR(255) DEFAULT NULL, ADD fichier_machine_le DATETIME DEFAULT NULL, ADD tirage_id INT DEFAULT NULL, ADD fichier_machine_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordre_production ADD CONSTRAINT FK_D1C3E84482579054 FOREIGN KEY (tirage_id) REFERENCES tirage_rouleau (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ordre_production ADD CONSTRAINT FK_D1C3E844E7C60809 FOREIGN KEY (fichier_machine_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D1C3E84482579054 ON ordre_production (tirage_id)');
        $this->addSql('CREATE INDEX IDX_D1C3E844E7C60809 ON ordre_production (fichier_machine_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E84482579054');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844E7C60809');
        $this->addSql('DROP INDEX IDX_D1C3E84482579054 ON ordre_production');
        $this->addSql('DROP INDEX IDX_D1C3E844E7C60809 ON ordre_production');
        $this->addSql('ALTER TABLE ordre_production DROP nom_fichier_production, DROP nom_fichier_decoupe, DROP fichier_machine_chemin, DROP fichier_machine_le, DROP tirage_id, DROP fichier_machine_par_id');
        $this->addSql('ALTER TABLE supports DROP code_production');
        $this->addSql('ALTER TABLE finition DROP code_production');
        $this->addSql('ALTER TABLE atelier_machine DROP code_dossier, DROP tete_impression, DROP usages, DROP miroir, DROP profils');
        $this->addSql('ALTER TABLE piece_tirage DROP FOREIGN KEY FK_F06651AE82579054');
        $this->addSql('ALTER TABLE piece_tirage DROP FOREIGN KEY FK_F06651AE9291498C');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423F6B75B26');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423315B405');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423D603292');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423A16399AD');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423E6889AD8');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE7423FC29C013');
        $this->addSql('DROP TABLE piece_tirage');
        $this->addSql('DROP TABLE tirage_rouleau');
    }
}
