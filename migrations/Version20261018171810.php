<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche client (maquettes FC01 à FC04, docs/clients.md) : canaux d'envoi
 * des factures (la personne est le contact « Factures » de la fiche) et
 * charte du client (couleurs, logo, police) pour le prépresse. Ces champs
 * restent au local : ils ne partent pas au site en ligne.
 */
final class Version20261018171810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche client : canaux d\'envoi des factures et charte du client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients ADD factures_canaux JSON DEFAULT \'[]\' NOT NULL, ADD charte_couleurs JSON DEFAULT \'[]\' NOT NULL, ADD charte_texte VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients DROP factures_canaux, DROP charte_couleurs, DROP charte_texte');
    }
}
