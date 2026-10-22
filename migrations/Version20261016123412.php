<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Site vitrine : « Notre équipe » de la page « Qui sommes-nous ? »
 * (Réglages › Site vitrine › Équipe). Une ligne par personne : nom
 * affiché, poste, photo (image du site), ordre, visible, accord de la
 * personne. Aucun lien vers le personnel. Descend au site par la
 * synchronisation (type « site_equipe »).
 */
final class Version20261016123412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Site vitrine : équipe de la page « Qui sommes-nous ? » (nom, poste, photo, accord de la personne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_equipe (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(60) NOT NULL, poste VARCHAR(80) NOT NULL, ordre INT NOT NULL, visible TINYINT NOT NULL, accord_personne TINYINT NOT NULL, public_id BINARY(16) NOT NULL, photo_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_54E7144AB5B48B91 (public_id), INDEX IDX_54E7144A7E9E4C8C (photo_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE site_equipe ADD CONSTRAINT FK_54E7144A7E9E4C8C FOREIGN KEY (photo_id) REFERENCES site_image (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Les portraits ne servent qu'à l'équipe : ils partent avec elle.
        $this->addSql("DELETE i FROM site_image i JOIN site_equipe e ON e.photo_id = i.id WHERE i.usage_image = 'portrait'");
        $this->addSql('ALTER TABLE site_equipe DROP FOREIGN KEY FK_54E7144A7E9E4C8C');
        $this->addSql('DROP TABLE site_equipe');
    }
}
