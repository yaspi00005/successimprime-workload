<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Alertes système (docs/alertes-systeme.md) : une ligne par panne (erreur
 * 500, paiement Orange Money, synchronisation, envoi, sauvegarde, disque,
 * file de messages, tâche planifiée), avec son compteur, qui la prend en
 * charge et comment elle a été résolue. Les réglages (destinataires par
 * sorte, délai entre deux e-mails) vont dans la table reglage.
 */
final class Version20261018177000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alertes système : table alerte_systeme (pannes regroupées, prise en charge, résolution)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alerte_systeme (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(20) NOT NULL, type VARCHAR(20) NOT NULL, gravite VARCHAR(15) NOT NULL, titre VARCHAR(190) NOT NULL, detail LONGTEXT DEFAULT NULL, empreinte VARCHAR(40) NOT NULL, origine VARCHAR(8) DEFAULT \'local\' NOT NULL, occurrences INT DEFAULT 1 NOT NULL, premiere_le DATETIME NOT NULL, derniere_le DATETIME NOT NULL, etat VARCHAR(10) DEFAULT \'ouverte\' NOT NULL, prise_le DATETIME DEFAULT NULL, resolue_le DATETIME DEFAULT NULL, resolution LONGTEXT DEFAULT NULL, a_prevenir TINYINT DEFAULT 0 NOT NULL, prevenue_le DATETIME DEFAULT NULL, email_envoye_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, prise_par_id INT DEFAULT NULL, resolue_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_2FDA36E9B5B48B91 (public_id), INDEX IDX_2FDA36E9A7DD6C8B (prise_par_id), INDEX IDX_2FDA36E992A12474 (resolue_par_id), INDEX idx_alerte_systeme_empreinte (empreinte, etat), INDEX idx_alerte_systeme_etat (etat, derniere_le), INDEX idx_alerte_systeme_prevenir (a_prevenir), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE alerte_systeme ADD CONSTRAINT FK_2FDA36E9A7DD6C8B FOREIGN KEY (prise_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE alerte_systeme ADD CONSTRAINT FK_2FDA36E992A12474 FOREIGN KEY (resolue_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alerte_systeme DROP FOREIGN KEY FK_2FDA36E9A7DD6C8B');
        $this->addSql('ALTER TABLE alerte_systeme DROP FOREIGN KEY FK_2FDA36E992A12474');
        $this->addSql('DROP TABLE alerte_systeme');
    }
}
