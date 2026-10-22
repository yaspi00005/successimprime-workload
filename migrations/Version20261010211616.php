<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010211616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cout de revient automatique (machines, finitions) et position des clients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients ADD latitude NUMERIC(10, 7) DEFAULT NULL, ADD longitude NUMERIC(10, 7) DEFAULT NULL, ADD repere VARCHAR(255) DEFAULT NULL, ADD localise_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE finition ADD cout_unitaire INT DEFAULT NULL');
        $this->addSql('ALTER TABLE machines ADD vitesse_m2_heure NUMERIC(8, 2) DEFAULT NULL, ADD cout_horaire INT DEFAULT NULL, ADD cout_encre_m2 INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients DROP latitude, DROP longitude, DROP repere, DROP localise_le');
        $this->addSql('ALTER TABLE finition DROP cout_unitaire');
        $this->addSql('ALTER TABLE machines DROP vitesse_m2_heure, DROP cout_horaire, DROP cout_encre_m2');
    }
}
