<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014141317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Patrimoine et primes : registre des immobilisations, dotations aux amortissements, relevés de compteurs, unités d\'usage des machines, barèmes, réglages et évaluations des primes (tables locales, non synchronisées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bareme_prime (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) NOT NULL, prime_cible INT NOT NULL, criteres JSON NOT NULL, modifie_le DATETIME NOT NULL, modifie_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_377F780E57698A6A (role), INDEX IDX_377F780E553B2554 (modifie_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dotation_amortissement (id INT AUTO_INCREMENT NOT NULL, mois DATE NOT NULL, montant INT NOT NULL, cumul INT NOT NULL, usage_mois DOUBLE PRECISION DEFAULT NULL, methode VARCHAR(10) NOT NULL, compte VARCHAR(8) NOT NULL, cree_le DATETIME NOT NULL, immobilisation_id INT NOT NULL, cree_par_id INT DEFAULT NULL, INDEX IDX_18F6EE9988E7A58 (immobilisation_id), INDEX IDX_18F6EE9FC29C013 (cree_par_id), UNIQUE INDEX uniq_dotation_bien_mois (immobilisation_id, mois), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evaluation_prime (id INT AUTO_INCREMENT NOT NULL, mois DATE NOT NULL, version SMALLINT NOT NULL, statut VARCHAR(10) NOT NULL, role VARCHAR(20) NOT NULL, score SMALLINT DEFAULT NULL, taux SMALLINT NOT NULL, prime_cible INT NOT NULL, prime INT NOT NULL, criteres JSON NOT NULL, appreciation_note SMALLINT DEFAULT NULL, appreciation_texte VARCHAR(255) DEFAULT NULL, suspendue TINYINT DEFAULT 0 NOT NULL, motif_suspension VARCHAR(255) DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, validee_le DATETIME DEFAULT NULL, prime_validee_le DATETIME DEFAULT NULL, prime_reportee INT DEFAULT 0 NOT NULL, agent_id INT NOT NULL, cree_par_id INT DEFAULT NULL, validee_par_id INT DEFAULT NULL, prime_validee_par_id INT DEFAULT NULL, bulletin_id INT DEFAULT NULL, INDEX IDX_91BC72A63414710B (agent_id), INDEX IDX_91BC72A6FC29C013 (cree_par_id), INDEX IDX_91BC72A6629A7BB2 (validee_par_id), INDEX IDX_91BC72A698D63698 (prime_validee_par_id), INDEX IDX_91BC72A6D1AAB236 (bulletin_id), INDEX idx_evaluation_mois (mois), UNIQUE INDEX uniq_evaluation_agent_mois_version (agent_id, mois, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE immobilisation (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, designation VARCHAR(150) NOT NULL, categorie VARCHAR(20) NOT NULL, lieu VARCHAR(80) DEFAULT NULL, date_achat DATE DEFAULT NULL, date_mise_service DATE DEFAULT NULL, valeur_achat INT NOT NULL, valeur_residuelle INT NOT NULL, duree_annees SMALLINT DEFAULT NULL, quantite SMALLINT DEFAULT 1 NOT NULL, etat VARCHAR(15) NOT NULL, etat_note VARCHAR(80) DEFAULT NULL, fournisseur VARCHAR(120) DEFAULT NULL, numero_serie VARCHAR(80) DEFAULT NULL, note LONGTEXT DEFAULT NULL, controle_le DATE DEFAULT NULL, sortie_le DATE DEFAULT NULL, sortie_motif VARCHAR(10) DEFAULT NULL, prix_cession INT DEFAULT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, machine_id INT DEFAULT NULL, confie_a_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_B1563E2777153098 (code), UNIQUE INDEX UNIQ_B1563E27F6B75B26 (machine_id), INDEX IDX_B1563E27E6B6AEA3 (confie_a_id), INDEX idx_immobilisation_categorie (categorie), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE prime_agent (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(20) DEFAULT NULL, prime_cible INT DEFAULT NULL, modifie_le DATETIME NOT NULL, agent_id INT NOT NULL, modifie_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_18C886CF3414710B (agent_id), INDEX IDX_18C886CF553B2554 (modifie_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE releve_compteur (id INT AUTO_INCREMENT NOT NULL, mois DATE NOT NULL, a4_couleur INT DEFAULT NULL, a4_noir INT DEFAULT NULL, a3_couleur INT DEFAULT NULL, a3_noir INT DEFAULT NULL, compteur DOUBLE PRECISION DEFAULT NULL, photo VARCHAR(120) DEFAULT NULL, saisi_le DATETIME NOT NULL, machine_id INT NOT NULL, saisi_par_id INT DEFAULT NULL, INDEX IDX_F15DECBCF6B75B26 (machine_id), INDEX IDX_F15DECBCB0F809FE (saisi_par_id), UNIQUE INDEX uniq_releve_machine_mois (machine_id, mois), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bareme_prime ADD CONSTRAINT FK_377F780E553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE dotation_amortissement ADD CONSTRAINT FK_18F6EE9988E7A58 FOREIGN KEY (immobilisation_id) REFERENCES immobilisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dotation_amortissement ADD CONSTRAINT FK_18F6EE9FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation_prime ADD CONSTRAINT FK_91BC72A63414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation_prime ADD CONSTRAINT FK_91BC72A6FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation_prime ADD CONSTRAINT FK_91BC72A6629A7BB2 FOREIGN KEY (validee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation_prime ADD CONSTRAINT FK_91BC72A698D63698 FOREIGN KEY (prime_validee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation_prime ADD CONSTRAINT FK_91BC72A6D1AAB236 FOREIGN KEY (bulletin_id) REFERENCES bulletin_paie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE immobilisation ADD CONSTRAINT FK_B1563E27F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE immobilisation ADD CONSTRAINT FK_B1563E27E6B6AEA3 FOREIGN KEY (confie_a_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE prime_agent ADD CONSTRAINT FK_18C886CF3414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prime_agent ADD CONSTRAINT FK_18C886CF553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE releve_compteur ADD CONSTRAINT FK_F15DECBCF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE releve_compteur ADD CONSTRAINT FK_F15DECBCB0F809FE FOREIGN KEY (saisi_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE machines ADD unite_usage VARCHAR(10) DEFAULT NULL, ADD compteur_unites INT DEFAULT 0 NOT NULL, ADD releve_compteurs VARCHAR(8) DEFAULT NULL, ADD cout_page_couleur NUMERIC(8, 2) DEFAULT NULL, ADD cout_page_noir NUMERIC(8, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bareme_prime DROP FOREIGN KEY FK_377F780E553B2554');
        $this->addSql('ALTER TABLE dotation_amortissement DROP FOREIGN KEY FK_18F6EE9988E7A58');
        $this->addSql('ALTER TABLE dotation_amortissement DROP FOREIGN KEY FK_18F6EE9FC29C013');
        $this->addSql('ALTER TABLE evaluation_prime DROP FOREIGN KEY FK_91BC72A63414710B');
        $this->addSql('ALTER TABLE evaluation_prime DROP FOREIGN KEY FK_91BC72A6FC29C013');
        $this->addSql('ALTER TABLE evaluation_prime DROP FOREIGN KEY FK_91BC72A6629A7BB2');
        $this->addSql('ALTER TABLE evaluation_prime DROP FOREIGN KEY FK_91BC72A698D63698');
        $this->addSql('ALTER TABLE evaluation_prime DROP FOREIGN KEY FK_91BC72A6D1AAB236');
        $this->addSql('ALTER TABLE immobilisation DROP FOREIGN KEY FK_B1563E27F6B75B26');
        $this->addSql('ALTER TABLE immobilisation DROP FOREIGN KEY FK_B1563E27E6B6AEA3');
        $this->addSql('ALTER TABLE prime_agent DROP FOREIGN KEY FK_18C886CF3414710B');
        $this->addSql('ALTER TABLE prime_agent DROP FOREIGN KEY FK_18C886CF553B2554');
        $this->addSql('ALTER TABLE releve_compteur DROP FOREIGN KEY FK_F15DECBCF6B75B26');
        $this->addSql('ALTER TABLE releve_compteur DROP FOREIGN KEY FK_F15DECBCB0F809FE');
        $this->addSql('DROP TABLE bareme_prime');
        $this->addSql('DROP TABLE dotation_amortissement');
        $this->addSql('DROP TABLE evaluation_prime');
        $this->addSql('DROP TABLE immobilisation');
        $this->addSql('DROP TABLE prime_agent');
        $this->addSql('DROP TABLE releve_compteur');
        $this->addSql('ALTER TABLE machines DROP unite_usage, DROP compteur_unites, DROP releve_compteurs, DROP cout_page_couleur, DROP cout_page_noir');
    }
}
