<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011160528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Montage : marge et feuilles par machine, données du montage sur le fichier';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier ADD montage JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE machines ADD marge_montage_mm DOUBLE PRECISION DEFAULT NULL, ADD formats_montage VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier DROP montage');
        $this->addSql('ALTER TABLE machines DROP marge_montage_mm, DROP formats_montage');
    }
}
