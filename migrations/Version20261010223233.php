<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010223233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock S01/S02 : familles d\'articles, unités et conversion, seuils max, emplacement, fournisseurs par article, coût moyen, quantités décimales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_fournisseur (id INT AUTO_INCREMENT NOT NULL, prix_achat INT DEFAULT NULL, delai_jours INT DEFAULT NULL, principal TINYINT DEFAULT 0 NOT NULL, reference_fournisseur VARCHAR(100) DEFAULT NULL, article_id INT NOT NULL, fournisseur_id INT NOT NULL, INDEX IDX_AC1DA0557294869C (article_id), INDEX IDX_AC1DA055670C757F (fournisseur_id), UNIQUE INDEX uniq_article_fournisseur (article_id, fournisseur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE famille_article (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, prefixe VARCHAR(10) NOT NULL, couleur VARCHAR(7) DEFAULT \'#1d4ed8\' NOT NULL, ordre INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_CE5004886C6E55B5 (nom), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE article_fournisseur ADD CONSTRAINT FK_AC1DA0557294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE article_fournisseur ADD CONSTRAINT FK_AC1DA055670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE articles ADD unite_achat VARCHAR(50) DEFAULT NULL, ADD quantite_par_unite_achat NUMERIC(14, 4) DEFAULT NULL, ADD longueur_unite_achat NUMERIC(10, 3) DEFAULT NULL, ADD laize NUMERIC(10, 3) DEFAULT NULL, ADD unite_vente VARCHAR(30) DEFAULT NULL, ADD stock_max NUMERIC(14, 3) DEFAULT NULL, ADD emplacement VARCHAR(120) DEFAULT NULL, ADD cout_moyen INT DEFAULT NULL, ADD famille_id INT DEFAULT NULL, ADD machine_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE articles ADD CONSTRAINT FK_BFDD316897A77B84 FOREIGN KEY (famille_id) REFERENCES famille_article (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE articles ADD CONSTRAINT FK_BFDD3168F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BFDD316897A77B84 ON articles (famille_id)');
        $this->addSql('CREATE INDEX IDX_BFDD3168F6B75B26 ON articles (machine_id)');
        $this->addSql('ALTER TABLE stock_entrees CHANGE quantites quantites NUMERIC(14, 3) NOT NULL');
        $this->addSql('ALTER TABLE stock_sorties CHANGE quantite quantite NUMERIC(14, 3) NOT NULL');

        // Familles de la maquette S01.
        $this->addSql("INSERT INTO famille_article (nom, prefixe, couleur, ordre) VALUES
            ('Supports', 'MAT', '#e30613', 1),
            ('Encres', 'ENC', '#db2777', 2),
            ('DTF', 'DTF', '#ec4899', 3),
            ('Textile', 'TEX', '#f97316', 4),
            ('Finition', 'FIN', '#1d4ed8', 5),
            ('Papier', 'PAP', '#0ea5e9', 6)");

        // Les anciennes catégories en texte deviennent des familles.
        $this->addSql("INSERT INTO famille_article (nom, prefixe, couleur, ordre)
            SELECT DISTINCT TRIM(a.categorie), UPPER(LEFT(TRIM(a.categorie), 3)), '#64748b', 50
            FROM articles a
            WHERE a.categorie IS NOT NULL AND TRIM(a.categorie) <> ''
              AND NOT EXISTS (SELECT 1 FROM famille_article f WHERE LOWER(f.nom) = LOWER(TRIM(a.categorie)))");
        $this->addSql('UPDATE articles a JOIN famille_article f ON LOWER(f.nom) = LOWER(TRIM(a.categorie)) SET a.famille_id = f.id');

        // Le fournisseur unique devient le fournisseur principal.
        $this->addSql('INSERT INTO article_fournisseur (article_id, fournisseur_id, prix_achat, principal)
            SELECT id, fournisseur_id, prix_achat, 1 FROM articles WHERE fournisseur_id IS NOT NULL');

        // Coût moyen de départ : moyenne des entrées pondérée par les quantités, sinon le prix d'achat.
        $this->addSql('UPDATE articles a SET a.cout_moyen = COALESCE(
            (SELECT ROUND(SUM(e.quantites * e.prix) / NULLIF(SUM(e.quantites), 0)) FROM stock_entrees e WHERE e.article_id = a.id AND e.prix > 0),
            a.prix_achat)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_fournisseur DROP FOREIGN KEY FK_AC1DA0557294869C');
        $this->addSql('ALTER TABLE article_fournisseur DROP FOREIGN KEY FK_AC1DA055670C757F');
        $this->addSql('DROP TABLE article_fournisseur');
        $this->addSql('ALTER TABLE articles DROP FOREIGN KEY FK_BFDD316897A77B84');
        $this->addSql('ALTER TABLE articles DROP FOREIGN KEY FK_BFDD3168F6B75B26');
        $this->addSql('DROP INDEX IDX_BFDD316897A77B84 ON articles');
        $this->addSql('DROP INDEX IDX_BFDD3168F6B75B26 ON articles');
        $this->addSql('ALTER TABLE articles DROP unite_achat, DROP quantite_par_unite_achat, DROP longueur_unite_achat, DROP laize, DROP unite_vente, DROP stock_max, DROP emplacement, DROP cout_moyen, DROP famille_id, DROP machine_id');
        $this->addSql('DROP TABLE famille_article');
        $this->addSql('ALTER TABLE stock_entrees CHANGE quantites quantites INT NOT NULL');
        $this->addSql('ALTER TABLE stock_sorties CHANGE quantite quantite INT NOT NULL');
    }
}
