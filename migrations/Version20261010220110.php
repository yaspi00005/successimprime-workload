<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010220110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiche employe minimale pour creer un utilisateur (nom, prenom, telephone)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employes CHANGE fonction fonction VARCHAR(255) DEFAULT NULL, CHANGE email email VARCHAR(255) DEFAULT NULL, CHANGE photos photos VARCHAR(255) DEFAULT NULL, CHANGE cin cin VARCHAR(255) DEFAULT NULL, CHANGE salaires salaires INT DEFAULT NULL, CHANGE date_naissances date_naissances DATE DEFAULT NULL, CHANGE matricules matricules VARCHAR(100) DEFAULT NULL, CHANGE date_embauches date_embauches DATE DEFAULT NULL, CHANGE adresses adresses VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE employes SET fonction = COALESCE(fonction, ''), email = COALESCE(email, ''), photos = COALESCE(photos, ''), cin = COALESCE(cin, ''), salaires = COALESCE(salaires, 0), date_naissances = COALESCE(date_naissances, '1970-01-01'), matricules = COALESCE(matricules, ''), date_embauches = COALESCE(date_embauches, CURDATE()), adresses = COALESCE(adresses, '')");
        $this->addSql('ALTER TABLE employes CHANGE fonction fonction VARCHAR(255) NOT NULL, CHANGE email email VARCHAR(255) NOT NULL, CHANGE photos photos VARCHAR(255) NOT NULL, CHANGE cin cin VARCHAR(255) NOT NULL, CHANGE salaires salaires INT NOT NULL, CHANGE date_naissances date_naissances DATE NOT NULL, CHANGE matricules matricules VARCHAR(100) NOT NULL, CHANGE date_embauches date_embauches DATE NOT NULL, CHANGE adresses adresses VARCHAR(255) NOT NULL');
    }
}
