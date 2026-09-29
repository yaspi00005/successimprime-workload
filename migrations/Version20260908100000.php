<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute clients.solde_credit (monnaie non rendue) et rend "
            . "paiements.compte_tresorerie_id facultatif (nouveau mode "
            . "MODE_SOLDE_CLIENT, qui ne credite aucun compte de tresorerie).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE clients ADD solde_credit INT NOT NULL DEFAULT 0"
        );

        $this->addSql(
            "ALTER TABLE paiements CHANGE compte_tresorerie_id compte_tresorerie_id INT DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE paiements CHANGE compte_tresorerie_id compte_tresorerie_id INT NOT NULL"
        );

        $this->addSql(
            "ALTER TABLE clients DROP solde_credit"
        );
    }
}
