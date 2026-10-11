<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Campagnes WhatsApp et SMS (maquette 35).
 */
final class Version20261011092640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campagnes par segment avec texte libre et envoi programmé (maquette 35), accord marketing des clients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campagne ADD canal VARCHAR(20) DEFAULT NULL, ADD message LONGTEXT DEFAULT NULL, ADD segment VARCHAR(30) DEFAULT NULL, ADD envoi_prevu_le DATETIME DEFAULT NULL, CHANGE modele_message_id modele_message_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE clients ADD consentement_marketing_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE campagne DROP canal, DROP message, DROP segment, DROP envoi_prevu_le, CHANGE modele_message_id modele_message_id INT NOT NULL');
        $this->addSql('ALTER TABLE clients DROP consentement_marketing_le');
    }
}
