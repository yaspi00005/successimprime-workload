<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Canal des commandes (comptoir ou en ligne) pour l\'écran TV et l\'espace client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commandes ADD canal VARCHAR(20) DEFAULT 'comptoir' NOT NULL");
        $this->addSql('CREATE INDEX idx_commandes_canal_date ON commandes (canal, date_commande)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_commandes_canal_date ON commandes');
        $this->addSql('ALTER TABLE commandes DROP canal');
    }
}
