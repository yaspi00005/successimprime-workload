<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corbeille (docs/corbeille.md) : ce qui est supprimé y reste le temps réglé
 * (30 jours par défaut) avant d'être effacé pour de bon par
 * app:corbeille:purger. Une ligne par fiche supprimée, regroupées par
 * suppression ; les colonnes de la fiche sont gardées en JSON.
 */
final class Version20261018153700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corbeille : table des fiches supprimées, gardées 30 jours';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE corbeille (id INT AUTO_INCREMENT NOT NULL, groupe VARCHAR(36) NOT NULL, racine TINYINT NOT NULL, ordre INT NOT NULL, table_nom VARCHAR(64) NOT NULL, ligne_id VARCHAR(190) NOT NULL, type VARCHAR(80) NOT NULL, libelle VARCHAR(255) NOT NULL, donnees JSON NOT NULL, liens JSON DEFAULT NULL, mode VARCHAR(10) NOT NULL, restauration JSON DEFAULT NULL, supprime_le DATETIME NOT NULL, supprime_par_nom VARCHAR(190) DEFAULT NULL, supprime_par_id INT DEFAULT NULL, INDEX IDX_1646B707ACC02199 (supprime_par_id), INDEX idx_corbeille_groupe (groupe), INDEX idx_corbeille_liste (racine, supprime_le), INDEX idx_corbeille_ligne (table_nom, ligne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE corbeille ADD CONSTRAINT FK_1646B707ACC02199 FOREIGN KEY (supprime_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corbeille DROP FOREIGN KEY FK_1646B707ACC02199');
        $this->addSql('DROP TABLE corbeille');
    }
}
