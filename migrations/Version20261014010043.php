<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014010043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site vitrine : pages de contenu, réalisations, avis clients, conseils, partenaires, images et réglages (synchronisés vers le site)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_article (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, slug VARCHAR(100) NOT NULL, rubrique VARCHAR(60) DEFAULT NULL, resume VARCHAR(300) NOT NULL, corps LONGTEXT NOT NULL, publie_le DATETIME NOT NULL, publie TINYINT NOT NULL, modifie_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F99EECAD989D9B62 (slug), UNIQUE INDEX UNIQ_F99EECADB5B48B91 (public_id), INDEX IDX_F99EECAD3DA5256D (image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_avis (id INT AUTO_INCREMENT NOT NULL, nom_affiche VARCHAR(80) NOT NULL, texte LONGTEXT NOT NULL, note SMALLINT NOT NULL, source VARCHAR(60) DEFAULT NULL, date_avis DATE NOT NULL, accord_client TINYINT NOT NULL, publie TINYINT NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_B40B1609B5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_image (id INT AUTO_INCREMENT NOT NULL, contenu MEDIUMTEXT NOT NULL, type VARCHAR(20) NOT NULL, largeur INT NOT NULL, hauteur INT NOT NULL, empreinte VARCHAR(64) NOT NULL, usage_image VARCHAR(10) NOT NULL, texte_alternatif VARCHAR(160) DEFAULT NULL, ordre INT NOT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_167D45AB5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_page (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(80) NOT NULL, titre VARCHAR(160) NOT NULL, accroche VARCHAR(255) DEFAULT NULL, description VARCHAR(300) DEFAULT NULL, corps LONGTEXT NOT NULL, publie TINYINT NOT NULL, ordre INT NOT NULL, modifie_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2F900BD9989D9B62 (slug), UNIQUE INDEX UNIQ_2F900BD9B5B48B91 (public_id), INDEX IDX_2F900BD93DA5256D (image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_partenaire (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(120) NOT NULL, lien VARCHAR(255) DEFAULT NULL, ordre INT NOT NULL, visible TINYINT NOT NULL, accord_obtenu TINYINT NOT NULL, public_id BINARY(16) NOT NULL, logo_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_12F62CB6B5B48B91 (public_id), INDEX IDX_12F62CB6F98F144A (logo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_realisation (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, categorie_ref VARCHAR(36) DEFAULT NULL, description LONGTEXT DEFAULT NULL, nom_client VARCHAR(160) DEFAULT NULL, accord_client TINYINT NOT NULL, publie TINYINT NOT NULL, date_realisation DATE NOT NULL, public_id BINARY(16) NOT NULL, categorie_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_18B5EBEB5B48B91 (public_id), INDEX IDX_18B5EBEBCF5E72D (categorie_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_realisation_image (realisation_id INT NOT NULL, image_site_id INT NOT NULL, INDEX IDX_D37CB061B685E551 (realisation_id), INDEX IDX_D37CB06163AD419A (image_site_id), PRIMARY KEY (realisation_id, image_site_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_reglage (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(60) NOT NULL, valeurs JSON NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_4195481841401D17 (cle), UNIQUE INDEX UNIQ_41954818B5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE site_article ADD CONSTRAINT FK_F99EECAD3DA5256D FOREIGN KEY (image_id) REFERENCES site_image (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE site_page ADD CONSTRAINT FK_2F900BD93DA5256D FOREIGN KEY (image_id) REFERENCES site_image (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE site_partenaire ADD CONSTRAINT FK_12F62CB6F98F144A FOREIGN KEY (logo_id) REFERENCES site_image (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE site_realisation ADD CONSTRAINT FK_18B5EBEBCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie_produit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE site_realisation_image ADD CONSTRAINT FK_D37CB061B685E551 FOREIGN KEY (realisation_id) REFERENCES site_realisation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_realisation_image ADD CONSTRAINT FK_D37CB06163AD419A FOREIGN KEY (image_site_id) REFERENCES site_image (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_article DROP FOREIGN KEY FK_F99EECAD3DA5256D');
        $this->addSql('ALTER TABLE site_page DROP FOREIGN KEY FK_2F900BD93DA5256D');
        $this->addSql('ALTER TABLE site_partenaire DROP FOREIGN KEY FK_12F62CB6F98F144A');
        $this->addSql('ALTER TABLE site_realisation DROP FOREIGN KEY FK_18B5EBEBCF5E72D');
        $this->addSql('ALTER TABLE site_realisation_image DROP FOREIGN KEY FK_D37CB061B685E551');
        $this->addSql('ALTER TABLE site_realisation_image DROP FOREIGN KEY FK_D37CB06163AD419A');
        $this->addSql('DROP TABLE site_article');
        $this->addSql('DROP TABLE site_avis');
        $this->addSql('DROP TABLE site_image');
        $this->addSql('DROP TABLE site_page');
        $this->addSql('DROP TABLE site_partenaire');
        $this->addSql('DROP TABLE site_realisation');
        $this->addSql('DROP TABLE site_realisation_image');
        $this->addSql('DROP TABLE site_reglage');
    }
}
