<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace client : e-mail de la personne, où partent ses codes de
 * connexion (première connexion, mot de passe oublié). Reste sur le site.
 */
final class Version20261014230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client : compte_client.email (codes de connexion par e-mail)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_client ADD email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_client DROP email');
    }
}
