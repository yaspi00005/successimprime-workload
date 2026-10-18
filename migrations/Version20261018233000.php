<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notes vocales des commandes (demande de Yaya du 18/10/2026,
 * docs/messagerie-clients.md) : une note vocale jointe à un message de la
 * conversation (vue par le client) ou à une note interne (équipe seule).
 * Le fichier est sous var/uploads/vocales ; la base garde son nom de
 * stockage, son type, sa taille, sa durée et son empreinte.
 */
final class Version20261018233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notes vocales : colonnes vocale_* de message_client et note_conversation';
    }

    public function up(Schema $schema): void
    {
        foreach (['message_client', 'note_conversation'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD vocale_nom VARCHAR(60) DEFAULT NULL, ADD vocale_type VARCHAR(20) DEFAULT NULL, ADD vocale_taille INT DEFAULT NULL, ADD vocale_duree INT DEFAULT NULL, ADD vocale_empreinte VARCHAR(64) DEFAULT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['message_client', 'note_conversation'] as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP vocale_nom, DROP vocale_type, DROP vocale_taille, DROP vocale_duree, DROP vocale_empreinte', $table));
        }
    }
}
