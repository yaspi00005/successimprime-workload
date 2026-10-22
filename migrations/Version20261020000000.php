<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avoirs (maquette FD04) : une facture validée ne se modifie jamais,
 * toute correction passe par un avoir numéroté.
 */
final class Version20261020000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Avoirs sur facture validée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE avoir (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(30) NOT NULL, motif VARCHAR(30) NOT NULL, lignes JSON NOT NULL, remise INT NOT NULL, total INT NOT NULL, suite VARCHAR(12) NOT NULL, deduit INT NOT NULL, rembourse INT NOT NULL, observation LONGTEXT DEFAULT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, facture_id INT NOT NULL, client_id INT NOT NULL, reclamation_id INT DEFAULT NULL, compte_remboursement_id INT DEFAULT NULL, valide_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_659B1A43F55AE19E (numero), UNIQUE INDEX UNIQ_659B1A43B5B48B91 (public_id), INDEX IDX_659B1A437F2DEE08 (facture_id), INDEX IDX_659B1A4319EB6921 (client_id), INDEX IDX_659B1A432D6BA2D9 (reclamation_id), INDEX IDX_659B1A4364C37336 (compte_remboursement_id), INDEX IDX_659B1A436AF12ED9 (valide_par_id), INDEX idx_avoir_cree_le (cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE avoir ADD CONSTRAINT FK_659B1A437F2DEE08 FOREIGN KEY (facture_id) REFERENCES factures (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE avoir ADD CONSTRAINT FK_659B1A4319EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE avoir ADD CONSTRAINT FK_659B1A432D6BA2D9 FOREIGN KEY (reclamation_id) REFERENCES reclamation_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE avoir ADD CONSTRAINT FK_659B1A4364C37336 FOREIGN KEY (compte_remboursement_id) REFERENCES compte_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE avoir ADD CONSTRAINT FK_659B1A436AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE avoir');
    }
}
