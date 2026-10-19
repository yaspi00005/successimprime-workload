<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prépresse (docs/prepresse.md, maquettes PQ01 à PQ04) : journal des
 * relances pour les fichiers manquants, pause de la commande à J+15 avec
 * sa date de livraison gardée, « fichier à venir » coché à la saisie.
 */
final class Version20261020040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prépresse : relances des fichiers manquants, pause et fichier à venir';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE relance_fichier (
            id INT AUTO_INCREMENT NOT NULL,
            commande_id INT NOT NULL,
            cree_par_id INT DEFAULT NULL,
            envoye_par_id INT DEFAULT NULL,
            etape VARCHAR(10) NOT NULL,
            canal VARCHAR(12) NOT NULL,
            cree_le DATETIME NOT NULL,
            envoye_le DATETIME DEFAULT NULL,
            manquants INT DEFAULT 0 NOT NULL,
            message LONGTEXT DEFAULT NULL,
            lien VARCHAR(1000) DEFAULT NULL,
            reponse VARCHAR(255) DEFAULT NULL,
            reponse_le DATETIME DEFAULT NULL,
            INDEX idx_relance_fichier_commande (commande_id, etape),
            INDEX IDX_F25367F1FC29C013 (cree_par_id),
            INDEX IDX_F25367F1D603292 (envoye_par_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE relance_fichier ADD CONSTRAINT FK_RELANCE_FICHIER_COMMANDE FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance_fichier ADD CONSTRAINT FK_RELANCE_FICHIER_CREE_PAR FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE relance_fichier ADD CONSTRAINT FK_RELANCE_FICHIER_ENVOYE_PAR FOREIGN KEY (envoye_par_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE commandes
            ADD en_pause_fichiers_le DATETIME DEFAULT NULL,
            ADD date_livraison_avant_pause DATETIME DEFAULT NULL,
            ADD fichier_a_venir TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE relance_fichier');
        $this->addSql('ALTER TABLE commandes DROP en_pause_fichiers_le, DROP date_livraison_avant_pause, DROP fichier_a_venir');
    }
}
