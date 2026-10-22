<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nom commercial du catalogue (docs/noms-commerciaux.md) : à côté de la
 * désignation technique (« Vinyle adhésif »), le nom que tout le monde
 * connaît (« Autocollant »), facultatif. Produits, supports, types
 * d'impression et articles de stock. Aucune donnée existante ne change.
 */
final class Version20261018143100 extends AbstractMigration
{
    private const TABLES = ['produits', 'supports', 'types_impression', 'articles'];

    public function getDescription(): string
    {
        return 'Catalogue : nom commercial facultatif des produits, supports, types d\'impression et articles';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD nom_commercial VARCHAR(120) DEFAULT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP nom_commercial', $table));
        }
    }
}
