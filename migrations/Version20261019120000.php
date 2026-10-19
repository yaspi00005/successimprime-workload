<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messagerie de l'équipe (demande de Yaya du 19/10/2026, maquettes MI01 à
 * MI05, docs/messagerie-interne.md). L'ancien chat interne (messages privés
 * à deux) devient la messagerie de l'équipe : ses conversations restent des
 * messages privés, ses pièces jointes passent dans messages_chat_fichier.
 *
 * - groupes (permanent, projet, annonces), membres par rôle, responsables ;
 * - fichiers, photos, liens vers le serveur, réactions, épingles, réponses,
 *   mentions, commandes citées, notes vocales ;
 * - droit « Messagerie de l'équipe » à tous les rôles ; « Créer des groupes »
 *   aux chefs d'atelier (la Direction a tous les droits) ;
 * - groupes Général, Atelier, Prépresse, Caisse et finances, avec leurs
 *   membres par rôle.
 */
final class Version20261019120000 extends AbstractMigration
{
    public const ACCES = 'ROLE_MESSAGERIE_INTERNE';
    public const GROUPES = 'ROLE_MESSAGERIE_GROUPES';

    /** Mêmes groupes que App\Service\MessagerieInterne\Groupes::PAR_DEFAUT. */
    private const PAR_DEFAUT = [
        'general' => ['Général', 'toute l\'imprimerie', 'bleu', ['ROLE_ADMIN', 'ROLE_CAISSE_COMMANDE', 'ROLE_GRAPHISTE', 'ROLE_PRODUCTION', 'ROLE_LIVREUR']],
        'atelier' => ['Atelier', 'groupe de travail', 'vert', ['ROLE_PRODUCTION', 'ROLE_GRAPHISTE', 'ROLE_LIVREUR']],
        'prepresse' => ['Prépresse', 'fichiers, BAT, corrections', 'rose', ['ROLE_GRAPHISTE', 'ROLE_PRODUCTION']],
        'caisse' => ['Caisse et finances', 'caisse, encaissements, clôtures', 'ocre', ['ROLE_ADMIN', 'ROLE_CAISSE_COMMANDE']],
    ];

