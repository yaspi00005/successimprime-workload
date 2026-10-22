<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Atelier 3D (docs/atelier-3d.md) : articles proposés (modele_atelier,
 * réglés au local et envoyés au site) et modèles validés par les clients
 * (creation_atelier, écrits en ligne et montés au local), reliés à la
 * demande de devis du visiteur.
 */
final class Version20261014220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Atelier 3D : tables modele_atelier et creation_atelier, demande_devis.creation_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE creation_atelier (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) DEFAULT NULL, auteur VARCHAR(120) DEFAULT NULL, designation VARCHAR(255) NOT NULL, forme VARCHAR(20) NOT NULL, couleur JSON NOT NULL, couleur_visiere JSON DEFAULT NULL, tailles JSON NOT NULL, quantite INT NOT NULL, reglages JSON NOT NULL, nombre_commandes INT NOT NULL, cree_le DATETIME NOT NULL, derniere_commande_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT DEFAULT NULL, configuration_id INT DEFAULT NULL, rendu_devant_id INT DEFAULT NULL, rendu_dos_id INT DEFAULT NULL, image_devant_id INT DEFAULT NULL, image_dos_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C1BACA48B5B48B91 (public_id), INDEX IDX_C1BACA4819EB6921 (client_id), INDEX IDX_C1BACA4873F32DD8 (configuration_id), INDEX IDX_C1BACA48E7978E2F (rendu_devant_id), INDEX IDX_C1BACA48526BF3F (rendu_dos_id), INDEX IDX_C1BACA48B0406AA6 (image_devant_id), INDEX IDX_C1BACA48765241EB (image_dos_id), INDEX IDX_C1BACA4882EA2E54 (commande_id), INDEX idx_creation_atelier_cree (cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE modele_atelier (id INT AUTO_INCREMENT NOT NULL, forme VARCHAR(20) NOT NULL, couleurs JSON NOT NULL, tailles JSON NOT NULL, zone_largeur_cm DOUBLE PRECISION NOT NULL, zone_hauteur_cm DOUBLE PRECISION NOT NULL, actif TINYINT NOT NULL, ordre INT NOT NULL, public_id BINARY(16) NOT NULL, configuration_id INT NOT NULL, UNIQUE INDEX UNIQ_E436E2F1B5B48B91 (public_id), INDEX IDX_E436E2F173F32DD8 (configuration_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA4819EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA4873F32DD8 FOREIGN KEY (configuration_id) REFERENCES produit_configuration (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA48E7978E2F FOREIGN KEY (rendu_devant_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA48526BF3F FOREIGN KEY (rendu_dos_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA48B0406AA6 FOREIGN KEY (image_devant_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA48765241EB FOREIGN KEY (image_dos_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE creation_atelier ADD CONSTRAINT FK_C1BACA4882EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE modele_atelier ADD CONSTRAINT FK_E436E2F173F32DD8 FOREIGN KEY (configuration_id) REFERENCES produit_configuration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_devis ADD creation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE demande_devis ADD CONSTRAINT FK_7DF9460234FFA69A FOREIGN KEY (creation_id) REFERENCES creation_atelier (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7DF9460234FFA69A ON demande_devis (creation_id)');
    }

    public function down(Schema $schema): void
    {
        // Les clés étrangères partent avant les tables.
        $this->addSql('ALTER TABLE demande_devis DROP FOREIGN KEY FK_7DF9460234FFA69A');
        $this->addSql('DROP INDEX IDX_7DF9460234FFA69A ON demande_devis');
        $this->addSql('ALTER TABLE demande_devis DROP creation_id');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA4819EB6921');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA4873F32DD8');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA48E7978E2F');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA48526BF3F');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA48B0406AA6');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA48765241EB');
        $this->addSql('ALTER TABLE creation_atelier DROP FOREIGN KEY FK_C1BACA4882EA2E54');
        $this->addSql('ALTER TABLE modele_atelier DROP FOREIGN KEY FK_E436E2F173F32DD8');
        $this->addSql('DROP TABLE creation_atelier');
        $this->addSql('DROP TABLE modele_atelier');
    }
}
