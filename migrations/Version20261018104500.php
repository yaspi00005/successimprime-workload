<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Nos actions sociales » (docs/site-vitrine.md) : titre, date, lieu,
 * texte, photos (images du site), publication et ordre ; descendent vers
 * le serveur en ligne (site_action_sociale). La partie reste cachée tant
 * qu'aucune action publiée n'a un texte et une photo.
 *
 * « Nous soutenir » : la carte du tableau de bord de l'espace client se
 * ferme pour chaque personne (compte_client.soutien_masque_le, en ligne
 * seulement). Le lien de l'avis Google est un réglage du site
 * (site_reglage, clé « soutien ») : pas de colonne.
 */
final class Version20261018104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actions sociales du site vitrine et carte « Nous soutenir » fermée par personne dans l\'espace client';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_action_sociale (id INT AUTO_INCREMENT NOT NULL, titre VARCHAR(160) NOT NULL, date_action DATE NOT NULL, lieu VARCHAR(120) DEFAULT NULL, texte LONGTEXT DEFAULT NULL, publie TINYINT NOT NULL, ordre INT NOT NULL, modifie_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_7835CD1B5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site_action_sociale_image (action_sociale_id INT NOT NULL, image_site_id INT NOT NULL, INDEX IDX_91270AD35454819C (action_sociale_id), INDEX IDX_91270AD363AD419A (image_site_id), PRIMARY KEY (action_sociale_id, image_site_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE site_action_sociale_image ADD CONSTRAINT FK_91270AD35454819C FOREIGN KEY (action_sociale_id) REFERENCES site_action_sociale (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_action_sociale_image ADD CONSTRAINT FK_91270AD363AD419A FOREIGN KEY (image_site_id) REFERENCES site_image (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE compte_client ADD soutien_masque_le DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_action_sociale_image DROP FOREIGN KEY FK_91270AD35454819C');
        $this->addSql('ALTER TABLE site_action_sociale_image DROP FOREIGN KEY FK_91270AD363AD419A');
        $this->addSql('DROP TABLE site_action_sociale_image');
        $this->addSql('DROP TABLE site_action_sociale');
        $this->addSql('ALTER TABLE compte_client DROP soutien_masque_le');
    }
}
