<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011144107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace employé : demandes (absence, congé, réclamation, frais médicaux) et droits aux congés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE conge_employe (id INT AUTO_INCREMENT NOT NULL, actif TINYINT NOT NULL, solde DOUBLE PRECISION NOT NULL, plage_debut DATE DEFAULT NULL, plage_fin DATE DEFAULT NULL, plage_jours DOUBLE PRECISION DEFAULT NULL, utilisateur_id INT NOT NULL, UNIQUE INDEX uniq_conge_employe_utilisateur (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE demande_employe (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, categorie VARCHAR(20) DEFAULT NULL, cree_le DATETIME NOT NULL, du DATE NOT NULL, au DATE DEFAULT NULL, demi_journee TINYINT NOT NULL, jours DOUBLE PRECISION DEFAULT NULL, motif VARCHAR(255) NOT NULL, montant INT DEFAULT NULL, montant_accorde INT DEFAULT NULL, justificatif VARCHAR(120) DEFAULT NULL, etat VARCHAR(12) NOT NULL, commentaire VARCHAR(255) DEFAULT NULL, decide_le DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, decide_par_id INT DEFAULT NULL, mouvement_id INT DEFAULT NULL, INDEX IDX_C8F36816FB88E14F (utilisateur_id), INDEX IDX_C8F368162E54F2F1 (decide_par_id), INDEX IDX_C8F36816ECD1C222 (mouvement_id), INDEX idx_demande_employe_etat (etat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE conge_employe ADD CONSTRAINT FK_2888881AFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_employe ADD CONSTRAINT FK_C8F36816FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_employe ADD CONSTRAINT FK_C8F368162E54F2F1 FOREIGN KEY (decide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_employe ADD CONSTRAINT FK_C8F36816ECD1C222 FOREIGN KEY (mouvement_id) REFERENCES mouvement_tresorerie (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conge_employe DROP FOREIGN KEY FK_2888881AFB88E14F');
        $this->addSql('ALTER TABLE demande_employe DROP FOREIGN KEY FK_C8F36816FB88E14F');
        $this->addSql('ALTER TABLE demande_employe DROP FOREIGN KEY FK_C8F368162E54F2F1');
        $this->addSql('ALTER TABLE demande_employe DROP FOREIGN KEY FK_C8F36816ECD1C222');
        $this->addSql('DROP TABLE conge_employe');
        $this->addSql('DROP TABLE demande_employe');
    }
}
