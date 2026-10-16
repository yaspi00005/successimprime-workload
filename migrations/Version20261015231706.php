<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bandes d'annonces (docs/site-vitrine.md, « Annonces ») : texte court,
 * lien, style, cible (site, espace client, les deux), dates ; descendent
 * vers le serveur en ligne (site_annonce).
 * Suspension de l'accès à l'espace client (docs/espace-client.md) : une
 * personne (un numéro) ou tout le compte d'une entreprise, avec motif et
 * auteur, gardée après la levée ; seuls client, numéro et dates descendent.
 * Plusieurs interlocuteurs : la personne à qui s'adresse un devis.
 */
final class Version20261015231706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Annonces du site et de l\'espace client, suspension de l\'accès à l\'espace client, interlocuteur du devis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_annonce (id INT AUTO_INCREMENT NOT NULL, texte VARCHAR(200) NOT NULL, lien VARCHAR(255) DEFAULT NULL, libelle_lien VARCHAR(40) DEFAULT NULL, style VARCHAR(12) NOT NULL, cible VARCHAR(8) NOT NULL, debut DATE DEFAULT NULL, fin DATE DEFAULT NULL, publie TINYINT NOT NULL, ordre INT NOT NULL, modifie_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_DF1712EB5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE suspension_espace_client (id INT AUTO_INCREMENT NOT NULL, telephone VARCHAR(20) DEFAULT NULL, nom VARCHAR(120) DEFAULT NULL, motif VARCHAR(255) NOT NULL, suspendu_le DATETIME NOT NULL, levee_le DATETIME DEFAULT NULL, note_levee VARCHAR(255) DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, suspendu_par_id INT DEFAULT NULL, levee_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_46153A6AB5B48B91 (public_id), INDEX IDX_46153A6A19EB6921 (client_id), INDEX IDX_46153A6AA4030016 (suspendu_par_id), INDEX IDX_46153A6A207DFC20 (levee_par_id), INDEX idx_suspension_espace_client_levee (client_id, levee_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE suspension_espace_client ADD CONSTRAINT FK_46153A6A19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE suspension_espace_client ADD CONSTRAINT FK_46153A6AA4030016 FOREIGN KEY (suspendu_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE suspension_espace_client ADD CONSTRAINT FK_46153A6A207DFC20 FOREIGN KEY (levee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE devis ADD interlocuteur VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE suspension_espace_client DROP FOREIGN KEY FK_46153A6A19EB6921');
        $this->addSql('ALTER TABLE suspension_espace_client DROP FOREIGN KEY FK_46153A6AA4030016');
        $this->addSql('ALTER TABLE suspension_espace_client DROP FOREIGN KEY FK_46153A6A207DFC20');
        $this->addSql('DROP TABLE site_annonce');
        $this->addSql('DROP TABLE suspension_espace_client');
        $this->addSql('ALTER TABLE devis DROP interlocuteur');
    }
}
