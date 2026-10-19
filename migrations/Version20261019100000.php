<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dossiers clients (maquettes DS01 à DS04 du 19/10/2026,
 * docs/dossiers-clients.md) : liaison entre un client et son dossier de
 * l'atelier, fichiers lus par l'analyse, fichiers « à classer » et
 * fichiers publiés pour le client. Aucun fichier de l'atelier n'est
 * stocké en base : seulement ce que l'analyse en a lu.
 */
final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossiers clients : lien_dossier_client, fichier_dossier, fichier_a_classer, fichier_publie';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fichier_a_classer (id INT AUTO_INCREMENT NOT NULL, chemin VARCHAR(700) NOT NULL, chemin_hash VARCHAR(40) NOT NULL, nom VARCHAR(255) NOT NULL, extension VARCHAR(12) NOT NULL, octets BIGINT NOT NULL, modifie_le DATETIME NOT NULL, signature VARCHAR(40) NOT NULL, origine VARCHAR(120) NOT NULL, origine_type VARCHAR(20) NOT NULL, raison VARCHAR(160) DEFAULT NULL, sous_dossier VARCHAR(40) DEFAULT NULL, confiance SMALLINT NOT NULL, nom_propose VARCHAR(255) DEFAULT NULL, miniature VARCHAR(60) DEFAULT NULL, etat VARCHAR(12) NOT NULL, apparu_le DATETIME NOT NULL, vu_le DATETIME NOT NULL, classe_le DATETIME DEFAULT NULL, chemin_final VARCHAR(700) DEFAULT NULL, client_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, classe_par_id INT DEFAULT NULL, INDEX IDX_ED9D349E19EB6921 (client_id), INDEX IDX_ED9D349E82EA2E54 (commande_id), INDEX IDX_ED9D349E6ECF4960 (classe_par_id), INDEX idx_fichier_a_classer_etat (etat, apparu_le), UNIQUE INDEX uniq_fichier_a_classer_chemin (chemin_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fichier_dossier (id INT AUTO_INCREMENT NOT NULL, sous_dossier VARCHAR(40) NOT NULL, chemin VARCHAR(700) NOT NULL, chemin_hash VARCHAR(40) NOT NULL, nom VARCHAR(255) NOT NULL, extension VARCHAR(12) NOT NULL, octets BIGINT NOT NULL, modifie_le DATETIME NOT NULL, signature VARCHAR(40) NOT NULL, dimensions VARCHAR(160) DEFAULT NULL, resume VARCHAR(160) DEFAULT NULL, miniature VARCHAR(60) DEFAULT NULL, base_version VARCHAR(191) NOT NULL, version SMALLINT DEFAULT 1 NOT NULL, commande_liee_le DATETIME DEFAULT NULL, ajoute_par VARCHAR(120) DEFAULT NULL, texte LONGTEXT DEFAULT NULL, vu_le DATETIME NOT NULL, absent TINYINT DEFAULT 0 NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, commande_liee_par_id INT DEFAULT NULL, INDEX IDX_E6DB1C0019EB6921 (client_id), INDEX IDX_E6DB1C0082EA2E54 (commande_id), INDEX IDX_E6DB1C00F96ECBAB (commande_liee_par_id), INDEX idx_fichier_dossier_sd (client_id, sous_dossier, absent), UNIQUE INDEX uniq_fichier_dossier_chemin (client_id, chemin_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fichier_publie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(255) NOT NULL, type VARCHAR(100) NOT NULL, taille INT NOT NULL, empreinte VARCHAR(64) NOT NULL, nom_stockage VARCHAR(80) NOT NULL, categorie VARCHAR(12) NOT NULL, publie_le DATETIME NOT NULL, retire_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, fichier_dossier_id INT DEFAULT NULL, publie_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_FAE26A02B5B48B91 (public_id), INDEX IDX_FAE26A0219EB6921 (client_id), INDEX IDX_FAE26A0282EA2E54 (commande_id), INDEX IDX_FAE26A02913793D7 (fichier_dossier_id), INDEX IDX_FAE26A02801A2092 (publie_par_id), INDEX idx_fichier_publie_client (client_id, retire_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lien_dossier_client (id INT AUTO_INCREMENT NOT NULL, nom_dossier VARCHAR(255) NOT NULL, etat VARCHAR(10) NOT NULL, score SMALLINT NOT NULL, nombre_fichiers INT DEFAULT 0 NOT NULL, marque_posee TINYINT DEFAULT 0 NOT NULL, absent TINYINT DEFAULT 0 NOT NULL, lie_le DATETIME DEFAULT NULL, maj_le DATETIME NOT NULL, client_id INT NOT NULL, lie_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F4925D3319EB6921 (client_id), INDEX IDX_F4925D336241ABFF (lie_par_id), INDEX idx_lien_dossier_etat (etat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fichier_a_classer ADD CONSTRAINT FK_ED9D349E19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_a_classer ADD CONSTRAINT FK_ED9D349E82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_a_classer ADD CONSTRAINT FK_ED9D349E6ECF4960 FOREIGN KEY (classe_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_dossier ADD CONSTRAINT FK_E6DB1C0019EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fichier_dossier ADD CONSTRAINT FK_E6DB1C0082EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_dossier ADD CONSTRAINT FK_E6DB1C00F96ECBAB FOREIGN KEY (commande_liee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_publie ADD CONSTRAINT FK_FAE26A0219EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fichier_publie ADD CONSTRAINT FK_FAE26A0282EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_publie ADD CONSTRAINT FK_FAE26A02913793D7 FOREIGN KEY (fichier_dossier_id) REFERENCES fichier_dossier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fichier_publie ADD CONSTRAINT FK_FAE26A02801A2092 FOREIGN KEY (publie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE lien_dossier_client ADD CONSTRAINT FK_F4925D3319EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lien_dossier_client ADD CONSTRAINT FK_F4925D336241ABFF FOREIGN KEY (lie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fichier_a_classer DROP FOREIGN KEY FK_ED9D349E19EB6921');
        $this->addSql('ALTER TABLE fichier_a_classer DROP FOREIGN KEY FK_ED9D349E82EA2E54');
        $this->addSql('ALTER TABLE fichier_a_classer DROP FOREIGN KEY FK_ED9D349E6ECF4960');
        $this->addSql('ALTER TABLE fichier_dossier DROP FOREIGN KEY FK_E6DB1C0019EB6921');
        $this->addSql('ALTER TABLE fichier_dossier DROP FOREIGN KEY FK_E6DB1C0082EA2E54');
        $this->addSql('ALTER TABLE fichier_dossier DROP FOREIGN KEY FK_E6DB1C00F96ECBAB');
        $this->addSql('ALTER TABLE fichier_publie DROP FOREIGN KEY FK_FAE26A0219EB6921');
        $this->addSql('ALTER TABLE fichier_publie DROP FOREIGN KEY FK_FAE26A0282EA2E54');
        $this->addSql('ALTER TABLE fichier_publie DROP FOREIGN KEY FK_FAE26A02913793D7');
        $this->addSql('ALTER TABLE fichier_publie DROP FOREIGN KEY FK_FAE26A02801A2092');
        $this->addSql('ALTER TABLE lien_dossier_client DROP FOREIGN KEY FK_F4925D3319EB6921');
        $this->addSql('ALTER TABLE lien_dossier_client DROP FOREIGN KEY FK_F4925D336241ABFF');
        $this->addSql('DROP TABLE fichier_a_classer');
        $this->addSql('DROP TABLE fichier_dossier');
        $this->addSql('DROP TABLE fichier_publie');
        $this->addSql('DROP TABLE lien_dossier_client');
    }
}
