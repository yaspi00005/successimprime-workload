<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saisie de commande (maquette C01) : retrait à la boutique ou
 * livraison, et adresse de livraison.
 */
final class Version20261011014428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mode et adresse de livraison choisis à la saisie de la commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes ADD mode_livraison VARCHAR(20) DEFAULT \'retrait\' NOT NULL, ADD adresse_livraison VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes DROP mode_livraison, DROP adresse_livraison');
    }
}
