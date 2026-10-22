<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Employés avec et sans accès (maquettes E01 à E05) : dossier (contrat,
 * paie, horaires propres), accès à l'application par invitation WhatsApp
 * et code PIN, tablette de l'entrée. Les comptes existants restent actifs.
 */
final class Version20261012020255 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Employés : dossier, horaires propres, accès par PIN et tablette de l\'entrée (E01-E05)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employes ADD contrat VARCHAR(20) DEFAULT NULL, ADD fin_prevue DATE DEFAULT NULL, ADD type_remuneration VARCHAR(20) DEFAULT NULL, ADD mode_paiement VARCHAR(20) DEFAULT NULL, ADD heure_debut VARCHAR(5) DEFAULT NULL, ADD heure_fin VARCHAR(5) DEFAULT NULL, ADD jours_travail VARCHAR(13) DEFAULT NULL, ADD contact_urgence VARCHAR(150) DEFAULT NULL, ADD responsable_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE employes ADD CONSTRAINT FK_A94BC0F053C59D72 FOREIGN KEY (responsable_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A94BC0F053C59D72 ON employes (responsable_id)');
        $this->addSql('ALTER TABLE point_pointage ADD tablette VARCHAR(64) DEFAULT NULL, ADD tablette_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD acces VARCHAR(12) DEFAULT \'actif\' NOT NULL, ADD pin VARCHAR(255) DEFAULT NULL, ADD pin_essais INT DEFAULT 0 NOT NULL, ADD pin_bloque_jusqua DATETIME DEFAULT NULL, ADD invitation_empreinte VARCHAR(64) DEFAULT NULL, ADD invitation_envoyee_le DATETIME DEFAULT NULL, ADD invitation_expire_le DATETIME DEFAULT NULL, ADD fin_acces_le DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employes DROP FOREIGN KEY FK_A94BC0F053C59D72');
        $this->addSql('DROP INDEX IDX_A94BC0F053C59D72 ON employes');
        $this->addSql('ALTER TABLE employes DROP contrat, DROP fin_prevue, DROP type_remuneration, DROP mode_paiement, DROP heure_debut, DROP heure_fin, DROP jours_travail, DROP contact_urgence, DROP responsable_id');
        $this->addSql('ALTER TABLE point_pointage DROP tablette, DROP tablette_le');
        $this->addSql('ALTER TABLE `user` DROP acces, DROP pin, DROP pin_essais, DROP pin_bloque_jusqua, DROP invitation_empreinte, DROP invitation_envoyee_le, DROP invitation_expire_le, DROP fin_acces_le');
    }
}
