<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace client : « Mes fichiers » (maquette 16) et envoi des fichiers clients du site au local.
 */
final class Version20261012230033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fichiers envoyés par le client depuis son espace : client, logo, contrôle automatique, empreinte ; demande du client sur la ligne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier ADD logo TINYINT DEFAULT 0 NOT NULL, ADD analyse JSON DEFAULT NULL, ADD empreinte VARCHAR(64) DEFAULT NULL, ADD client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commande_detail_fichier ADD CONSTRAINT FK_39E2DA1019EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_39E2DA1019EB6921 ON commande_detail_fichier (client_id)');
        $this->addSql('ALTER TABLE commandes_details ADD demande_client LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier DROP FOREIGN KEY FK_39E2DA1019EB6921');
        $this->addSql('DROP INDEX IDX_39E2DA1019EB6921 ON commande_detail_fichier');
        $this->addSql('ALTER TABLE commandes_details DROP demande_client');
        $this->addSql('ALTER TABLE commande_detail_fichier DROP logo, DROP analyse, DROP empreinte, DROP client_id');
    }
}
