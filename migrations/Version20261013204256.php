<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013204256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Finances : dépenses avec reçu, approbation et paiement, budgets mensuels par catégorie (tables locales, non synchronisées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE budget_depense (id INT AUTO_INCREMENT NOT NULL, categorie VARCHAR(20) NOT NULL, montant_mensuel INT NOT NULL, modifie_le DATETIME NOT NULL, modifie_par_id INT DEFAULT NULL, INDEX IDX_F686DDB6553B2554 (modifie_par_id), UNIQUE INDEX uniq_budget_depense_categorie (categorie), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE depense (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(150) NOT NULL, categorie VARCHAR(20) NOT NULL, montant INT NOT NULL, date_depense DATE NOT NULL, beneficiaire VARCHAR(100) DEFAULT NULL, lie_a VARCHAR(120) DEFAULT NULL, quantite NUMERIC(12, 3) DEFAULT NULL, echeance DATE DEFAULT NULL, justificatif VARCHAR(60) NOT NULL, statut VARCHAR(20) NOT NULL, note LONGTEXT DEFAULT NULL, cree_le DATETIME NOT NULL, approuvee_le DATETIME DEFAULT NULL, motif_refus VARCHAR(255) DEFAULT NULL, payee_le DATETIME DEFAULT NULL, fournisseur_id INT DEFAULT NULL, compte_id INT DEFAULT NULL, article_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, approuvee_par_id INT DEFAULT NULL, payee_par_id INT DEFAULT NULL, mouvement_id INT DEFAULT NULL, INDEX IDX_34059757670C757F (fournisseur_id), INDEX IDX_34059757F2C56620 (compte_id), INDEX IDX_340597577294869C (article_id), INDEX IDX_34059757FC29C013 (cree_par_id), INDEX IDX_34059757CFC9DB11 (approuvee_par_id), INDEX IDX_3405975768BD7452 (payee_par_id), UNIQUE INDEX UNIQ_34059757ECD1C222 (mouvement_id), INDEX idx_depense_date (date_depense), INDEX idx_depense_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE budget_depense ADD CONSTRAINT FK_F686DDB6553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_340597577294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757CFC9DB11 FOREIGN KEY (approuvee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_3405975768BD7452 FOREIGN KEY (payee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_34059757ECD1C222 FOREIGN KEY (mouvement_id) REFERENCES mouvement_tresorerie (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_depense DROP FOREIGN KEY FK_F686DDB6553B2554');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757670C757F');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757F2C56620');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_340597577294869C');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757FC29C013');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757CFC9DB11');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_3405975768BD7452');
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_34059757ECD1C222');
        $this->addSql('DROP TABLE budget_depense');
        $this->addSql('DROP TABLE depense');
    }
}
