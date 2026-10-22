<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012115847 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications push : téléphones abonnés.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE abonnement_push (id INT AUTO_INCREMENT NOT NULL, adresse LONGTEXT NOT NULL, empreinte VARCHAR(64) NOT NULL, cle_publique VARCHAR(120) NOT NULL, secret VARCHAR(40) NOT NULL, appareil VARCHAR(120) DEFAULT NULL, cree_le DATETIME NOT NULL, dernier_envoi_le DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, INDEX IDX_2609353EFB88E14F (utilisateur_id), UNIQUE INDEX uniq_abonnement_push (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE abonnement_push ADD CONSTRAINT FK_2609353EFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE abonnement_push DROP FOREIGN KEY FK_2609353EFB88E14F');
        $this->addSql('DROP TABLE abonnement_push');
    }
}
