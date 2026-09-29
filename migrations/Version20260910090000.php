<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute clients.recevoir_sms (opt-out SMS par client) et "
            . "campagne.sender_name (nom d'expediteur choisi par campagne, "
            . "sinon le nom par defaut de .env.local est utilise).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE clients ADD recevoir_sms TINYINT(1) NOT NULL DEFAULT 1"
        );

        $this->addSql(
            "ALTER TABLE campagne ADD sender_name VARCHAR(20) DEFAULT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE campagne DROP sender_name"
        );

        $this->addSql(
            "ALTER TABLE clients DROP recevoir_sms"
        );
    }
}
