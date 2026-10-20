<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Production PD05-PD07 (docs/production-colis.md) : un colis par étiquette
 * (jeton du QR, code court, scan), le contrôle final d'une commande (photo,
 * vérifications, validé par), les pièces à refaire d'un ordre et la date
 * du passage « prête pour la livraison » d'une ligne (synchronisée).
 */
final class Version20261020234100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production : colis étiquetés, contrôle final, pièces à refaire, date « prête »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE colis_production (id INT AUTO_INCREMENT NOT NULL, serie SMALLINT DEFAULT 1 NOT NULL, rang SMALLINT NOT NULL, total SMALLINT NOT NULL, jeton VARCHAR(64) NOT NULL, code VARCHAR(50) NOT NULL, contenu JSON NOT NULL, emballage VARCHAR(12) NOT NULL, imprime_le DATETIME NOT NULL, scanne_le DATETIME DEFAULT NULL, annule_le DATETIME DEFAULT NULL, commande_id INT NOT NULL, imprime_par_id INT DEFAULT NULL, scanne_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4D3F999C2CF647B (jeton), UNIQUE INDEX UNIQ_4D3F999C77153098 (code), INDEX IDX_4D3F999C82EA2E54 (commande_id), INDEX IDX_4D3F999C2E58089 (imprime_par_id), INDEX IDX_4D3F999C2783DFB5 (scanne_par_id), INDEX idx_colis_production_commande (commande_id, annule_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE controle_final (id INT AUTO_INCREMENT NOT NULL, verifications JSON NOT NULL, valide_le DATETIME DEFAULT NULL, photo VARCHAR(120) DEFAULT NULL, photo_le DATETIME DEFAULT NULL, reprises SMALLINT DEFAULT 0 NOT NULL, modifie_le DATETIME NOT NULL, commande_id INT NOT NULL, valide_par_id INT DEFAULT NULL, photo_par_id INT DEFAULT NULL, INDEX IDX_7F9F9EA66AF12ED9 (valide_par_id), INDEX IDX_7F9F9EA6133BF182 (photo_par_id), UNIQUE INDEX uniq_controle_final_commande (commande_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE colis_production ADD CONSTRAINT FK_4D3F999C82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE colis_production ADD CONSTRAINT FK_4D3F999C2E58089 FOREIGN KEY (imprime_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE colis_production ADD CONSTRAINT FK_4D3F999C2783DFB5 FOREIGN KEY (scanne_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE controle_final ADD CONSTRAINT FK_7F9F9EA682EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE controle_final ADD CONSTRAINT FK_7F9F9EA66AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE controle_final ADD CONSTRAINT FK_7F9F9EA6133BF182 FOREIGN KEY (photo_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commandes_details ADD prete_livraison_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ordre_production ADD pieces_a_refaire INT DEFAULT NULL, ADD reimpressions SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE colis_production DROP FOREIGN KEY FK_4D3F999C82EA2E54');
        $this->addSql('ALTER TABLE colis_production DROP FOREIGN KEY FK_4D3F999C2E58089');
        $this->addSql('ALTER TABLE colis_production DROP FOREIGN KEY FK_4D3F999C2783DFB5');
        $this->addSql('ALTER TABLE controle_final DROP FOREIGN KEY FK_7F9F9EA682EA2E54');
        $this->addSql('ALTER TABLE controle_final DROP FOREIGN KEY FK_7F9F9EA66AF12ED9');
        $this->addSql('ALTER TABLE controle_final DROP FOREIGN KEY FK_7F9F9EA6133BF182');
        $this->addSql('DROP TABLE colis_production');
        $this->addSql('DROP TABLE controle_final');
        $this->addSql('ALTER TABLE commandes_details DROP prete_livraison_le');
        $this->addSql('ALTER TABLE ordre_production DROP pieces_a_refaire, DROP reimpressions');
    }
}
