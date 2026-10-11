<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptes de l'espace client (maquettes CX01 et CX04).
 */
final class Version20261011042519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes de l\'espace client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compte_client (id INT AUTO_INCREMENT NOT NULL, telephone VARCHAR(20) NOT NULL, mot_de_passe VARCHAR(255) DEFAULT NULL, actif TINYINT NOT NULL, cree_le DATETIME NOT NULL, derniere_connexion DATETIME DEFAULT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_1DDD5D62450FF010 (telephone), UNIQUE INDEX UNIQ_1DDD5D6219EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compte_client ADD CONSTRAINT FK_1DDD5D6219EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_client DROP FOREIGN KEY FK_1DDD5D6219EB6921');
        $this->addSql('DROP TABLE compte_client');
    }
}
