<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pipeline prospects (maquette 34).
 */
final class Version20261011054700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pipeline prospects (maquette 34)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prospect (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, telephone VARCHAR(30) DEFAULT NULL, besoin VARCHAR(255) DEFAULT NULL, montant_estime INT DEFAULT NULL, source VARCHAR(20) NOT NULL, etape VARCHAR(20) NOT NULL, motif_perte VARCHAR(255) DEFAULT NULL, prochaine_relance DATE DEFAULT NULL, cree_le DATETIME NOT NULL, mis_a_jour_le DATETIME NOT NULL, cloture_le DATETIME DEFAULT NULL, client_id INT DEFAULT NULL, agent_id INT DEFAULT NULL, INDEX IDX_C9CE8C7D19EB6921 (client_id), INDEX IDX_C9CE8C7D3414710B (agent_id), INDEX idx_prospect_etape (etape), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prospect ADD CONSTRAINT FK_C9CE8C7D19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE prospect ADD CONSTRAINT FK_C9CE8C7D3414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prospect DROP FOREIGN KEY FK_C9CE8C7D19EB6921');
        $this->addSql('ALTER TABLE prospect DROP FOREIGN KEY FK_C9CE8C7D3414710B');
        $this->addSql('DROP TABLE prospect');
    }
}
