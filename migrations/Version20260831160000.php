<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Passe machines.compteur_m2 en decimal : un arrondi a "
            . "l'entier avant addition faisait disparaitre les petits "
            . "travaux (moins de 0,5 m2) du compteur d'usure.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE machines MODIFY compteur_m2 DOUBLE PRECISION DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE machines MODIFY compteur_m2 INT DEFAULT NULL'
        );
    }
}
