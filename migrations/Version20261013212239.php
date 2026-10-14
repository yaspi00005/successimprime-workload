<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013212239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finances : lignes du relevé Orange Money importé, à rapprocher des paiements (table locale, non synchronisée)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ligne_releve_orange_money (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(60) NOT NULL, recu_le DATETIME NOT NULL, montant INT NOT NULL, expediteur VARCHAR(30) DEFAULT NULL, nom_expediteur VARCHAR(120) DEFAULT NULL, libelle VARCHAR(255) DEFAULT NULL, statut VARCHAR(20) NOT NULL, score SMALLINT DEFAULT NULL, motif_ignoree VARCHAR(255) DEFAULT NULL, importee_le DATETIME NOT NULL, traitee_le DATETIME DEFAULT NULL, paiement_id INT DEFAULT NULL, importee_par_id INT DEFAULT NULL, traitee_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_70035EC42A4C4478 (paiement_id), INDEX IDX_70035EC4CD423B57 (importee_par_id), INDEX IDX_70035EC43338F50D (traitee_par_id), INDEX idx_releve_om_recu_le (recu_le), UNIQUE INDEX uniq_releve_om_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ligne_releve_orange_money ADD CONSTRAINT FK_70035EC42A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_releve_orange_money ADD CONSTRAINT FK_70035EC4CD423B57 FOREIGN KEY (importee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ligne_releve_orange_money ADD CONSTRAINT FK_70035EC43338F50D FOREIGN KEY (traitee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ligne_releve_orange_money DROP FOREIGN KEY FK_70035EC42A4C4478');
        $this->addSql('ALTER TABLE ligne_releve_orange_money DROP FOREIGN KEY FK_70035EC4CD423B57');
        $this->addSql('ALTER TABLE ligne_releve_orange_money DROP FOREIGN KEY FK_70035EC43338F50D');
        $this->addSql('DROP TABLE ligne_releve_orange_money');
    }
}
