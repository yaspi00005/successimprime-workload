<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Appareils autorisés pour l'administration en ligne (maquettes 38 et CX03).
 */
final class Version20261011021910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Appareils autorisés pour l\'administration en ligne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appareil_autorise (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(100) NOT NULL, role_en_ligne VARCHAR(20) NOT NULL, etat VARCHAR(20) NOT NULL, empreinte VARCHAR(64) DEFAULT NULL, cree_le DATETIME NOT NULL, expire_le DATETIME DEFAULT NULL, autorise_le DATETIME DEFAULT NULL, derniere_connexion DATETIME DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, navigateur VARCHAR(255) DEFAULT NULL, identifiant_tente VARCHAR(180) DEFAULT NULL, utilisateur_id INT DEFAULT NULL, cree_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_448EAD9132AF9675 (empreinte), INDEX IDX_448EAD91FB88E14F (utilisateur_id), INDEX IDX_448EAD91FC29C013 (cree_par_id), INDEX idx_appareil_etat (etat), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appareil_autorise ADD CONSTRAINT FK_448EAD91FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appareil_autorise ADD CONSTRAINT FK_448EAD91FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appareil_autorise DROP FOREIGN KEY FK_448EAD91FB88E14F');
        $this->addSql('ALTER TABLE appareil_autorise DROP FOREIGN KEY FK_448EAD91FC29C013');
        $this->addSql('DROP TABLE appareil_autorise');
    }
}
