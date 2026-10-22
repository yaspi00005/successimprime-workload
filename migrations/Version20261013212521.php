<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notifications aux clients (J5) : téléphones abonnés de l'espace client
 * et journal des notifications envoyées (une seule fois par événement).
 */
final class Version20261013212521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notifications aux clients : abonnements de l\'application et journal des envois';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE abonnement_push_client (id INT AUTO_INCREMENT NOT NULL, adresse LONGTEXT NOT NULL, empreinte VARCHAR(64) NOT NULL, cle_publique VARCHAR(120) NOT NULL, secret VARCHAR(40) NOT NULL, appareil VARCHAR(120) DEFAULT NULL, cree_le DATETIME NOT NULL, dernier_envoi_le DATETIME DEFAULT NULL, compte_id INT NOT NULL, INDEX IDX_1AD53954F2C56620 (compte_id), UNIQUE INDEX uniq_abonnement_push_client (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_client_envoyee (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(64) NOT NULL, type VARCHAR(20) NOT NULL, canal VARCHAR(10) NOT NULL, envoyee_le DATETIME NOT NULL, client_id INT NOT NULL, INDEX IDX_AD6AF56519EB6921 (client_id), UNIQUE INDEX uniq_notification_client (client_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE abonnement_push_client ADD CONSTRAINT FK_1AD53954F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_client_envoyee ADD CONSTRAINT FK_AD6AF56519EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE abonnement_push_client DROP FOREIGN KEY FK_1AD53954F2C56620');
        $this->addSql('ALTER TABLE notification_client_envoyee DROP FOREIGN KEY FK_AD6AF56519EB6921');
        $this->addSql('DROP TABLE abonnement_push_client');
        $this->addSql('DROP TABLE notification_client_envoyee');
    }
}
