<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013210538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réclamations des clients (maquette 18) et leurs photos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reclamation_client (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) DEFAULT NULL, auteur VARCHAR(80) DEFAULT NULL, motif VARCHAR(20) NOT NULL, objet VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, statut VARCHAR(12) NOT NULL, reponse LONGTEXT DEFAULT NULL, repondu_par VARCHAR(80) DEFAULT NULL, repondu_le DATETIME DEFAULT NULL, close_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_56FD64F1B5B48B91 (public_id), INDEX IDX_56FD64F119EB6921 (client_id), INDEX IDX_56FD64F182EA2E54 (commande_id), INDEX idx_reclamation_client_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reclamation_client_photo (id INT AUTO_INCREMENT NOT NULL, contenu MEDIUMTEXT NOT NULL, type VARCHAR(20) NOT NULL, largeur INT NOT NULL, hauteur INT NOT NULL, public_id BINARY(16) NOT NULL, reclamation_id INT NOT NULL, UNIQUE INDEX UNIQ_30CEEFCDB5B48B91 (public_id), INDEX IDX_30CEEFCD2D6BA2D9 (reclamation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reclamation_client ADD CONSTRAINT FK_56FD64F119EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reclamation_client ADD CONSTRAINT FK_56FD64F182EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reclamation_client_photo ADD CONSTRAINT FK_30CEEFCD2D6BA2D9 FOREIGN KEY (reclamation_id) REFERENCES reclamation_client (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reclamation_client DROP FOREIGN KEY FK_56FD64F119EB6921');
        $this->addSql('ALTER TABLE reclamation_client DROP FOREIGN KEY FK_56FD64F182EA2E54');
        $this->addSql('ALTER TABLE reclamation_client_photo DROP FOREIGN KEY FK_30CEEFCD2D6BA2D9');
        $this->addSql('DROP TABLE reclamation_client');
        $this->addSql('DROP TABLE reclamation_client_photo');
    }
}
