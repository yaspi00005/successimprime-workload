<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011163547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraisons : position GPS de la remise, retard justifié et erreur de livraison (points du livreur, W04)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD remise_latitude DOUBLE PRECISION DEFAULT NULL, ADD remise_longitude DOUBLE PRECISION DEFAULT NULL, ADD retard_justifie VARCHAR(255) DEFAULT NULL, ADD erreur_livraison VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison DROP remise_latitude, DROP remise_longitude, DROP retard_justifie, DROP erreur_livraison');
    }
}
