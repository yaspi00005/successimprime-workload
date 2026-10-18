<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace client : code d'accès donné par l'imprimerie sur WhatsApp (fiche
 * client). Seule l'empreinte du code est gardée ; elle descend sur le
 * site avec son échéance (synchronisation « acces_espace »).
 */
final class Version20261014230100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client : acces_espace_client (code d\'accès envoyé par WhatsApp, empreinte seulement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE acces_espace_client (id INT AUTO_INCREMENT NOT NULL, empreinte CHAR(64) NOT NULL, expire_le DATETIME NOT NULL, cree_le DATETIME NOT NULL, empreinte_utilisee CHAR(64) DEFAULT NULL, utilise_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, cree_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_B0037D7EB5B48B91 (public_id), UNIQUE INDEX UNIQ_B0037D7E19EB6921 (client_id), INDEX IDX_B0037D7EFC29C013 (cree_par_id), INDEX idx_acces_espace_empreinte (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE acces_espace_client ADD CONSTRAINT FK_B0037D7E19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE acces_espace_client ADD CONSTRAINT FK_B0037D7EFC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_espace_client DROP FOREIGN KEY FK_B0037D7E19EB6921');
        $this->addSql('ALTER TABLE acces_espace_client DROP FOREIGN KEY FK_B0037D7EFC29C013');
        $this->addSql('DROP TABLE acces_espace_client');
    }
}
