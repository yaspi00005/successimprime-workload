<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouvelle commande en ligne en quatre étapes (docs/espace-client.md,
 * « Nouvelle commande ») : délai demandé par le client (standard ou
 * express) et créneau de livraison choisi. Vides pour les commandes du
 * comptoir.
 */
final class Version20261016153217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commandes en ligne : délai de production et créneau de livraison';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes ADD delai_production VARCHAR(10) DEFAULT NULL, ADD creneau_livraison VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes DROP delai_production, DROP creneau_livraison');
    }
}
