<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011204059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clients : types ONG et administration, suivi commercial, conditions commerciales, contacts et journal des modifications (maquettes K01 à K06)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contact_client (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, fonction VARCHAR(80) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, whatsapp TINYINT DEFAULT 1 NOT NULL, email VARCHAR(120) DEFAULT NULL, role VARCHAR(20) DEFAULT \'autre\' NOT NULL, client_id INT NOT NULL, INDEX IDX_57D633D419EB6921 (client_id), INDEX IDX_57D633D4450FF010 (telephone), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE modification_client (id INT AUTO_INCREMENT NOT NULL, le DATETIME NOT NULL, auteur VARCHAR(120) DEFAULT NULL, champ VARCHAR(60) NOT NULL, categorie VARCHAR(20) NOT NULL, avant VARCHAR(255) DEFAULT NULL, apres VARCHAR(255) DEFAULT NULL, depuis VARCHAR(20) NOT NULL, client_id INT NOT NULL, par_id INT DEFAULT NULL, INDEX IDX_B7E4E5DC19EB6921 (client_id), INDEX IDX_B7E4E5DC468486AA (par_id), INDEX IDX_B7E4E5DC19EB6921B549A383 (client_id, le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact_client ADD CONSTRAINT FK_57D633D419EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modification_client ADD CONSTRAINT FK_B7E4E5DC19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modification_client ADD CONSTRAINT FK_B7E4E5DC468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE clients ADD secteur VARCHAR(80) DEFAULT NULL, ADD origine VARCHAR(30) DEFAULT NULL, ADD delai_paiement VARCHAR(20) DEFAULT NULL, ADD remise_habituelle DOUBLE PRECISION DEFAULT NULL, ADD acompte INT DEFAULT NULL, ADD moyen_prefere VARCHAR(20) DEFAULT NULL, ADD entite_facturation VARCHAR(20) DEFAULT NULL, ADD commune VARCHAR(80) DEFAULT NULL, ADD quartier VARCHAR(80) DEFAULT NULL, ADD etiquettes JSON DEFAULT \'[]\' NOT NULL, ADD besoin LONGTEXT DEFAULT NULL, ADD suivi_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE clients ADD CONSTRAINT FK_C82E74E440FBF8 FOREIGN KEY (suivi_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C82E74E440FBF8 ON clients (suivi_par_id)');
        // Le client est suivi par l\'agent de sa dernière commande.
        $this->addSql('UPDATE clients c SET c.suivi_par_id = (SELECT m.agents_id FROM commandes m WHERE m.clients_id = c.id AND m.deleted = 0 ORDER BY m.id DESC LIMIT 1) WHERE c.suivi_par_id IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_client DROP FOREIGN KEY FK_57D633D419EB6921');
        $this->addSql('ALTER TABLE modification_client DROP FOREIGN KEY FK_B7E4E5DC19EB6921');
        $this->addSql('ALTER TABLE modification_client DROP FOREIGN KEY FK_B7E4E5DC468486AA');
        $this->addSql('DROP TABLE contact_client');
        $this->addSql('DROP TABLE modification_client');
        $this->addSql('ALTER TABLE clients DROP FOREIGN KEY FK_C82E74E440FBF8');
        $this->addSql('DROP INDEX IDX_C82E74E440FBF8 ON clients');
        $this->addSql('ALTER TABLE clients DROP secteur, DROP origine, DROP delai_paiement, DROP remise_habituelle, DROP acompte, DROP moyen_prefere, DROP entite_facturation, DROP commune, DROP quartier, DROP etiquettes, DROP besoin, DROP suivi_par_id');
    }
}
