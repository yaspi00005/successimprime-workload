<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010213113 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mes produits : produits du client et quantite demandee sur les lignes de commande';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE produit_client (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(30) NOT NULL, nom VARCHAR(255) NOT NULL, largeur NUMERIC(10, 4) DEFAULT NULL, hauteur NUMERIC(10, 4) DEFAULT NULL, unite_vente VARCHAR(20) DEFAULT \'piece\' NOT NULL, prix_unitaire INT NOT NULL, quantite_minimum INT DEFAULT 1 NOT NULL, taille_lot INT DEFAULT NULL, poses_par_feuille INT DEFAULT NULL, arrondi_superieur_impose TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, produit_id INT DEFAULT NULL, support_id INT DEFAULT NULL, machine_id INT DEFAULT NULL, fichier_id INT DEFAULT NULL, bat_valide_id INT DEFAULT NULL, ligne_origine_id INT DEFAULT NULL, remplace_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_3016CF6FAEA34913 (reference), UNIQUE INDEX UNIQ_3016CF6FB5B48B91 (public_id), INDEX IDX_3016CF6F19EB6921 (client_id), INDEX IDX_3016CF6FF347EFB (produit_id), INDEX IDX_3016CF6F315B405 (support_id), INDEX IDX_3016CF6FF6B75B26 (machine_id), INDEX IDX_3016CF6FF915CFE (fichier_id), INDEX IDX_3016CF6FD1975DA3 (bat_valide_id), INDEX IDX_3016CF6FE32B7793 (ligne_origine_id), INDEX IDX_3016CF6F3FAF8E69 (remplace_id), INDEX IDX_3016CF6F19EB69218F52502 (client_id, actif), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE produit_client_finition (produit_client_id INT NOT NULL, finition_id INT NOT NULL, INDEX IDX_2E8808F19D7C37CD (produit_client_id), INDEX IDX_2E8808F1CB56F5AF (finition_id), PRIMARY KEY (produit_client_id, finition_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6F19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id)');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6FF347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6F315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6FF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6FF915CFE FOREIGN KEY (fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6FD1975DA3 FOREIGN KEY (bat_valide_id) REFERENCES controle_pre_presse (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6FE32B7793 FOREIGN KEY (ligne_origine_id) REFERENCES commandes_details (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6F3FAF8E69 FOREIGN KEY (remplace_id) REFERENCES produit_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE produit_client_finition ADD CONSTRAINT FK_2E8808F19D7C37CD FOREIGN KEY (produit_client_id) REFERENCES produit_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE produit_client_finition ADD CONSTRAINT FK_2E8808F1CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commandes_details ADD quantite_demandee NUMERIC(12, 2) DEFAULT NULL, ADD unite_demandee VARCHAR(10) DEFAULT NULL, ADD produit_client_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commandes_details ADD CONSTRAINT FK_B48B83DA9D7C37CD FOREIGN KEY (produit_client_id) REFERENCES produit_client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B48B83DA9D7C37CD ON commandes_details (produit_client_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA9D7C37CD');
        $this->addSql('DROP INDEX IDX_B48B83DA9D7C37CD ON commandes_details');
        $this->addSql('ALTER TABLE commandes_details DROP quantite_demandee, DROP unite_demandee, DROP produit_client_id');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6F19EB6921');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6FF347EFB');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6F315B405');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6FF6B75B26');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6FF915CFE');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6FD1975DA3');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6FE32B7793');
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6F3FAF8E69');
        $this->addSql('ALTER TABLE produit_client_finition DROP FOREIGN KEY FK_2E8808F19D7C37CD');
        $this->addSql('ALTER TABLE produit_client_finition DROP FOREIGN KEY FK_2E8808F1CB56F5AF');
        $this->addSql('DROP TABLE produit_client');
        $this->addSql('DROP TABLE produit_client_finition');
    }
}
