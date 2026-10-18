<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Site vitrine : « Le mot du gérant », avant « Notre équipe » sur la page
 * « Qui sommes-nous ? » (demande de Yaya du 17/10/2026). Le gérant est
 * une personne de l'équipe cochée « Gérant », avec son mot ; « aussi dans
 * l'équipe » le montre une seconde fois dans la grille. Descend au site
 * avec le type « site_equipe ».
 */
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site vitrine : mot du gérant (personne de l\'équipe cochée « Gérant », son texte, montrée aussi dans l\'équipe ou non)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_equipe ADD gerant TINYINT DEFAULT 0 NOT NULL, ADD mot_gerant LONGTEXT DEFAULT NULL, ADD aussi_dans_equipe TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_equipe DROP gerant, DROP mot_gerant, DROP aussi_dans_equipe');
    }
}
