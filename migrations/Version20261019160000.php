<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Panier de devis du site (docs/site-vitrine.md) : une ligne par produit
 * d'une demande à plusieurs produits (quantité, finitions, fichier, prix
 * indicatif montré) ; au local, la ligne du devis brouillon qui en est née.
 */
final class Version20261019160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Panier de devis : demande_devis_ligne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demande_devis_ligne (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, designation VARCHAR(255) DEFAULT NULL, largeur_cm NUMERIC(7, 1) DEFAULT NULL, hauteur_cm NUMERIC(7, 1) DEFAULT NULL, quantite INT DEFAULT NULL, quantite_m2 NUMERIC(9, 2) DEFAULT NULL, finitions JSON DEFAULT NULL, noms_finitions VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, prix_indicatif INT DEFAULT NULL, demande_id INT NOT NULL, configuration_id INT DEFAULT NULL, fichier_id INT DEFAULT NULL, devis_detail_id INT DEFAULT NULL, INDEX IDX_E0F499D380E95E18 (demande_id), INDEX IDX_E0F499D373F32DD8 (configuration_id), INDEX IDX_E0F499D3F915CFE (fichier_id), INDEX IDX_E0F499D3A903C9C1 (devis_detail_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE demande_devis_ligne ADD CONSTRAINT FK_E0F499D380E95E18 FOREIGN KEY (demande_id) REFERENCES demande_devis (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_devis_ligne ADD CONSTRAINT FK_E0F499D373F32DD8 FOREIGN KEY (configuration_id) REFERENCES produit_configuration (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis_ligne ADD CONSTRAINT FK_E0F499D3F915CFE FOREIGN KEY (fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_devis_ligne ADD CONSTRAINT FK_E0F499D3A903C9C1 FOREIGN KEY (devis_detail_id) REFERENCES devis_details (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_devis_ligne DROP FOREIGN KEY FK_E0F499D380E95E18');
        $this->addSql('ALTER TABLE demande_devis_ligne DROP FOREIGN KEY FK_E0F499D373F32DD8');
        $this->addSql('ALTER TABLE demande_devis_ligne DROP FOREIGN KEY FK_E0F499D3F915CFE');
        $this->addSql('ALTER TABLE demande_devis_ligne DROP FOREIGN KEY FK_E0F499D3A903C9C1');
        $this->addSql('DROP TABLE demande_devis_ligne');
    }
}
