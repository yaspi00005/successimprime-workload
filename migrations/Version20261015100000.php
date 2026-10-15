<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Frais Orange Money payés par le client en plus du montant (fonds de
 * soutien et frais de retrait) : gardés sur la transaction ouverte chez
 * Orange, qui reçoit le total. Les paiements avaient déjà leurs colonnes.
 */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orange Money : fonds de soutien et frais de retrait sur transaction_orange_money';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_orange_money ADD fonds_soutien INT DEFAULT 0 NOT NULL, ADD frais_retrait INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_orange_money DROP fonds_soutien, DROP frais_retrait');
    }
}
