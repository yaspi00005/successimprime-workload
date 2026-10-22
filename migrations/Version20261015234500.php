<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Factures et paiements de l'espace client (docs/espace-client.md) :
 * règlement en ligne de plusieurs factures et acomptes en une fois (une
 * part par commande, avoir compris), relié à sa transaction Orange Money ;
 * numéro du reçu de la caisse sur le paiement (envoyé au site) ; identifiant
 * public des règles et des rappels de paiement envoyés, pour que le site
 * montre au client ses rappels. Sur le site, une règle n'a pas de modèle de
 * message et un rappel peut attendre sa règle : les deux liens deviennent
 * facultatifs.
 */
final class Version20261015234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client : règlement en ligne de plusieurs factures, numéro de reçu, rappels de paiement envoyés au site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reglement_en_ligne (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(40) NOT NULL, montant_om INT NOT NULL, fonds_soutien INT NOT NULL, frais_retrait INT NOT NULL, avoir INT NOT NULL, statut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, recu_le DATETIME DEFAULT NULL, client_id INT NOT NULL, compte_id INT DEFAULT NULL, INDEX IDX_61AC4B2D19EB6921 (client_id), INDEX IDX_61AC4B2DF2C56620 (compte_id), INDEX idx_reglement_en_ligne_client (client_id, statut), UNIQUE INDEX uniq_reglement_en_ligne_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reglement_en_ligne_part (id INT AUTO_INCREMENT NOT NULL, nature VARCHAR(10) NOT NULL, montant_om INT NOT NULL, avoir INT NOT NULL, fonds_soutien INT NOT NULL, frais_retrait INT NOT NULL, rang INT NOT NULL, reglement_id INT NOT NULL, commande_id INT NOT NULL, facture_id INT DEFAULT NULL, paiement_om_id INT DEFAULT NULL, paiement_avoir_id INT DEFAULT NULL, INDEX IDX_CDEACAB66A477111 (reglement_id), INDEX IDX_CDEACAB682EA2E54 (commande_id), INDEX IDX_CDEACAB67F2DEE08 (facture_id), INDEX IDX_CDEACAB62594C162 (paiement_om_id), INDEX IDX_CDEACAB69CABEE1 (paiement_avoir_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reglement_en_ligne ADD CONSTRAINT FK_61AC4B2D19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reglement_en_ligne ADD CONSTRAINT FK_61AC4B2DF2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reglement_en_ligne_part ADD CONSTRAINT FK_CDEACAB66A477111 FOREIGN KEY (reglement_id) REFERENCES reglement_en_ligne (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reglement_en_ligne_part ADD CONSTRAINT FK_CDEACAB682EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reglement_en_ligne_part ADD CONSTRAINT FK_CDEACAB67F2DEE08 FOREIGN KEY (facture_id) REFERENCES factures (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reglement_en_ligne_part ADD CONSTRAINT FK_CDEACAB62594C162 FOREIGN KEY (paiement_om_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reglement_en_ligne_part ADD CONSTRAINT FK_CDEACAB69CABEE1 FOREIGN KEY (paiement_avoir_id) REFERENCES paiements (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE transaction_orange_money ADD reglement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA076A477111 FOREIGN KEY (reglement_id) REFERENCES reglement_en_ligne (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E73FAA076A477111 ON transaction_orange_money (reglement_id)');

        $this->addSql('ALTER TABLE paiements ADD numero_recu VARCHAR(20) DEFAULT NULL');

        $this->addSql('ALTER TABLE regle_rappel_paiement ADD public_id BINARY(16) DEFAULT NULL, CHANGE modele_message_id modele_message_id INT DEFAULT NULL');
        $this->addSql("UPDATE regle_rappel_paiement SET public_id = UNHEX(REPLACE(UUID(), '-', '')) WHERE public_id IS NULL");
        $this->addSql('ALTER TABLE regle_rappel_paiement MODIFY public_id BINARY(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_20002786B5B48B91 ON regle_rappel_paiement (public_id)');

        $this->addSql('ALTER TABLE rappel_paiement_envoye ADD public_id BINARY(16) DEFAULT NULL, CHANGE regle_id regle_id INT DEFAULT NULL');
        $this->addSql("UPDATE rappel_paiement_envoye SET public_id = UNHEX(REPLACE(UUID(), '-', '')) WHERE public_id IS NULL");
        $this->addSql('ALTER TABLE rappel_paiement_envoye MODIFY public_id BINARY(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DEAFEF8EB5B48B91 ON rappel_paiement_envoye (public_id)');
    }

    public function down(Schema $schema): void
    {
        // Copies reçues par le site : sans modèle ni règle, elles ne tiennent plus dans les anciennes colonnes.
        $this->addSql('DELETE FROM rappel_paiement_envoye WHERE regle_id IS NULL');
        $this->addSql('DROP INDEX UNIQ_DEAFEF8EB5B48B91 ON rappel_paiement_envoye');
        $this->addSql('ALTER TABLE rappel_paiement_envoye DROP public_id, CHANGE regle_id regle_id INT NOT NULL');
        $this->addSql('DELETE FROM regle_rappel_paiement WHERE modele_message_id IS NULL');
        $this->addSql('DROP INDEX UNIQ_20002786B5B48B91 ON regle_rappel_paiement');
        $this->addSql('ALTER TABLE regle_rappel_paiement DROP public_id, CHANGE modele_message_id modele_message_id INT NOT NULL');

        $this->addSql('ALTER TABLE paiements DROP numero_recu');

        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA076A477111');
        $this->addSql('DROP INDEX IDX_E73FAA076A477111 ON transaction_orange_money');
        $this->addSql('ALTER TABLE transaction_orange_money DROP reglement_id');

        $this->addSql('ALTER TABLE reglement_en_ligne_part DROP FOREIGN KEY FK_CDEACAB66A477111');
        $this->addSql('ALTER TABLE reglement_en_ligne_part DROP FOREIGN KEY FK_CDEACAB682EA2E54');
        $this->addSql('ALTER TABLE reglement_en_ligne_part DROP FOREIGN KEY FK_CDEACAB67F2DEE08');
        $this->addSql('ALTER TABLE reglement_en_ligne_part DROP FOREIGN KEY FK_CDEACAB62594C162');
        $this->addSql('ALTER TABLE reglement_en_ligne_part DROP FOREIGN KEY FK_CDEACAB69CABEE1');
        $this->addSql('ALTER TABLE reglement_en_ligne DROP FOREIGN KEY FK_61AC4B2D19EB6921');
        $this->addSql('ALTER TABLE reglement_en_ligne DROP FOREIGN KEY FK_61AC4B2DF2C56620');
        $this->addSql('DROP TABLE reglement_en_ligne_part');
        $this->addSql('DROP TABLE reglement_en_ligne');
    }
}
