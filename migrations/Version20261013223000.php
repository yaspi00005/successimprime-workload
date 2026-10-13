<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entreprises : identifiant public pour la synchronisation (coordonnees du site vitrine)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise ADD public_id BINARY(16) DEFAULT NULL');
        // Identifiant tiré du code : le même sur l'ERP local et sur le site, où ces lignes existent déjà
        // (créées par Version20261010231429). Un identifiant au hasard ferait des doublons à la synchronisation.
        $this->addSql("UPDATE entreprise SET public_id = UNHEX(MD5(CONCAT('entreprise:', code))) WHERE public_id IS NULL");
        $this->addSql('ALTER TABLE entreprise MODIFY public_id BINARY(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D19FA60B5B48B91 ON entreprise (public_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_D19FA60B5B48B91 ON entreprise');
        $this->addSql('ALTER TABLE entreprise DROP public_id');
    }
}
