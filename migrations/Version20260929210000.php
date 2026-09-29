<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute commandes_details.statut_production_avant_annulation, "
            . "qui memorise le statut de production de chaque ligne juste "
            . "avant l'annulation d'une commande pour permettre une "
            . "restauration fidele (CommandesController::restaurer()).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE commandes_details ADD statut_production_avant_annulation VARCHAR(30) DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE commandes_details DROP statut_production_avant_annulation"
        );
    }
}
