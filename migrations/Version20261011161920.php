<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011161920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Machines : capacité prévue et valeur résiduelle pour l\'amortissement à l\'usage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machines ADD capacite_usage DOUBLE PRECISION DEFAULT NULL, ADD valeur_residuelle INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machines DROP capacite_usage, DROP valeur_residuelle');
    }
}
