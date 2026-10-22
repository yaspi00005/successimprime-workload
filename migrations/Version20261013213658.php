<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paiement Orange Money depuis l'espace client (J5) : transactions
 * ouvertes chez Orange, tenues sur le site en ligne.
 */
final class Version20261013213658 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client : transactions Orange Money (paiement en ligne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE transaction_orange_money (id INT AUTO_INCREMENT NOT NULL, montant INT NOT NULL, order_id VARCHAR(40) NOT NULL, pay_token VARCHAR(100) NOT NULL, notif_empreinte VARCHAR(64) NOT NULL, lien_paiement LONGTEXT NOT NULL, statut VARCHAR(10) NOT NULL, txnid VARCHAR(60) DEFAULT NULL, cree_le DATETIME NOT NULL, terminee_le DATETIME DEFAULT NULL, commande_id INT NOT NULL, compte_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, INDEX IDX_E73FAA0782EA2E54 (commande_id), INDEX IDX_E73FAA07F2C56620 (compte_id), INDEX IDX_E73FAA072A4C4478 (paiement_id), UNIQUE INDEX uniq_transaction_om_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA0782EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA07F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA072A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA0782EA2E54');
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA07F2C56620');
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA072A4C4478');
        $this->addSql('DROP TABLE transaction_orange_money');
    }
}
