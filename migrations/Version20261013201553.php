<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013201553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Forme du menu par rôle (maquettes R01 à R03) : volets, menu court ou replié';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE role_utilisateur ADD menu VARCHAR(10) DEFAULT 'court' NOT NULL");
        // Le graphiste travaille sur l'aperçu des fichiers : menu replié en icônes.
        $this->addSql("UPDATE role_utilisateur SET menu = 'replie' WHERE code = 'ROLE_GRAPHISTE'");
        $this->addSql("UPDATE role_utilisateur SET menu = 'volets' WHERE code = 'ROLE_ADMIN'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_utilisateur DROP menu');
    }
}
