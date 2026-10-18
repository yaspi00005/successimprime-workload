<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messages par commande (maquettes MS01 à MS05, docs/messagerie-clients.md) :
 * une conversation par commande, devis ou question générale, avec sa
 * clôture, son avis et son chronomètre de réponse ; l'agent tel que le
 * client le voit (nom, métier, vignette) ; les notes internes ; sur chaque
 * message, la conversation, l'agent, le BAT ou la facture joints, le délai
 * de réponse et, au local, le retard imputé et la pénalité.
 *
 * Les messages déjà écrits sont rangés dans leur conversation par
 * app:messagerie:delais (au local), qui les fait redescendre.
 */
final class Version20261018173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Messages par commande : conversations, agents, notes internes, délais de réponse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_messagerie (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, metier VARCHAR(80) DEFAULT NULL, photo LONGTEXT DEFAULT NULL, maj_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, utilisateur_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_65AE2703B5B48B91 (public_id), UNIQUE INDEX UNIQ_65AE2703FB88E14F (utilisateur_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE conversation_client (id INT AUTO_INCREMENT NOT NULL, objet VARCHAR(120) NOT NULL, sujet VARCHAR(12) DEFAULT \'commande\' NOT NULL, statut VARCHAR(12) DEFAULT \'ouverte\' NOT NULL, cree_le DATETIME NOT NULL, dernier_message_le DATETIME DEFAULT NULL, cloture_proposee_le DATETIME DEFAULT NULL, cloture_proposee_par VARCHAR(80) DEFAULT NULL, cloturee_le DATETIME DEFAULT NULL, cloturee_par VARCHAR(80) DEFAULT NULL, rouverte_le DATETIME DEFAULT NULL, avis SMALLINT DEFAULT NULL, avis_le DATETIME DEFAULT NULL, lu_client_le DATETIME DEFAULT NULL, lu_atelier_le DATETIME DEFAULT NULL, participants JSON DEFAULT NULL, attente_depuis DATETIME DEFAULT NULL, premiere_reponse_le DATETIME DEFAULT NULL, escaladee_le DATETIME DEFAULT NULL, relancee_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, devis_id INT DEFAULT NULL, precedente_id INT DEFAULT NULL, assigne_a_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F588BE95B5B48B91 (public_id), INDEX IDX_F588BE9519EB6921 (client_id), INDEX IDX_F588BE9582EA2E54 (commande_id), INDEX IDX_F588BE9541DEFADA (devis_id), INDEX IDX_F588BE95562D9ABA (precedente_id), INDEX IDX_F588BE95BB1B0F33 (assigne_a_id), INDEX idx_conversation_client_statut (client_id, statut), INDEX idx_conversation_client_attente (attente_depuis), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE note_conversation (id INT AUTO_INCREMENT NOT NULL, texte LONGTEXT NOT NULL, mentions JSON DEFAULT NULL, cree_le DATETIME NOT NULL, conversation_id INT NOT NULL, auteur_id INT DEFAULT NULL, INDEX IDX_177AE8D19AC0396 (conversation_id), INDEX IDX_177AE8D160BB6FE6 (auteur_id), INDEX idx_note_conversation (conversation_id, cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agent_messagerie ADD CONSTRAINT FK_65AE2703FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_client ADD CONSTRAINT FK_F588BE9519EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_client ADD CONSTRAINT FK_F588BE9582EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_client ADD CONSTRAINT FK_F588BE9541DEFADA FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_client ADD CONSTRAINT FK_F588BE95562D9ABA FOREIGN KEY (precedente_id) REFERENCES conversation_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_client ADD CONSTRAINT FK_F588BE95BB1B0F33 FOREIGN KEY (assigne_a_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE note_conversation ADD CONSTRAINT FK_177AE8D19AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_conversation ADD CONSTRAINT FK_177AE8D160BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_client ADD delai_minutes INT DEFAULT NULL, ADD cible_minutes INT DEFAULT NULL, ADD retard_minutes INT DEFAULT NULL, ADD penalite INT DEFAULT 0 NOT NULL, ADD premiere TINYINT DEFAULT 0 NOT NULL, ADD conversation_id INT DEFAULT NULL, ADD agent_id INT DEFAULT NULL, ADD bat_id INT DEFAULT NULL, ADD facture_id INT DEFAULT NULL, ADD impute_a_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A9AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A3414710B FOREIGN KEY (agent_id) REFERENCES agent_messagerie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A2DF17AE6 FOREIGN KEY (bat_id) REFERENCES controle_pre_presse (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A7F2DEE08 FOREIGN KEY (facture_id) REFERENCES factures (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE message_client ADD CONSTRAINT FK_F063995A90D3743F FOREIGN KEY (impute_a_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F063995A9AC0396 ON message_client (conversation_id)');
        $this->addSql('CREATE INDEX IDX_F063995A3414710B ON message_client (agent_id)');
        $this->addSql('CREATE INDEX IDX_F063995A2DF17AE6 ON message_client (bat_id)');
        $this->addSql('CREATE INDEX IDX_F063995A7F2DEE08 ON message_client (facture_id)');
        $this->addSql('CREATE INDEX IDX_F063995A90D3743F ON message_client (impute_a_id)');
        $this->addSql('CREATE INDEX idx_message_client_conversation ON message_client (conversation_id, cree_le)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A9AC0396');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A3414710B');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A2DF17AE6');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A7F2DEE08');
        $this->addSql('ALTER TABLE message_client DROP FOREIGN KEY FK_F063995A90D3743F');
        $this->addSql('DROP INDEX IDX_F063995A9AC0396 ON message_client');
        $this->addSql('DROP INDEX IDX_F063995A3414710B ON message_client');
        $this->addSql('DROP INDEX IDX_F063995A2DF17AE6 ON message_client');
        $this->addSql('DROP INDEX IDX_F063995A7F2DEE08 ON message_client');
        $this->addSql('DROP INDEX IDX_F063995A90D3743F ON message_client');
        $this->addSql('DROP INDEX idx_message_client_conversation ON message_client');
        $this->addSql('ALTER TABLE message_client DROP delai_minutes, DROP cible_minutes, DROP retard_minutes, DROP penalite, DROP premiere, DROP conversation_id, DROP agent_id, DROP bat_id, DROP facture_id, DROP impute_a_id');
        $this->addSql('DROP TABLE note_conversation');
        $this->addSql('DROP TABLE conversation_client');
        $this->addSql('DROP TABLE agent_messagerie');
    }
}
