<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rapports du jour, de la semaine et du mois par e-mail
 * (docs/rapports-mail.md) : historique des envois, une ligne par rapport
 * (clé unique « jour:2026-10-17 », pour ne jamais l'envoyer deux fois)
 * et une par essai, avec le résultat pour chaque destinataire. Table
 * locale, non synchronisée.
 */
final class Version20261018150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rapports par e-mail : table envoi_rapport (historique, un envoi par période)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE envoi_rapport (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, periode VARCHAR(12) NOT NULL, debut DATE NOT NULL, cle VARCHAR(30) DEFAULT NULL, declencheur VARCHAR(10) NOT NULL, statut VARCHAR(10) NOT NULL, demarre_le DATETIME NOT NULL, termine_le DATETIME DEFAULT NULL, lignes JSON NOT NULL, piece_jointe TINYINT NOT NULL, tentatives SMALLINT DEFAULT 1 NOT NULL, demande_par_id INT DEFAULT NULL, INDEX IDX_B42EFEE34C0C045 (demande_par_id), INDEX idx_envoi_rapport_demarre (demarre_le), UNIQUE INDEX uniq_envoi_rapport_cle (cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE envoi_rapport ADD CONSTRAINT FK_B42EFEE34C0C045 FOREIGN KEY (demande_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE envoi_rapport DROP FOREIGN KEY FK_B42EFEE34C0C045');
        $this->addSql('DROP TABLE envoi_rapport');
    }
}
