<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011050435 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Machines et maintenance (maquette 27) : temps d\'arrêt des interventions et plan préventif des machines.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machines ADD plan_preventif LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenance ADD duree_arret_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machines DROP plan_preventif');
        $this->addSql('ALTER TABLE maintenance DROP duree_arret_minutes');
    }
}
