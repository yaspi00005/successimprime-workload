<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Factures (maquettes FD01 à FD03) : qui a validé et quand, envoi
 * WhatsApp, ouverture du lien par le client, facture d'acompte.
 */
final class Version20261020010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Validation, envois et acompte des factures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE factures ADD validee_le DATETIME DEFAULT NULL, ADD envoyee_whatsapp_le DATETIME DEFAULT NULL, ADD lien_ouvert_le DATETIME DEFAULT NULL, ADD pourcentage_acompte SMALLINT DEFAULT NULL, ADD validee_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE factures ADD CONSTRAINT FK_647590B629A7BB2 FOREIGN KEY (validee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_647590B629A7BB2 ON factures (validee_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE factures DROP FOREIGN KEY FK_647590B629A7BB2');
        $this->addSql('DROP INDEX IDX_647590B629A7BB2 ON factures');
        $this->addSql('ALTER TABLE factures DROP validee_le, DROP envoyee_whatsapp_le, DROP lien_ouvert_le, DROP pourcentage_acompte, DROP validee_par_id');
    }
}
