<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014142129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client (maquettes EC01 à EC05) : lecture des notifications, préférences et heures calmes, appareils connectés, version de session, fonction de la personne, messages client ⇄ atelier, changements proposés en ligne, nom du conseiller';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appareil_client (id INT AUTO_INCREMENT NOT NULL, empreinte VARCHAR(64) NOT NULL, libelle VARCHAR(40) NOT NULL, cree_le DATETIME NOT NULL, derniere_activite DATETIME NOT NULL, compte_id INT NOT NULL, INDEX IDX_8A65E8EFF2C56620 (compte_id), UNIQUE INDEX uniq_appareil_client (compte_id, empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_client (id INT AUTO_INCREMENT NOT NULL, sens VARCHAR(10) NOT NULL, auteur VARCHAR(80) DEFAULT NULL, texte LONGTEXT NOT NULL, cree_le DATETIME NOT NULL, traite_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, ecrit_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F063995AB5B48B91 (public_id), INDEX IDX_F063995A19EB6921 (client_id), INDEX IDX_F063995A82EA2E54 (commande_id), INDEX IDX_F063995AAAF6E29 (ecrit_par_id), INDEX idx_message_client_fil (client_id, cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_client_differee (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(64) NOT NULL, type VARCHAR(20) NOT NULL, canal VARCHAR(10) NOT NULL, message VARCHAR(300) NOT NULL, evenement_le DATETIME NOT NULL, prevu_le DATETIME NOT NULL, envoye_le DATETIME DEFAULT NULL, resultat VARCHAR(10) DEFAULT NULL, compte_id INT NOT NULL, INDEX IDX_D6C28650F2C56620 (compte_id), INDEX idx_notification_differee_prevu (envoye_le, prevu_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE notification_client_lue (id INT AUTO_INCREMENT NOT NULL, cle VARCHAR(64) NOT NULL, lue_le DATETIME NOT NULL, compte_id INT NOT NULL, INDEX IDX_DCAC5B4F2C56620 (compte_id), UNIQUE INDEX uniq_notification_client_lue (compte_id, cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE proposition_client (id INT AUTO_INCREMENT NOT NULL, champs JSON NOT NULL, auteur VARCHAR(80) DEFAULT NULL, statut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, traite_le DATETIME DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, traite_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_41298633B5B48B91 (public_id), INDEX IDX_4129863319EB6921 (client_id), INDEX IDX_41298633167FABE8 (traite_par_id), INDEX idx_proposition_client_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appareil_client ADD CONSTRAINT FK_8A65E8EFF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995AAAF6E29 FOREIGN KEY (ecrit_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE notification_client_differee ADD CONSTRAINT FK_D6C28650F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_client_lue ADD CONSTRAINT FK_DCAC5B4F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposition_client ADD CONSTRAINT FK_4129863319EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE proposition_client ADD CONSTRAINT FK_41298633167FABE8 FOREIGN KEY (traite_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE clients ADD conseiller VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE compte_client ADD fonction VARCHAR(60) DEFAULT NULL, ADD version_session INT DEFAULT 1 NOT NULL, ADD preferences_notifications JSON DEFAULT NULL, ADD notifications_lues_jusqua DATETIME DEFAULT NULL');
        // Nom du conseiller pour les clients déjà suivis (au local ; en ligne, il arrive par la synchronisation).
        $this->addSql("UPDATE clients c JOIN `user` u ON u.id = c.suivi_par_id JOIN employes e ON e.id = u.employe_id SET c.conseiller = NULLIF(TRIM(CONCAT(COALESCE(e.prenom, ''), ' ', COALESCE(e.nom, ''))), '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appareil_client DROP FOREIGN KEY FK_8A65E8EFF2C56620');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A19EB6921');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A82EA2E54');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995AAAF6E29');
        $this->addSql('ALTER TABLE notification_client_differee DROP FOREIGN KEY FK_D6C28650F2C56620');
        $this->addSql('ALTER TABLE notification_client_lue DROP FOREIGN KEY FK_DCAC5B4F2C56620');
        $this->addSql('ALTER TABLE proposition_client DROP FOREIGN KEY FK_4129863319EB6921');
        $this->addSql('ALTER TABLE proposition_client DROP FOREIGN KEY FK_41298633167FABE8');
        $this->addSql('DROP TABLE appareil_client');
        $this->addSql('DROP TABLE message_client');
        $this->addSql('DROP TABLE notification_client_differee');
        $this->addSql('DROP TABLE notification_client_lue');
        $this->addSql('DROP TABLE proposition_client');
        $this->addSql('ALTER TABLE clients DROP conseiller');
        $this->addSql('ALTER TABLE compte_client DROP fonction, DROP version_session, DROP preferences_notifications, DROP notifications_lues_jusqua');
    }
}
