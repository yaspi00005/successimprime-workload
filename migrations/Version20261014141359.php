<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014141359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promotions par segment de clients : promotions, exceptions B2B, anniversaires et leurs modèles, promotion portée par les lignes de devis et de commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE promotion (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, avantage VARCHAR(12) NOT NULL, valeur NUMERIC(12, 2) NOT NULL, debut DATE NOT NULL, fin DATE NOT NULL, segment VARCHAR(20) NOT NULL, quantite_min INT DEFAULT NULL, montant_min INT DEFAULT NULL, code VARCHAR(30) DEFAULT NULL, utilisations_par_client INT DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, origine VARCHAR(12) DEFAULT \'manuelle\' NOT NULL, note VARCHAR(255) DEFAULT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, categorie_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, modifie_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C11D7DD1B5B48B91 (public_id), INDEX IDX_C11D7DD1BCF5E72D (categorie_id), INDEX IDX_C11D7DD1FC29C013 (cree_par_id), INDEX IDX_C11D7DD1553B2554 (modifie_par_id), INDEX idx_promotion_periode (active, debut, fin), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_produit (promotion_id INT NOT NULL, produits_id INT NOT NULL, INDEX IDX_71D81A1D139DF194 (promotion_id), INDEX IDX_71D81A1DCD11A2CF (produits_id), PRIMARY KEY (promotion_id, produits_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_client (promotion_id INT NOT NULL, clients_id INT NOT NULL, INDEX IDX_D2FE2F68139DF194 (promotion_id), INDEX IDX_D2FE2F68AB014612 (clients_id), PRIMARY KEY (promotion_id, clients_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_anniversaire (id INT AUTO_INCREMENT NOT NULL, annees INT NOT NULL, date_anniversaire DATE NOT NULL, decision VARCHAR(10) DEFAULT NULL, decide_le DATETIME DEFAULT NULL, traite_le DATETIME DEFAULT NULL, canal VARCHAR(10) DEFAULT NULL, envoye_le DATETIME DEFAULT NULL, erreur VARCHAR(255) DEFAULT NULL, rappel_le DATETIME DEFAULT NULL, client_id INT NOT NULL, modele_id INT DEFAULT NULL, decide_par_id INT DEFAULT NULL, promotion_id INT DEFAULT NULL, INDEX IDX_3CDDBEE319EB6921 (client_id), INDEX IDX_3CDDBEE3AC14B70A (modele_id), INDEX IDX_3CDDBEE32E54F2F1 (decide_par_id), INDEX IDX_3CDDBEE3139DF194 (promotion_id), INDEX idx_anniversaire_date (date_anniversaire), UNIQUE INDEX uniq_anniversaire_client_annees (client_id, annees), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_anniversaire_modele (id INT AUTO_INCREMENT NOT NULL, annees INT NOT NULL, nom VARCHAR(120) NOT NULL, avantage VARCHAR(12) NOT NULL, valeur NUMERIC(12, 2) NOT NULL, validite_jours INT NOT NULL, texte_sms LONGTEXT NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, modifie_le DATETIME NOT NULL, categorie_id INT DEFAULT NULL, INDEX IDX_302150A5BCF5E72D (categorie_id), UNIQUE INDEX uniq_modele_anniversaire_annees (annees), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_anniversaire_modele_produit (modele_anniversaire_id INT NOT NULL, produits_id INT NOT NULL, INDEX IDX_F1446310D45C069 (modele_anniversaire_id), INDEX IDX_F1446310CD11A2CF (produits_id), PRIMARY KEY (modele_anniversaire_id, produits_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE promotion_exception (id INT AUTO_INCREMENT NOT NULL, statut VARCHAR(12) NOT NULL, remise_unitaire INT NOT NULL, remise_b2b INT NOT NULL, demande_le DATETIME NOT NULL, decide_le DATETIME DEFAULT NULL, reponse VARCHAR(255) DEFAULT NULL, promotion_id INT NOT NULL, ligne_commande_id INT DEFAULT NULL, ligne_devis_id INT DEFAULT NULL, client_id INT NOT NULL, demande_par_id INT DEFAULT NULL, decide_par_id INT DEFAULT NULL, INDEX IDX_EBE5F40139DF194 (promotion_id), INDEX IDX_EBE5F40E10FEE63 (ligne_commande_id), INDEX IDX_EBE5F401E44EBEC (ligne_devis_id), INDEX IDX_EBE5F4019EB6921 (client_id), INDEX IDX_EBE5F404C0C045 (demande_par_id), INDEX IDX_EBE5F402E54F2F1 (decide_par_id), INDEX idx_promotion_exception_statut (statut, demande_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE promotion ADD CONSTRAINT FK_C11D7DD1BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion ADD CONSTRAINT FK_C11D7DD1FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion ADD CONSTRAINT FK_C11D7DD1553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_produit ADD CONSTRAINT FK_71D81A1D139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_produit ADD CONSTRAINT FK_71D81A1DCD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_client ADD CONSTRAINT FK_D2FE2F68139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_client ADD CONSTRAINT FK_D2FE2F68AB014612 FOREIGN KEY (clients_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_anniversaire ADD CONSTRAINT FK_3CDDBEE319EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_anniversaire ADD CONSTRAINT FK_3CDDBEE3AC14B70A FOREIGN KEY (modele_id) REFERENCES promotion_anniversaire_modele (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_anniversaire ADD CONSTRAINT FK_3CDDBEE32E54F2F1 FOREIGN KEY (decide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_anniversaire ADD CONSTRAINT FK_3CDDBEE3139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele ADD CONSTRAINT FK_302150A5BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele_produit ADD CONSTRAINT FK_F1446310D45C069 FOREIGN KEY (modele_anniversaire_id) REFERENCES promotion_anniversaire_modele (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele_produit ADD CONSTRAINT FK_F1446310CD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F40139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F40E10FEE63 FOREIGN KEY (ligne_commande_id) REFERENCES commandes_details (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F401E44EBEC FOREIGN KEY (ligne_devis_id) REFERENCES devis_details (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F4019EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F404C0C045 FOREIGN KEY (demande_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE promotion_exception ADD CONSTRAINT FK_EBE5F402E54F2F1 FOREIGN KEY (decide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commandes_details ADD libelle_promotion VARCHAR(160) DEFAULT NULL, ADD remise_promotion INT DEFAULT 0 NOT NULL, ADD montant_promotion INT DEFAULT 0 NOT NULL, ADD promotion_retiree TINYINT DEFAULT 0 NOT NULL, ADD promotion_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commandes_details ADD CONSTRAINT FK_B48B83DA139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B48B83DA139DF194 ON commandes_details (promotion_id)');
        $this->addSql('ALTER TABLE devis_details ADD libelle_promotion VARCHAR(160) DEFAULT NULL, ADD remise_promotion INT DEFAULT 0 NOT NULL, ADD montant_promotion INT DEFAULT 0 NOT NULL, ADD promotion_retiree TINYINT DEFAULT 0 NOT NULL, ADD promotion_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE devis_details ADD CONSTRAINT FK_E0C890D6139DF194 FOREIGN KEY (promotion_id) REFERENCES promotion (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E0C890D6139DF194 ON devis_details (promotion_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promotion DROP FOREIGN KEY FK_C11D7DD1BCF5E72D');
        $this->addSql('ALTER TABLE promotion DROP FOREIGN KEY FK_C11D7DD1FC29C013');
        $this->addSql('ALTER TABLE promotion DROP FOREIGN KEY FK_C11D7DD1553B2554');
        $this->addSql('ALTER TABLE promotion_produit DROP FOREIGN KEY FK_71D81A1D139DF194');
        $this->addSql('ALTER TABLE promotion_produit DROP FOREIGN KEY FK_71D81A1DCD11A2CF');
        $this->addSql('ALTER TABLE promotion_client DROP FOREIGN KEY FK_D2FE2F68139DF194');
        $this->addSql('ALTER TABLE promotion_client DROP FOREIGN KEY FK_D2FE2F68AB014612');
        $this->addSql('ALTER TABLE promotion_anniversaire DROP FOREIGN KEY FK_3CDDBEE319EB6921');
        $this->addSql('ALTER TABLE promotion_anniversaire DROP FOREIGN KEY FK_3CDDBEE3AC14B70A');
        $this->addSql('ALTER TABLE promotion_anniversaire DROP FOREIGN KEY FK_3CDDBEE32E54F2F1');
        $this->addSql('ALTER TABLE promotion_anniversaire DROP FOREIGN KEY FK_3CDDBEE3139DF194');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele DROP FOREIGN KEY FK_302150A5BCF5E72D');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele_produit DROP FOREIGN KEY FK_F1446310D45C069');
        $this->addSql('ALTER TABLE promotion_anniversaire_modele_produit DROP FOREIGN KEY FK_F1446310CD11A2CF');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F40139DF194');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F40E10FEE63');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F401E44EBEC');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F4019EB6921');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F404C0C045');
        $this->addSql('ALTER TABLE promotion_exception DROP FOREIGN KEY FK_EBE5F402E54F2F1');
        $this->addSql('DROP TABLE promotion');
        $this->addSql('DROP TABLE promotion_produit');
        $this->addSql('DROP TABLE promotion_client');
        $this->addSql('DROP TABLE promotion_anniversaire');
        $this->addSql('DROP TABLE promotion_anniversaire_modele');
        $this->addSql('DROP TABLE promotion_anniversaire_modele_produit');
        $this->addSql('DROP TABLE promotion_exception');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA139DF194');
        $this->addSql('DROP INDEX IDX_B48B83DA139DF194 ON commandes_details');
        $this->addSql('ALTER TABLE commandes_details DROP libelle_promotion, DROP remise_promotion, DROP montant_promotion, DROP promotion_retiree, DROP promotion_id');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6139DF194');
        $this->addSql('DROP INDEX IDX_E0C890D6139DF194 ON devis_details');
        $this->addSql('ALTER TABLE devis_details DROP libelle_promotion, DROP remise_promotion, DROP montant_promotion, DROP promotion_retiree, DROP promotion_id');
    }
}
