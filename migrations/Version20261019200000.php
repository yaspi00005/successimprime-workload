<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Devis de l'espace client (maquettes DB01 à DB03, docs/devis.md) :
 * quantités au choix sur une ligne, choix du client, conditions de vente
 * acceptées et mode de l'acompte.
 */
final class Version20261019200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Devis : options de quantité, choix du client, conditions acceptées, mode de l\'acompte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis_details ADD options_quantite JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE devis ADD options_choisies JSON DEFAULT NULL, ADD conditions_acceptees_le DATETIME DEFAULT NULL, ADD acompte_mode VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis_details DROP options_quantite');
        $this->addSql('ALTER TABLE devis DROP options_choisies, DROP conditions_acceptees_le, DROP acompte_mode');
    }
}
