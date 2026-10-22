<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assistant du site et de l'espace client (docs/assistant-client.md) :
 * conversations et messages. Créés en ligne, ils montent au local ; le
 * jeton du visiteur, le compte connecté, les échanges exacts avec l'API et
 * les boutons proposés restent en ligne.
 */
final class Version20261014190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assistant client : tables assistant_conversation et assistant_message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_conversation (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(20) DEFAULT NULL, contexte VARCHAR(10) NOT NULL, interlocuteur VARCHAR(120) DEFAULT NULL, page VARCHAR(255) DEFAULT NULL, modele VARCHAR(60) NOT NULL, debut_le DATETIME NOT NULL, derniere_le DATETIME NOT NULL, nombre_questions INT DEFAULT 0 NOT NULL, passage_agent_le DATETIME DEFAULT NULL, jeton VARCHAR(64) DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT DEFAULT NULL, compte_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_5BF3529BB5B48B91 (public_id), INDEX IDX_5BF3529B19EB6921 (client_id), INDEX IDX_5BF3529BF2C56620 (compte_id), INDEX idx_assistant_conversation_debut (debut_le), INDEX idx_assistant_conversation_jeton (jeton), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assistant_message (id INT AUTO_INCREMENT NOT NULL, role VARCHAR(10) NOT NULL, texte LONGTEXT NOT NULL, issue VARCHAR(12) DEFAULT NULL, outils VARCHAR(255) DEFAULT NULL, note SMALLINT DEFAULT NULL, modele VARCHAR(60) DEFAULT NULL, jetons_entree INT DEFAULT 0 NOT NULL, jetons_sortie INT DEFAULT 0 NOT NULL, jetons_cache_lecture INT DEFAULT 0 NOT NULL, jetons_cache_ecriture INT DEFAULT 0 NOT NULL, depense_usd NUMERIC(12, 6) DEFAULT \'0.000000\' NOT NULL, depense_fcfa NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, cree_le DATETIME NOT NULL, contenu_api JSON DEFAULT NULL, actions JSON DEFAULT NULL, public_id BINARY(16) NOT NULL, conversation_id INT NOT NULL, UNIQUE INDEX UNIQ_8A36E1EFB5B48B91 (public_id), INDEX IDX_8A36E1EF9AC0396 (conversation_id), INDEX idx_assistant_message_cree (cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529B19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assistant_conversation ADD CONSTRAINT FK_5BF3529BF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assistant_message ADD CONSTRAINT FK_8A36E1EF9AC0396 FOREIGN KEY (conversation_id) REFERENCES assistant_conversation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Les clés étrangères partent avant les tables.
        $this->addSql('ALTER TABLE assistant_message DROP FOREIGN KEY FK_8A36E1EF9AC0396');
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529B19EB6921');
        $this->addSql('ALTER TABLE assistant_conversation DROP FOREIGN KEY FK_5BF3529BF2C56620');
        $this->addSql('DROP TABLE assistant_message');
        $this->addSql('DROP TABLE assistant_conversation');
    }
}
