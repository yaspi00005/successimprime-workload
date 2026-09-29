<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute articles.consommable_production : un article peut "
            . "desormais etre a la fois vendable ET consommable en "
            . "production (ex. bache vinyle), independamment l'un de "
            . "l'autre. Les articles deja non vendables gardent leur "
            . "visibilite dans l'ecran Consommables (backfill = NOT vendable).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE articles ADD consommable_production TINYINT(1) DEFAULT 0 NOT NULL'
        );

        $this->addSql(
            'UPDATE articles SET consommable_production = (vendable = 0)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE articles DROP consommable_production'
        );
    }
}
