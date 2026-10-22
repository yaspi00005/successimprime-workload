<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crédits SMS et e-mail (docs/messagerie-marketing.md, « Crédits SMS et
 * e-mail ») : historique des envois, une ligne par SMS ou e-mail tenté
 * (App\Entity\EnvoiMessage). Ni texte, ni code, ni lien : seulement la date,
 * le canal, le type, l'objet nettoyé, le destinataire, l'état et les unités.
 */
final class Version20261018093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crédits SMS et e-mail : table envoi_message (historique des envois)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE envoi_message (id INT AUTO_INCREMENT NOT NULL, canal VARCHAR(5) NOT NULL, type VARCHAR(30) NOT NULL, sujet VARCHAR(190) DEFAULT NULL, destinataire VARCHAR(190) NOT NULL, statut VARCHAR(10) NOT NULL, erreur VARCHAR(255) DEFAULT NULL, unites INT DEFAULT 0 NOT NULL, envoye_le DATETIME NOT NULL, origine VARCHAR(8) DEFAULT 'local' NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_BCA76E00B5B48B91 (public_id), INDEX idx_envoi_message_le (envoye_le), INDEX idx_envoi_message_canal_le (canal, envoye_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
    }

    public function down(Schema $schema): void
    {
        // Les lignes de synchronisation de ce type n'ont plus d'objet sans la table.
        $this->addSql("DELETE FROM synchro_journal WHERE type = 'envoi_message'");
        $this->addSql('DROP TABLE envoi_message');
    }
}
