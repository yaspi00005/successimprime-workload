<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012115121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planning : rappels WhatsApp à envoyer (étape 2).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE rappel_whatsapp (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(10) NOT NULL, telephone VARCHAR(30) NOT NULL, message LONGTEXT NOT NULL, cree_le DATETIME NOT NULL, envoye_le DATETIME DEFAULT NULL, tache_id INT NOT NULL, destinataire_id INT DEFAULT NULL, envoye_par_id INT DEFAULT NULL, INDEX IDX_2F450D82D2235D39 (tache_id), INDEX IDX_2F450D82A4F84F6E (destinataire_id), INDEX IDX_2F450D82D603292 (envoye_par_id), INDEX idx_rappel_whatsapp_envoye (envoye_le), UNIQUE INDEX uniq_rappel_whatsapp (tache_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE rappel_whatsapp ADD CONSTRAINT FK_2F450D82D2235D39 FOREIGN KEY (tache_id) REFERENCES tache (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE rappel_whatsapp ADD CONSTRAINT FK_2F450D82A4F84F6E FOREIGN KEY (destinataire_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE rappel_whatsapp ADD CONSTRAINT FK_2F450D82D603292 FOREIGN KEY (envoye_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE rappel_whatsapp DROP FOREIGN KEY FK_2F450D82D2235D39');
        $this->addSql('ALTER TABLE rappel_whatsapp DROP FOREIGN KEY FK_2F450D82A4F84F6E');
        $this->addSql('ALTER TABLE rappel_whatsapp DROP FOREIGN KEY FK_2F450D82D603292');
        $this->addSql('DROP TABLE rappel_whatsapp');
    }
}
