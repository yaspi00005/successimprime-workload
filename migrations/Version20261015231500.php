<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Garder le total » (docs/migration-de-base.md) : une ligne de commande
 * ou de devis dont la remise a été convertie par la bascule et dont le
 * nouveau calcul ne redonne pas exactement le total enregistré garde ce
 * total tant que rien de ce qui le fait ne change (total_fige).
 *
 * Sur la base de l'imprimerie, app:bascule:verifier --corriger ajoute déjà
 * la colonne (avec la même définition) pour y marquer ces lignes, avant
 * cette migration : elle n'est alors pas recréée.
 */
final class Version20261015231500 extends AbstractMigration
{
    public const TABLES = ['commandes_details', 'devis_details'];

    public function getDescription(): string
    {
        return 'Lignes de commande et de devis : total d\'origine conservé après la bascule (total_fige)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table) && $schema->getTable($table)->hasColumn('total_fige')) {
                continue; // ajoutée par la bascule, déjà remplie
            }
            $this->addSql(\sprintf('ALTER TABLE %s ADD total_fige TINYINT DEFAULT 0 NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP total_fige', $table));
        }
    }
}
