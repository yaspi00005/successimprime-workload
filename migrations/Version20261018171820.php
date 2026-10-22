<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Produits de l'application (maquettes PR01 à PR04, docs/produits.md) :
 * délai, machine et machine de secours, coût saisi à la main, visibilité
 * en ligne (avec son motif) sur la fiche produit, et la table des prix
 * négociés par client (prix_special).
 */
final class Version20261018171820 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Produits : délai, machines, coût saisi, visible en ligne ; table prix_special (prix négociés par client)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prix_special (id INT AUTO_INCREMENT NOT NULL, prix INT NOT NULL, fin_le DATE DEFAULT NULL, statut VARCHAR(10) DEFAULT \'actif\' NOT NULL, note VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, decide_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, configuration_id INT NOT NULL, cree_par_id INT DEFAULT NULL, decide_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C259E329B5B48B91 (public_id), INDEX IDX_C259E32919EB6921 (client_id), INDEX IDX_C259E32973F32DD8 (configuration_id), INDEX IDX_C259E329FC29C013 (cree_par_id), INDEX IDX_C259E3292E54F2F1 (decide_par_id), INDEX idx_prix_special_client (client_id, statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prix_special ADD CONSTRAINT FK_C259E32919EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prix_special ADD CONSTRAINT FK_C259E32973F32DD8 FOREIGN KEY (configuration_id) REFERENCES produit_configuration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prix_special ADD CONSTRAINT FK_C259E329FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE prix_special ADD CONSTRAINT FK_C259E3292E54F2F1 FOREIGN KEY (decide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produits ADD delai_heures INT DEFAULT NULL, ADD cout_revient_saisi INT DEFAULT NULL, ADD visible_en_ligne TINYINT DEFAULT 1 NOT NULL, ADD motif_hors_ligne VARCHAR(160) DEFAULT NULL, ADD machine_id INT DEFAULT NULL, ADD machine_secours_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE produits ADD CONSTRAINT FK_BE2DDF8CF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produits ADD CONSTRAINT FK_BE2DDF8C87482D7E FOREIGN KEY (machine_secours_id) REFERENCES machines (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BE2DDF8CF6B75B26 ON produits (machine_id)');
        $this->addSql('CREATE INDEX IDX_BE2DDF8C87482D7E ON produits (machine_secours_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produits DROP FOREIGN KEY FK_BE2DDF8CF6B75B26');
        $this->addSql('ALTER TABLE produits DROP FOREIGN KEY FK_BE2DDF8C87482D7E');
        $this->addSql('DROP INDEX IDX_BE2DDF8CF6B75B26 ON produits');
        $this->addSql('DROP INDEX IDX_BE2DDF8C87482D7E ON produits');
        $this->addSql('ALTER TABLE produits DROP delai_heures, DROP cout_revient_saisi, DROP visible_en_ligne, DROP motif_hors_ligne, DROP machine_id, DROP machine_secours_id');
        $this->addSql('DROP TABLE prix_special');
    }
}
