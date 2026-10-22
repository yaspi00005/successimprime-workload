<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prépresse : une ligne sans fichier peut être retirée de la liste
 * (« Retirer de la liste ») sans clôturer ni supprimer la commande.
 */
final class Version20261020060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prépresse : lignes sans fichier retirées de la liste';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes_details ADD retire_prepresse_le DATETIME DEFAULT NULL, ADD retire_prepresse_par VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes_details DROP retire_prepresse_le, DROP retire_prepresse_par');
    }
}