    public function getDescription(): string
    {
        return 'Messagerie de l\'équipe : groupes, fichiers, réactions, épingles, droits et groupes par défaut';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE messages_chat_fichier (id INT AUTO_INCREMENT NOT NULL, genre VARCHAR(10) NOT NULL, nom VARCHAR(255) NOT NULL, stockage VARCHAR(80) DEFAULT NULL, miniature VARCHAR(80) DEFAULT NULL, extension VARCHAR(10) NOT NULL, octets BIGINT DEFAULT 0 NOT NULL, chemin_serveur VARCHAR(500) DEFAULT NULL, modifie_le DATETIME DEFAULT NULL, empreinte VARCHAR(64) DEFAULT NULL, range_dans VARCHAR(500) DEFAULT NULL, cree_le DATETIME NOT NULL, message_id INT NOT NULL, conversation_id INT NOT NULL, client_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, INDEX IDX_231F5452537A1329 (message_id), INDEX IDX_231F54529AC0396 (conversation_id), INDEX IDX_231F545219EB6921 (client_id), INDEX IDX_231F545282EA2E54 (commande_id), INDEX idx_message_chat_fichier_conversation (conversation_id, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messages_chat_reaction (id INT AUTO_INCREMENT NOT NULL, emoji VARCHAR(16) NOT NULL, cree_le DATETIME NOT NULL, message_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_AC0253DB537A1329 (message_id), INDEX IDX_AC0253DBFB88E14F (utilisateur_id), UNIQUE INDEX uniq_reaction_message_utilisateur_emoji (message_id, utilisateur_id, emoji), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE messages_chat_fichier ADD CONSTRAINT FK_231F5452537A1329 FOREIGN KEY (message_id) REFERENCES messages_chat (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE messages_chat_fichier ADD CONSTRAINT FK_231F54529AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE messages_chat_fichier ADD CONSTRAINT FK_231F545219EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE messages_chat_fichier ADD CONSTRAINT FK_231F545282EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE messages_chat_reaction ADD CONSTRAINT FK_AC0253DB537A1329 FOREIGN KEY (message_id) REFERENCES messages_chat (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE messages_chat_reaction ADD CONSTRAINT FK_AC0253DBFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql("ALTER TABLE conversations ADD type VARCHAR(12) DEFAULT 'prive' NOT NULL, ADD nom VARCHAR(80) DEFAULT NULL, ADD description VARCHAR(160) DEFAULT NULL, ADD couleur VARCHAR(10) DEFAULT NULL, ADD par_defaut VARCHAR(20) DEFAULT NULL, ADD fermeture_auto TINYINT DEFAULT 0 NOT NULL, ADD ferme_le DATETIME DEFAULT NULL, ADD ferme_motif VARCHAR(160) DEFAULT NULL, ADD fichiers_pour_tous TINYINT DEFAULT 1 NOT NULL, ADD ranger_dossier_client TINYINT DEFAULT 0 NOT NULL, ADD roles_membres JSON DEFAULT '[]' NOT NULL, ADD exclus JSON DEFAULT '[]' NOT NULL, ADD client_id INT DEFAULT NULL, ADD devis_id INT DEFAULT NULL, ADD commande_id INT DEFAULT NULL, ADD cree_par_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF119EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF141DEFADA FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF182EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversations ADD CONSTRAINT FK_C2521BF1FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C2521BF1F5FCF395 ON conversations (par_defaut)');
        $this->addSql('CREATE INDEX IDX_C2521BF119EB6921 ON conversations (client_id)');
        $this->addSql('CREATE INDEX IDX_C2521BF141DEFADA ON conversations (devis_id)');
        $this->addSql('CREATE INDEX IDX_C2521BF182EA2E54 ON conversations (commande_id)');
        $this->addSql('CREATE INDEX IDX_C2521BF1FC29C013 ON conversations (cree_par_id)');
        $this->addSql('CREATE INDEX idx_conversation_type ON conversations (type)');

        $this->addSql("ALTER TABLE messages_chat ADD genre VARCHAR(10) DEFAULT 'texte' NOT NULL, ADD epingle_le DATETIME DEFAULT NULL, ADD mentions JSON DEFAULT '[]' NOT NULL, ADD commandes JSON DEFAULT '[]' NOT NULL, ADD vocale_nom VARCHAR(60) DEFAULT NULL, ADD vocale_type VARCHAR(20) DEFAULT NULL, ADD vocale_taille INT DEFAULT NULL, ADD vocale_duree INT DEFAULT NULL, ADD vocale_empreinte VARCHAR(64) DEFAULT NULL, ADD reponse_a_id INT DEFAULT NULL, ADD epingle_par_id INT DEFAULT NULL");
        // Pièces jointes de l'ancien chat : une ligne de fichier chacune (le fichier reste sous var/uploads/chat).
        $this->addSql("INSERT INTO messages_chat_fichier (genre, nom, stockage, extension, octets, cree_le, message_id, conversation_id)
            SELECT IF(LOWER(SUBSTRING_INDEX(m.piece_jointe_fichier, '.', -1)) IN ('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'), 'photo', 'fichier'),
                   COALESCE(m.piece_jointe_nom_original, m.piece_jointe_fichier), m.piece_jointe_fichier,
                   LEFT(LOWER(SUBSTRING_INDEX(m.piece_jointe_fichier, '.', -1)), 10), 0, m.date_envoi, m.id, m.conversation_id
            FROM messages_chat m WHERE m.piece_jointe_fichier IS NOT NULL AND m.piece_jointe_fichier <> ''");
        $this->addSql('ALTER TABLE messages_chat DROP piece_jointe_fichier, DROP piece_jointe_nom_original');
        $this->addSql('ALTER TABLE messages_chat ADD CONSTRAINT FK_B770B8177B51A1B FOREIGN KEY (reponse_a_id) REFERENCES messages_chat (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE messages_chat ADD CONSTRAINT FK_B770B817E7D47291 FOREIGN KEY (epingle_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B770B8177B51A1B ON messages_chat (reponse_a_id)');
        $this->addSql('CREATE INDEX IDX_B770B817E7D47291 ON messages_chat (epingle_par_id)');
        $this->addSql('CREATE INDEX idx_message_chat_conversation ON messages_chat (conversation_id, id)');

        $this->addSql('ALTER TABLE conversation_participants ADD admin TINYINT DEFAULT 0 NOT NULL, ADD dernier_lu_id INT DEFAULT 0 NOT NULL');
        // Non-lus par fil : le dernier message lu d'après la date de dernière lecture.
        $this->addSql('UPDATE conversation_participants p SET p.dernier_lu_id = COALESCE((SELECT MAX(m.id) FROM messages_chat m WHERE m.conversation_id = p.conversation_id AND m.date_envoi <= p.date_derniere_lecture), 0) WHERE p.date_derniere_lecture IS NOT NULL');

        // Droits : la messagerie pour tous les rôles ; créer des groupes pour les chefs d'atelier.
        foreach ($this->connection->fetchAllAssociative('SELECT id, code, droits FROM role_utilisateur') as $role) {
            $droits = json_decode((string) $role['droits'], true);
            if ('ROLE_ADMIN' === $role['code'] || !\is_array($droits) || \in_array(self::ACCES, $droits, true)) {
                continue;
            }
            $droits[] = self::ACCES;
            $this->addSql('UPDATE role_utilisateur SET droits = ? WHERE id = ?', [json_encode(array_values($droits)), $role['id']]);
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, droits_ajoutes FROM `user`') as $u) {
            $droits = json_decode((string) $u['droits_ajoutes'], true);
            if (\is_array($droits) && \in_array('ROLE_ATELIER_CHEF', $droits, true) && !\in_array(self::GROUPES, $droits, true)) {
                $droits[] = self::GROUPES;
                $this->addSql('UPDATE `user` SET droits_ajoutes = ? WHERE id = ?', [json_encode(array_values($droits)), $u['id']]);
            }
        }

        // Groupes par défaut, avec leurs membres par rôle (la Direction en est responsable quand elle en est membre).
        foreach (self::PAR_DEFAUT as $cle => [$nom, $description, $couleur, $roles]) {
            $this->addSql(
                "INSERT INTO conversations (date_creation, date_dernier_message, jeton, type, nom, description, couleur, par_defaut, roles_membres, exclus, fermeture_auto, fichiers_pour_tous, ranger_dossier_client)
                 SELECT NOW(), NOW(), ?, 'permanent', ?, ?, ?, ?, ?, '[]', 0, 1, 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM conversations WHERE par_defaut = ?)",
                [bin2hex(random_bytes(16)), $nom, $description, $couleur, $cle, json_encode($roles), $cle],
            );
            $conditions = implode(' OR ', array_fill(0, \count($roles), 'JSON_CONTAINS(u.roles, ?)'));
            $this->addSql(
                "INSERT INTO conversation_participants (conversation_id, utilisateur_id, admin, dernier_lu_id)
                 SELECT c.id, u.id, IF(JSON_CONTAINS(u.roles, '\"ROLE_ADMIN\"'), 1, 0), 0 FROM conversations c JOIN `user` u ON u.actif = 1 AND ($conditions)
                 WHERE c.par_defaut = ? AND NOT EXISTS (SELECT 1 FROM conversation_participants p WHERE p.conversation_id = c.id AND p.utilisateur_id = u.id)",
                [...array_map(static fn (string $r): string => json_encode($r), $roles), $cle],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM conversations WHERE type <> 'prive'");
        $this->addSql('ALTER TABLE messages_chat ADD piece_jointe_fichier VARCHAR(255) DEFAULT NULL, ADD piece_jointe_nom_original VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE messages_chat m JOIN (SELECT message_id, MIN(id) AS id FROM messages_chat_fichier WHERE stockage IS NOT NULL GROUP BY message_id) f ON f.message_id = m.id JOIN messages_chat_fichier p ON p.id = f.id SET m.piece_jointe_fichier = p.stockage, m.piece_jointe_nom_original = p.nom');
        $this->addSql('ALTER TABLE messages_chat_fichier DROP FOREIGN KEY FK_231F5452537A1329');
        $this->addSql('ALTER TABLE messages_chat_fichier DROP FOREIGN KEY FK_231F54529AC0396');
        $this->addSql('ALTER TABLE messages_chat_fichier DROP FOREIGN KEY FK_231F545219EB6921');
        $this->addSql('ALTER TABLE messages_chat_fichier DROP FOREIGN KEY FK_231F545282EA2E54');
        $this->addSql('ALTER TABLE messages_chat_reaction DROP FOREIGN KEY FK_AC0253DB537A1329');
        $this->addSql('ALTER TABLE messages_chat_reaction DROP FOREIGN KEY FK_AC0253DBFB88E14F');
        $this->addSql('DROP TABLE messages_chat_fichier');
        $this->addSql('DROP TABLE messages_chat_reaction');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF119EB6921');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF141DEFADA');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF182EA2E54');
        $this->addSql('ALTER TABLE conversations DROP FOREIGN KEY FK_C2521BF1FC29C013');
        $this->addSql('DROP INDEX UNIQ_C2521BF1F5FCF395 ON conversations');
        $this->addSql('DROP INDEX IDX_C2521BF119EB6921 ON conversations');
        $this->addSql('DROP INDEX IDX_C2521BF141DEFADA ON conversations');
        $this->addSql('DROP INDEX IDX_C2521BF182EA2E54 ON conversations');
        $this->addSql('DROP INDEX IDX_C2521BF1FC29C013 ON conversations');
        $this->addSql('DROP INDEX idx_conversation_type ON conversations');
        $this->addSql('ALTER TABLE conversations DROP type, DROP nom, DROP description, DROP couleur, DROP par_defaut, DROP fermeture_auto, DROP ferme_le, DROP ferme_motif, DROP fichiers_pour_tous, DROP ranger_dossier_client, DROP roles_membres, DROP exclus, DROP client_id, DROP devis_id, DROP commande_id, DROP cree_par_id');
        $this->addSql('ALTER TABLE messages_chat DROP FOREIGN KEY FK_B770B8177B51A1B');
        $this->addSql('ALTER TABLE messages_chat DROP FOREIGN KEY FK_B770B817E7D47291');
        $this->addSql('DROP INDEX IDX_B770B8177B51A1B ON messages_chat');
        $this->addSql('DROP INDEX IDX_B770B817E7D47291 ON messages_chat');
        $this->addSql('DROP INDEX idx_message_chat_conversation ON messages_chat');
        $this->addSql('ALTER TABLE messages_chat DROP genre, DROP epingle_le, DROP mentions, DROP commandes, DROP vocale_nom, DROP vocale_type, DROP vocale_taille, DROP vocale_duree, DROP vocale_empreinte, DROP reponse_a_id, DROP epingle_par_id');
        $this->addSql('ALTER TABLE conversation_participants DROP admin, DROP dernier_lu_id');
        foreach ($this->connection->fetchAllAssociative('SELECT id, droits FROM role_utilisateur') as $role) {
            $droits = json_decode((string) $role['droits'], true);
            if (\is_array($droits) && \in_array(self::ACCES, $droits, true)) {
                $this->addSql('UPDATE role_utilisateur SET droits = ? WHERE id = ?', [json_encode(array_values(array_diff($droits, [self::ACCES]))), $role['id']]);
            }
        }
    }
}
