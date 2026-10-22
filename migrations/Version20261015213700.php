<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Codes promo (docs/promotions.md, « Codes promo ») : codes générés pour
 * une promotion (un code partagé limité, ou un lot de codes uniques), avec
 * expiration et limites ; avantage sur la commande (pourcentage ou montant,
 * minimum de commande) ; code retenu sur les devis et commandes, et sa part
 * sur chaque ligne (remise_code_promo, hors TVA, déjà dans les totaux).
 */
final class Version20261015213700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promotions : codes promo générés, avantage sur la commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE code_promo (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) DEFAULT NULL, empreinte CHAR(64) NOT NULL, type VARCHAR(8) NOT NULL, lot VARCHAR(12) DEFAULT NULL, debut DATE DEFAULT NULL, expiration DATE NOT NULL, utilisations_max INT DEFAULT 1 NOT NULL, utilisations_par_client INT DEFAULT 1 NOT NULL, utilisations INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, desactive_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, promotion_id INT NOT NULL, cree_par_id INT DEFAULT NULL, desactive_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_5C4683B777153098 (code), UNIQUE INDEX UNIQ_5C4683B7B5B48B91 (public_id), INDEX IDX_5C4683B7139DF194 (promotion_id), INDEX IDX_5C4683B7FC29C013 (cree_par_id), INDEX IDX_5C4683B730B5FE9C (desactive_par_id), INDEX idx_code_promo_empreinte (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE code_promo ADD CONSTRAINT FK_5C4683B7139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE code_promo ADD CONSTRAINT FK_5C4683B7FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE code_promo ADD CONSTRAINT FK_5C4683B730B5FE9C FOREIGN KEY (desactive_par_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE promotion ADD avantage_commande VARCHAR(12) DEFAULT NULL, ADD valeur_commande NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, ADD plafond_commande INT DEFAULT NULL, ADD minimum_commande INT DEFAULT NULL, ADD a_codes TINYINT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE commandes ADD code_saisi VARCHAR(30) DEFAULT NULL, ADD remise_code_promo INT DEFAULT 0 NOT NULL, ADD motif_code_promo VARCHAR(160) DEFAULT NULL, ADD code_promo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commandes ADD CONSTRAINT FK_35D4282C294102D4 FOREIGN KEY (code_promo_id) REFERENCES code_promo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_35D4282C294102D4 ON commandes (code_promo_id)');
        $this->addSql('ALTER TABLE commandes_details ADD remise_code_promo INT DEFAULT 0 NOT NULL');

        $this->addSql('ALTER TABLE devis ADD code_saisi VARCHAR(30) DEFAULT NULL, ADD remise_code_promo INT DEFAULT 0 NOT NULL, ADD motif_code_promo VARCHAR(160) DEFAULT NULL, ADD code_promo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE devis ADD CONSTRAINT FK_8B27C52B294102D4 FOREIGN KEY (code_promo_id) REFERENCES code_promo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8B27C52B294102D4 ON devis (code_promo_id)');
        $this->addSql('ALTER TABLE devis_details ADD remise_code_promo INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis_details DROP remise_code_promo');
        $this->addSql('ALTER TABLE devis DROP FOREIGN KEY FK_8B27C52B294102D4');
        $this->addSql('DROP INDEX IDX_8B27C52B294102D4 ON devis');
        $this->addSql('ALTER TABLE devis DROP code_saisi, DROP remise_code_promo, DROP motif_code_promo, DROP code_promo_id');

        $this->addSql('ALTER TABLE commandes_details DROP remise_code_promo');
        $this->addSql('ALTER TABLE commandes DROP FOREIGN KEY FK_35D4282C294102D4');
        $this->addSql('DROP INDEX IDX_35D4282C294102D4 ON commandes');
        $this->addSql('ALTER TABLE commandes DROP code_saisi, DROP remise_code_promo, DROP motif_code_promo, DROP code_promo_id');

        $this->addSql('ALTER TABLE promotion DROP avantage_commande, DROP valeur_commande, DROP plafond_commande, DROP minimum_commande, DROP a_codes');

        $this->addSql('ALTER TABLE code_promo DROP FOREIGN KEY FK_5C4683B7139DF194');
        $this->addSql('ALTER TABLE code_promo DROP FOREIGN KEY FK_5C4683B7FC29C013');
        $this->addSql('ALTER TABLE code_promo DROP FOREIGN KEY FK_5C4683B730B5FE9C');
        $this->addSql('DROP TABLE code_promo');
    }
}
