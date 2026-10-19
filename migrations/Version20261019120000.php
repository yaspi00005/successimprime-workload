<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Page Contact du site (maquettes EC04 à EC08 du 19/10/2026) : numéro de
 * commande et heure de rappel d'une demande (montent au local) ; au local,
 * la conversation ouverte pour la demande, le rappel fait et son alerte ;
 * origine « site » d'une conversation, qui reste au local.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Page Contact : demande_devis (numéro de commande, rappel, conversation), conversation_client.origine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation_client ADD origine VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE demande_devis ADD numero_commande VARCHAR(50) DEFAULT NULL, ADD rappel_le DATETIME DEFAULT NULL, ADD rappel_fait_le DATETIME DEFAULT NULL, ADD rappel_fait_par VARCHAR(80) DEFAULT NULL, ADD rappel_alerte_le DATETIME DEFAULT NULL, ADD conversation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF946029AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation_client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7DF946029AC0396 ON demande_devis (conversation_id)');
        $this->addSql('CREATE INDEX idx_demande_devis_rappel ON demande_devis (nature, rappel_le)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_demande_devis_rappel ON demande_devis');
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF946029AC0396');
        $this->addSql('DROP INDEX IDX_7DF946029AC0396 ON demande_devis');
        $this->addSql('ALTER TABLE demande_devis DROP numero_commande, DROP rappel_le, DROP rappel_fait_le, DROP rappel_fait_par, DROP rappel_alerte_le, DROP conversation_id');
        $this->addSql('ALTER TABLE conversation_client DROP origine');
    }
}
