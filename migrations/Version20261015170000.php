<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compte des paiements Orange Money en ligne (API) : une marque sur le
 * compte de trésorerie qui reçoit les paiements de l'espace client. Le
 * compte « Orange Money en ligne » est créé au premier paiement en ligne
 * validé s'il n'existe pas (App\Service\Paiement\ComptesOrangeMoney).
 */
final class Version20261015170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trésorerie : compte des paiements Orange Money en ligne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_tresorerie ADD orange_money_en_ligne TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_tresorerie DROP orange_money_en_ligne');
    }
}
