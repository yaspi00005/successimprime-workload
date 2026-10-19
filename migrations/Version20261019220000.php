<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche produit (maquette PR02) : l'espace client a son propre
 * interrupteur, séparé du site public. Au départ, il suit le réglage
 * « Site public et espace client » d'avant : rien ne change en ligne.
 */
final class Version20261019220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Produits : interrupteur Espace client séparé du site public';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produits ADD visible_espace_client TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE produits SET visible_espace_client = visible_en_ligne');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produits DROP visible_espace_client');
    }
}
