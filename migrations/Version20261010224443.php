<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010224443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Relances des commandes prêtes à retirer : journal des relances, type de règle, modèle de message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE relance_retrait (id INT AUTO_INCREMENT NOT NULL, canal VARCHAR(20) NOT NULL, envoyee_le DATETIME NOT NULL, commande_id INT NOT NULL, par_id INT DEFAULT NULL, regle_id INT DEFAULT NULL, INDEX IDX_B0A158BF82EA2E54 (commande_id), INDEX IDX_B0A158BF468486AA (par_id), INDEX IDX_B0A158BF8E12947B (regle_id), INDEX idx_relance_retrait_commande_date (commande_id, envoyee_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE relance_retrait ADD CONSTRAINT FK_B0A158BF82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relance_retrait ADD CONSTRAINT FK_B0A158BF468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE relance_retrait ADD CONSTRAINT FK_B0A158BF8E12947B FOREIGN KEY (regle_id) REFERENCES regle_rappel_paiement (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE regle_rappel_paiement ADD type VARCHAR(20) DEFAULT \'paiement\' NOT NULL');

        // Modèle prêt à l'emploi pour une règle « Commande prête à retirer » (aucune règle n'est créée : c'est à l'admin de l'activer).
        $this->addSql("INSERT INTO modele_message (nom, canal, sujet, contenu, actif, date_creation) VALUES ('Commande prête à retirer', 'sms', NULL, 'Bonjour {{nom}}, votre commande {{numeroCommande}} est prête chez Succès Imprim depuis le {{datePrete}}. Reste à payer : {{reste}} F. Nous vous attendons pour la récupérer. Merci !', 1, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE relance_retrait DROP FOREIGN KEY FK_B0A158BF82EA2E54');
        $this->addSql('ALTER TABLE relance_retrait DROP FOREIGN KEY FK_B0A158BF468486AA');
        $this->addSql('ALTER TABLE relance_retrait DROP FOREIGN KEY FK_B0A158BF8E12947B');
        $this->addSql('DROP TABLE relance_retrait');
        $this->addSql('DELETE FROM regle_rappel_paiement WHERE type = \'retrait\'');
        $this->addSql('DELETE FROM modele_message WHERE nom = \'Commande prête à retirer\' AND id NOT IN (SELECT modele_message_id FROM regle_rappel_paiement)');
        $this->addSql('ALTER TABLE regle_rappel_paiement DROP type');
    }
}
