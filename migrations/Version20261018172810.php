<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Poste machiniste (maquettes OP01 à OP06, docs/poste-machiniste.md) :
 * sur l'ordre de production, le fichier vérifié par le machiniste, son
 * estimation par étape, les étiquettes des colis et « Prêt pour livraison » ;
 * la table retour_prepresse garde les travaux renvoyés au prépresse.
 */
final class Version20261018172810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Poste machiniste : vérification du fichier, estimation, colis et retours au prépresse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ordre_production ADD fichier_verifie_le DATETIME DEFAULT NULL, ADD controle_machiniste JSON DEFAULT NULL, ADD estimation JSON DEFAULT NULL, ADD nombre_colis INT DEFAULT NULL, ADD etiquettes_imprimees_le DATETIME DEFAULT NULL, ADD pret_livraison_le DATETIME DEFAULT NULL, ADD fichier_verifie_par_id INT DEFAULT NULL, ADD pret_livraison_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ordre_production ADD CONSTRAINT FK_D1C3E844CDAC2D09 FOREIGN KEY (fichier_verifie_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ordre_production ADD CONSTRAINT FK_D1C3E844F4CCC07B FOREIGN KEY (pret_livraison_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D1C3E844CDAC2D09 ON ordre_production (fichier_verifie_par_id)');
        $this->addSql('CREATE INDEX IDX_D1C3E844F4CCC07B ON ordre_production (pret_livraison_par_id)');
        // Les travaux déjà démarrés ou terminés avaient leur fichier vérifié.
        $this->addSql("UPDATE ordre_production SET fichier_verifie_le = COALESCE(demarre_le, cree_le) WHERE statut <> 'a_produire'");

        $this->addSql('CREATE TABLE retour_prepresse (id INT AUTO_INCREMENT NOT NULL, motifs JSON NOT NULL, commentaire LONGTEXT DEFAULT NULL, annotation JSON DEFAULT NULL, cree_le DATETIME NOT NULL, corrige_le DATETIME DEFAULT NULL, ordre_id INT NOT NULL, par_id INT DEFAULT NULL, destinataire_id INT DEFAULT NULL, INDEX IDX_61D133AF9291498C (ordre_id), INDEX IDX_61D133AF468486AA (par_id), INDEX IDX_61D133AFA4F84F6E (destinataire_id), INDEX idx_retour_prepresse_ouvert (corrige_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE retour_prepresse ADD CONSTRAINT FK_61D133AF9291498C FOREIGN KEY (ordre_id) REFERENCES ordre_production (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE retour_prepresse ADD CONSTRAINT FK_61D133AF468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE retour_prepresse ADD CONSTRAINT FK_61D133AFA4F84F6E FOREIGN KEY (destinataire_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE retour_prepresse');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844CDAC2D09');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844F4CCC07B');
        $this->addSql('DROP INDEX IDX_D1C3E844CDAC2D09 ON ordre_production');
        $this->addSql('DROP INDEX IDX_D1C3E844F4CCC07B ON ordre_production');
        $this->addSql('ALTER TABLE ordre_production DROP fichier_verifie_le, DROP controle_machiniste, DROP estimation, DROP nombre_colis, DROP etiquettes_imprimees_le, DROP pret_livraison_le, DROP fichier_verifie_par_id, DROP pret_livraison_par_id');
    }
}
