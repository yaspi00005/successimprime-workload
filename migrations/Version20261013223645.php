<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013223645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fidélité : relevé de points, compte fidélité par client, règles par article, promos de réveil des clients dormeurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compte_fidelite (id INT AUTO_INCREMENT NOT NULL, points_par_m2 NUMERIC(10, 2) DEFAULT NULL, taux_autre NUMERIC(5, 2) DEFAULT NULL, seuil_m2 NUMERIC(10, 2) DEFAULT NULL, seuil_montant INT DEFAULT NULL, motif_perso VARCHAR(255) DEFAULT NULL, perso_le DATETIME DEFAULT NULL, exclu TINYINT DEFAULT 0 NOT NULL, solde INT DEFAULT 0 NOT NULL, solde_f INT DEFAULT 0 NOT NULL, m2_cumules NUMERIC(12, 2) DEFAULT 0 NOT NULL, montant_cumule INT DEFAULT 0 NOT NULL, seuil_m2_applique NUMERIC(10, 2) DEFAULT 5 NOT NULL, seuil_montant_applique INT DEFAULT 25000 NOT NULL, points_par_m2_applique NUMERIC(10, 2) DEFAULT 100 NOT NULL, taux_autre_applique NUMERIC(5, 2) DEFAULT 2 NOT NULL, multiplicateur_jour SMALLINT DEFAULT 2 NOT NULL, delai_heures SMALLINT DEFAULT 72 NOT NULL, points_expirant INT DEFAULT 0 NOT NULL, expiration_prochaine DATETIME DEFAULT NULL, maj_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, perso_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6DF2C8DEB5B48B91 (public_id), UNIQUE INDEX UNIQ_6DF2C8DE19EB6921 (client_id), INDEX IDX_6DF2C8DE6E9EDB14 (perso_par_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_fidelite (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, points INT NOT NULL, valeur_point INT DEFAULT 1 NOT NULL, famille VARCHAR(10) DEFAULT NULL, m2 NUMERIC(12, 4) DEFAULT NULL, pieces NUMERIC(12, 4) DEFAULT NULL, montant INT DEFAULT NULL, multiplicateur SMALLINT DEFAULT NULL, regle VARCHAR(12) DEFAULT NULL, date DATETIME NOT NULL, expire_le DATETIME DEFAULT NULL, annule_le DATETIME DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, produit_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, ligne_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, cible_id INT DEFAULT NULL, agent_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6C5E1E31B5B48B91 (public_id), INDEX IDX_6C5E1E3119EB6921 (client_id), INDEX IDX_6C5E1E31F347EFB (produit_id), INDEX IDX_6C5E1E3182EA2E54 (commande_id), INDEX IDX_6C5E1E315A438E76 (ligne_id), INDEX IDX_6C5E1E312A4C4478 (paiement_id), INDEX IDX_6C5E1E31A96E5E09 (cible_id), INDEX IDX_6C5E1E313414710B (agent_id), INDEX idx_mouvement_fidelite_client_date (client_id, date), INDEX idx_mouvement_fidelite_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promo_reveil (id INT AUTO_INCREMENT NOT NULL, taux NUMERIC(5, 2) NOT NULL, debut DATE NOT NULL, fin DATE NOT NULL, jours_sans_commande INT DEFAULT NULL, cree_le DATETIME NOT NULL, utilisee_le DATETIME DEFAULT NULL, montant_remise INT DEFAULT NULL, annulee_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, cree_par_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4F0CE10FB5B48B91 (public_id), INDEX IDX_4F0CE10F19EB6921 (client_id), INDEX IDX_4F0CE10FFC29C013 (cree_par_id), INDEX IDX_4F0CE10F82EA2E54 (commande_id), INDEX idx_promo_reveil_client_fin (client_id, fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE regle_fidelite (id INT AUTO_INCREMENT NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, mode VARCHAR(12) NOT NULL, valeur NUMERIC(10, 2) DEFAULT 0 NOT NULL, valeur_point INT DEFAULT 1 NOT NULL, seuil NUMERIC(12, 2) DEFAULT 0 NOT NULL, portee VARCHAR(10) NOT NULL, debut DATE NOT NULL, fin DATE DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, modifie_le DATETIME NOT NULL, produit_id INT NOT NULL, modifie_par_id INT DEFAULT NULL, INDEX IDX_DF2723C3F347EFB (produit_id), INDEX IDX_DF2723C3553B2554 (modifie_par_id), INDEX idx_regle_fidelite_produit (produit_id, debut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE compte_fidelite ADD CONSTRAINT FK_6DF2C8DE19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compte_fidelite ADD CONSTRAINT FK_6DF2C8DE6E9EDB14 FOREIGN KEY (perso_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E3119EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E31F347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E3182EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E315A438E76 FOREIGN KEY (ligne_id) REFERENCES commandes_details (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E312A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E31A96E5E09 FOREIGN KEY (cible_id) REFERENCES mouvement_fidelite (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_fidelite ADD CONSTRAINT FK_6C5E1E313414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promo_reveil ADD CONSTRAINT FK_4F0CE10F19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promo_reveil ADD CONSTRAINT FK_4F0CE10FFC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promo_reveil ADD CONSTRAINT FK_4F0CE10F82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE regle_fidelite ADD CONSTRAINT FK_DF2723C3F347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE regle_fidelite ADD CONSTRAINT FK_DF2723C3553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compte_fidelite DROP FOREIGN KEY FK_6DF2C8DE19EB6921');
        $this->addSql('ALTER TABLE compte_fidelite DROP FOREIGN KEY FK_6DF2C8DE6E9EDB14');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E3119EB6921');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E31F347EFB');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E3182EA2E54');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E315A438E76');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E312A4C4478');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E31A96E5E09');
        $this->addSql('ALTER TABLE mouvement_fidelite DROP FOREIGN KEY FK_6C5E1E313414710B');
        $this->addSql('ALTER TABLE promo_reveil DROP FOREIGN KEY FK_4F0CE10F19EB6921');
        $this->addSql('ALTER TABLE promo_reveil DROP FOREIGN KEY FK_4F0CE10FFC29C013');
        $this->addSql('ALTER TABLE promo_reveil DROP FOREIGN KEY FK_4F0CE10F82EA2E54');
        $this->addSql('ALTER TABLE regle_fidelite DROP FOREIGN KEY FK_DF2723C3F347EFB');
        $this->addSql('ALTER TABLE regle_fidelite DROP FOREIGN KEY FK_DF2723C3553B2554');
        $this->addSql('DROP TABLE compte_fidelite');
        $this->addSql('DROP TABLE mouvement_fidelite');
        $this->addSql('DROP TABLE promo_reveil');
        $this->addSql('DROP TABLE regle_fidelite');
    }
}
