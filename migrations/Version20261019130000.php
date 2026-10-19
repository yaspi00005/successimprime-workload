<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications de l'équipe à niveaux (demande de Yaya du 19/10/2026,
 * docs/notifications-equipe.md) : la table notifications gagne la sorte, le
 * niveau, le bouton d'action, l'empreinte de regroupement, le montant
 * protégé par un droit, le report, la résolution, les rappels et la
 * remontée. Les notifications existantes deviennent des « info ».
 */
final class Version20261019130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications de l\'équipe : niveaux, action, regroupement, report, résolution, remontée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE notifications ADD type VARCHAR(40) DEFAULT NULL, ADD niveau VARCHAR(10) DEFAULT 'info' NOT NULL, ADD detail VARCHAR(255) DEFAULT NULL, ADD action_libelle VARCHAR(40) DEFAULT NULL, ADD empreinte VARCHAR(100) DEFAULT NULL, ADD compteur INT DEFAULT 1 NOT NULL, ADD montant INT DEFAULT NULL, ADD droit_montant VARCHAR(60) DEFAULT NULL, ADD mise_a_jour_le DATETIME DEFAULT NULL, ADD reportee_jusqua DATETIME DEFAULT NULL, ADD resolue_le DATETIME DEFAULT NULL, ADD resolution VARCHAR(20) DEFAULT NULL, ADD alertee_le DATETIME DEFAULT NULL, ADD rappele_le DATETIME DEFAULT NULL, ADD escalade SMALLINT DEFAULT 0 NOT NULL, ADD escaladee_le DATETIME DEFAULT NULL, ADD silencieuse TINYINT DEFAULT 0 NOT NULL");
        $this->addSql('CREATE INDEX idx_notification_destinataire_resolue ON notifications (destinataire_id, resolue_le)');
        $this->addSql('CREATE INDEX idx_notification_empreinte ON notifications (empreinte)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_notification_destinataire_resolue ON notifications');
        $this->addSql('DROP INDEX idx_notification_empreinte ON notifications');
        $this->addSql('ALTER TABLE notifications DROP type, DROP niveau, DROP detail, DROP action_libelle, DROP empreinte, DROP compteur, DROP montant, DROP droit_montant, DROP mise_a_jour_le, DROP reportee_jusqua, DROP resolue_le, DROP resolution, DROP alertee_le, DROP rappele_le, DROP escalade, DROP escaladee_le, DROP silencieuse');
    }
}
