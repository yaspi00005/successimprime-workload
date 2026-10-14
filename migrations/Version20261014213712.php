<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Import des données de démarrage (docs/import-donnees.md) : journal des
 * imports, soldes d'ouverture, et la marque « solde d'ouverture » sur les
 * commandes (ce que le client devait au démarrage) et les dépenses (ce que
 * la boutique devait à un fournisseur). Rien de cela n'est une vente ni
 * une dépense du mois.
 */
final class Version20261014213712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Import de données : tables import_donnees et solde_ouverture, colonne solde_ouverture sur commandes et depense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE import_donnees (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, nom_fichier VARCHAR(255) NOT NULL, empreinte VARCHAR(64) NOT NULL, chemin VARCHAR(255) DEFAULT NULL, taille INT NOT NULL, lignes INT DEFAULT 0 NOT NULL, crees INT DEFAULT 0 NOT NULL, mis_a_jour INT DEFAULT 0 NOT NULL, ignores INT DEFAULT 0 NOT NULL, erreurs INT DEFAULT 0 NOT NULL, resultat VARCHAR(10) NOT NULL, message LONGTEXT DEFAULT NULL, par_nom VARCHAR(120) NOT NULL, le DATETIME NOT NULL, options JSON NOT NULL, details JSON NOT NULL, annule_le DATETIME DEFAULT NULL, par_id INT DEFAULT NULL, annule_par_id INT DEFAULT NULL, INDEX IDX_6A465C58468486AA (par_id), INDEX IDX_6A465C58F376B95 (annule_par_id), INDEX idx_import_donnees_empreinte (empreinte), INDEX idx_import_donnees_le (le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE solde_ouverture (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, montant INT NOT NULL, date_ouverture DATE NOT NULL, echeance DATE DEFAULT NULL, tiers VARCHAR(150) NOT NULL, ancien_solde_initial INT DEFAULT NULL, note LONGTEXT DEFAULT NULL, cree_le DATETIME NOT NULL, annule_le DATETIME DEFAULT NULL, import_id INT DEFAULT NULL, client_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, compte_id INT DEFAULT NULL, depense_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, INDEX IDX_69755185B6A263D9 (import_id), INDEX IDX_6975518519EB6921 (client_id), UNIQUE INDEX UNIQ_6975518582EA2E54 (commande_id), INDEX IDX_69755185F2C56620 (compte_id), UNIQUE INDEX UNIQ_6975518541D81563 (depense_id), INDEX IDX_69755185FC29C013 (cree_par_id), INDEX idx_solde_ouverture_date (date_ouverture), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE import_donnees ADD CONSTRAINT FK_6A465C58468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE import_donnees ADD CONSTRAINT FK_6A465C58F376B95 FOREIGN KEY (annule_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_69755185B6A263D9 FOREIGN KEY (import_id) REFERENCES import_donnees (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_6975518519EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_6975518582EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_69755185F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_6975518541D81563 FOREIGN KEY (depense_id) REFERENCES depense (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE solde_ouverture ADD CONSTRAINT FK_69755185FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE commandes ADD solde_ouverture TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE depense ADD solde_ouverture TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Les clés étrangères partent avant les tables.
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_69755185B6A263D9');
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_6975518519EB6921');
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_6975518582EA2E54');
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_69755185F2C56620');
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_6975518541D81563');
        $this->addSql('ALTER TABLE solde_ouverture DROP FOREIGN KEY FK_69755185FC29C013');
        $this->addSql('ALTER TABLE import_donnees DROP FOREIGN KEY FK_6A465C58468486AA');
        $this->addSql('ALTER TABLE import_donnees DROP FOREIGN KEY FK_6A465C58F376B95');
        $this->addSql('DROP TABLE solde_ouverture');
        $this->addSql('DROP TABLE import_donnees');
        $this->addSql('ALTER TABLE commandes DROP solde_ouverture');
        $this->addSql('ALTER TABLE depense DROP solde_ouverture');
    }
}
