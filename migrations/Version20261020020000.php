<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vente « hors imprimerie » (docs/hors-imprimerie.md) : case sur le devis
 * et la commande, comptée à part de l'imprimerie dans les statistiques.
 */
final class Version20261020020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Case « Hors imprimerie » sur les devis et les commandes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis ADD hors_imprimerie TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE commandes ADD hors_imprimerie TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis DROP hors_imprimerie');
        $this->addSql('ALTER TABLE commandes DROP hors_imprimerie');
    }
}
