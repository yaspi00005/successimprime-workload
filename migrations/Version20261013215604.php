<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sondages et satisfaction client (docs/sondages.md) : sondages globaux et
 * libres, invitations au sondage de commande envoyées à la livraison,
 * réponses des clients et notes par article.
 */
final class Version20261013215604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sondages et satisfaction client : sondages, invitations, réponses et notes par article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation_sondage (id INT AUTO_INCREMENT NOT NULL, jeton VARCHAR(24) NOT NULL, cree_le DATETIME NOT NULL, sms_envoye_le DATETIME DEFAULT NULL, whatsapp_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, commande_id INT NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_E84E6EA2CF647B (jeton), UNIQUE INDEX UNIQ_E84E6EAB5B48B91 (public_id), INDEX IDX_E84E6EA19EB6921 (client_id), UNIQUE INDEX uniq_invitation_sondage_commande (commande_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note_article_sondage (id INT AUTO_INCREMENT NOT NULL, designation VARCHAR(160) NOT NULL, note SMALLINT NOT NULL, reponse_id INT NOT NULL, ligne_id INT DEFAULT NULL, INDEX IDX_5D7AF458CF18BB82 (reponse_id), INDEX IDX_5D7AF4585A438E76 (ligne_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reponse_sondage (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, auteur VARCHAR(80) DEFAULT NULL, nom VARCHAR(80) DEFAULT NULL, telephone VARCHAR(20) DEFAULT NULL, canal VARCHAR(10) NOT NULL, note_globale SMALLINT DEFAULT NULL, nps SMALLINT DEFAULT NULL, note_delai SMALLINT DEFAULT NULL, note_accueil SMALLINT DEFAULT NULL, proposition LONGTEXT DEFAULT NULL, reponses JSON NOT NULL, cree_le DATETIME NOT NULL, theme VARCHAR(12) DEFAULT NULL, theme_manuel TINYINT NOT NULL, statut_proposition VARCHAR(12) DEFAULT NULL, alerte VARCHAR(12) DEFAULT NULL, alerte_note LONGTEXT DEFAULT NULL, alerte_traitee_par VARCHAR(80) DEFAULT NULL, alerte_traitee_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, sondage_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, client_id INT DEFAULT NULL, reclamation_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_FC7EB7A6B5B48B91 (public_id), INDEX IDX_FC7EB7A6BAF4AE56 (sondage_id), INDEX IDX_FC7EB7A682EA2E54 (commande_id), INDEX IDX_FC7EB7A619EB6921 (client_id), INDEX IDX_FC7EB7A62D6BA2D9 (reclamation_id), INDEX idx_reponse_sondage_type_date (type, cree_le), INDEX idx_reponse_sondage_alerte (alerte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sondage (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(10) NOT NULL, titre VARCHAR(120) NOT NULL, introduction VARCHAR(500) DEFAULT NULL, statut VARCHAR(12) NOT NULL, segment VARCHAR(20) NOT NULL, anonyme_autorise TINYINT NOT NULL, jeton VARCHAR(24) NOT NULL, questions JSON NOT NULL, destinataires INT DEFAULT NULL, ouvert_le DATETIME DEFAULT NULL, clos_le DATETIME DEFAULT NULL, cree_le DATETIME NOT NULL, cree_par VARCHAR(80) DEFAULT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_7579C89F2CF647B (jeton), UNIQUE INDEX UNIQ_7579C89FB5B48B91 (public_id), INDEX idx_sondage_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invitation_sondage ADD CONSTRAINT FK_E84E6EA82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invitation_sondage ADD CONSTRAINT FK_E84E6EA19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_article_sondage ADD CONSTRAINT FK_5D7AF458CF18BB82 FOREIGN KEY (reponse_id) REFERENCES reponse_sondage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_article_sondage ADD CONSTRAINT FK_5D7AF4585A438E76 FOREIGN KEY (ligne_id) REFERENCES commandes_details (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reponse_sondage ADD CONSTRAINT FK_FC7EB7A6BAF4AE56 FOREIGN KEY (sondage_id) REFERENCES sondage (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reponse_sondage ADD CONSTRAINT FK_FC7EB7A682EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reponse_sondage ADD CONSTRAINT FK_FC7EB7A619EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reponse_sondage ADD CONSTRAINT FK_FC7EB7A62D6BA2D9 FOREIGN KEY (reclamation_id) REFERENCES reclamation_client (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation_sondage DROP FOREIGN KEY FK_E84E6EA82EA2E54');
        $this->addSql('ALTER TABLE invitation_sondage DROP FOREIGN KEY FK_E84E6EA19EB6921');
        $this->addSql('ALTER TABLE note_article_sondage DROP FOREIGN KEY FK_5D7AF458CF18BB82');
        $this->addSql('ALTER TABLE note_article_sondage DROP FOREIGN KEY FK_5D7AF4585A438E76');
        $this->addSql('ALTER TABLE reponse_sondage DROP FOREIGN KEY FK_FC7EB7A6BAF4AE56');
        $this->addSql('ALTER TABLE reponse_sondage DROP FOREIGN KEY FK_FC7EB7A682EA2E54');
        $this->addSql('ALTER TABLE reponse_sondage DROP FOREIGN KEY FK_FC7EB7A619EB6921');
        $this->addSql('ALTER TABLE reponse_sondage DROP FOREIGN KEY FK_FC7EB7A62D6BA2D9');
        $this->addSql('DROP TABLE invitation_sondage');
        $this->addSql('DROP TABLE note_article_sondage');
        $this->addSql('DROP TABLE reponse_sondage');
        $this->addSql('DROP TABLE sondage');
    }
}
