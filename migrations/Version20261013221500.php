<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bons de livraison : identifiant public pour la synchronisation (livreur en route, livree dans l\'espace client)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD public_id BINARY(16) DEFAULT NULL');
        $this->addSql("UPDATE bon_livraison SET public_id = UNHEX(REPLACE(UUID(), '-', '')) WHERE public_id IS NULL");
        $this->addSql('ALTER TABLE bon_livraison MODIFY public_id BINARY(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_31A531A4B5B48B91 ON bon_livraison (public_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_31A531A4B5B48B91 ON bon_livraison');
        $this->addSql('ALTER TABLE bon_livraison DROP public_id');
    }
}
