<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conservation des fichiers de commande : effacés quelques mois après
 * l'impression, sauf « Garder ce fichier 1 an » ; l'effacement est noté.
 */
final class Version20261019230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fichiers de commande : garder 1 an, date d\'effacement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier ADD garde_demandee_le DATETIME DEFAULT NULL, ADD garde_par VARCHAR(120) DEFAULT NULL, ADD efface_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier DROP garde_demandee_le, DROP garde_par, DROP efface_le');
    }
}
