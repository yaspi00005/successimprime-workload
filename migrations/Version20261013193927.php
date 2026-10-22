<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013193927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comptes entreprise : plusieurs utilisateurs par client avec leurs droits, adresses de livraison, bon de commande et validation interne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE adresse_livraison (id INT AUTO_INCREMENT NOT NULL, libelle VARCHAR(60) NOT NULL, adresse VARCHAR(255) NOT NULL, repere VARCHAR(160) DEFAULT NULL, contact VARCHAR(80) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, par_defaut TINYINT NOT NULL, actif TINYINT NOT NULL, client_id INT NOT NULL, INDEX IDX_B0B09C919EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE adresse_livraison ADD CONSTRAINT FK_B0B09C919EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE commandes ADD bon_commande VARCHAR(60) DEFAULT NULL, ADD validation_entreprise VARCHAR(12) DEFAULT NULL, ADD demandee_par VARCHAR(80) DEFAULT NULL, ADD validee_par VARCHAR(80) DEFAULT NULL, ADD motif_refus_entreprise VARCHAR(255) DEFAULT NULL, ADD compte_demandeur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE commandes ADD CONSTRAINT FK_35D4282CF98C6A3E FOREIGN KEY (compte_demandeur_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_35D4282CF98C6A3E ON commandes (compte_demandeur_id)');
        $this->addSql('ALTER TABLE compte_client DROP INDEX UNIQ_1DDD5D6219EB6921, ADD INDEX IDX_1DDD5D6219EB6921 (client_id)');
        $this->addSql('ALTER TABLE compte_client ADD nom VARCHAR(80) DEFAULT NULL, ADD droits JSON DEFAULT NULL, ADD ajoute_par_id INT DEFAULT NULL');
        // Les comptes déjà créés gardent tous les droits sur leur fiche.
        $this->addSql('UPDATE compte_client SET droits = \'["valideur","payeur","administrateur"]\' WHERE droits IS NULL');
        $this->addSql('ALTER TABLE compte_client MODIFY droits JSON NOT NULL');
        $this->addSql('ALTER TABLE compte_client ADD CONSTRAINT FK_1DDD5D62DAA76F43 FOREIGN KEY (ajoute_par_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1DDD5D62DAA76F43 ON compte_client (ajoute_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE adresse_livraison DROP FOREIGN KEY FK_B0B09C919EB6921');
        $this->addSql('DROP TABLE adresse_livraison');
        $this->addSql('ALTER TABLE commandes DROP FOREIGN KEY FK_35D4282CF98C6A3E');
        $this->addSql('DROP INDEX IDX_35D4282CF98C6A3E ON commandes');
        $this->addSql('ALTER TABLE commandes DROP bon_commande, DROP validation_entreprise, DROP demandee_par, DROP validee_par, DROP motif_refus_entreprise, DROP compte_demandeur_id');
        $this->addSql('ALTER TABLE compte_client DROP INDEX IDX_1DDD5D6219EB6921, ADD UNIQUE INDEX UNIQ_1DDD5D6219EB6921 (client_id)');
        $this->addSql('ALTER TABLE compte_client DROP FOREIGN KEY FK_1DDD5D62DAA76F43');
        $this->addSql('DROP INDEX IDX_1DDD5D62DAA76F43 ON compte_client');
        $this->addSql('ALTER TABLE compte_client DROP nom, DROP droits, DROP ajoute_par_id');
    }
}
