<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Compte du client à la boutique (docs/finances.md, « Compte du client ») :
 * relevé des entrées et sorties du solde (monnaie gardée, dépôts, paiements
 * avec le solde, argent rendu) et dépôts Orange Money de l'espace client.
 *
 * Le solde déjà sur une fiche devient la première ligne de son relevé
 * (« Solde déjà sur la fiche ») : la somme des lignes reste le solde.
 * La monnaie ajoutée à la main avant (« Monnaie non rendue — … », classée
 * en ajustement) passe dans la catégorie « avance client ».
 */
final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compte du client : relevé du solde, dépôts Orange Money de l\'espace client, catégorie « avance client »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE depot_solde_client (id INT AUTO_INCREMENT NOT NULL, auteur VARCHAR(80) DEFAULT NULL, montant INT NOT NULL, fonds_soutien INT DEFAULT 0 NOT NULL, frais_retrait INT DEFAULT 0 NOT NULL, reference VARCHAR(40) NOT NULL, txnid VARCHAR(60) DEFAULT NULL, statut VARCHAR(10) NOT NULL, cree_le DATETIME NOT NULL, recu_le DATETIME DEFAULT NULL, traite_le DATETIME DEFAULT NULL, motif_rejet VARCHAR(255) DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, traite_par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_7966173FB5B48B91 (public_id), INDEX IDX_7966173F19EB6921 (client_id), INDEX IDX_7966173F167FABE8 (traite_par_id), INDEX idx_depot_solde_client_statut (statut), UNIQUE INDEX uniq_depot_solde_client_reference (reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_solde_client (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(14) NOT NULL, montant INT NOT NULL, solde_apres INT NOT NULL, mode VARCHAR(20) DEFAULT NULL, reference VARCHAR(60) DEFAULT NULL, motif VARCHAR(255) DEFAULT NULL, par_nom VARCHAR(80) DEFAULT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, mouvement_tresorerie_id INT DEFAULT NULL, depot_id INT DEFAULT NULL, par_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4EE0E4CB5B48B91 (public_id), INDEX IDX_4EE0E4C19EB6921 (client_id), INDEX IDX_4EE0E4C82EA2E54 (commande_id), INDEX IDX_4EE0E4C2A4C4478 (paiement_id), INDEX IDX_4EE0E4CC1DCAF54 (mouvement_tresorerie_id), INDEX IDX_4EE0E4C8510D4DE (depot_id), INDEX IDX_4EE0E4C468486AA (par_id), INDEX idx_mouvement_solde_client_date (client_id, cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE depot_solde_client ADD CONSTRAINT FK_7966173F19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE depot_solde_client ADD CONSTRAINT FK_7966173F167FABE8 FOREIGN KEY (traite_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4C19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4C82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4C2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4CC1DCAF54 FOREIGN KEY (mouvement_tresorerie_id) REFERENCES mouvement_tresorerie (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4C8510D4DE FOREIGN KEY (depot_id) REFERENCES depot_solde_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_solde_client ADD CONSTRAINT FK_4EE0E4C468486AA FOREIGN KEY (par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE transaction_orange_money ADD depot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA078510D4DE FOREIGN KEY (depot_id) REFERENCES depot_solde_client (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E73FAA078510D4DE ON transaction_orange_money (depot_id)');

        // Solde déjà sur la fiche : première ligne du relevé.
        $maintenant = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($this->connection->fetchAllAssociative('SELECT id, solde_credit FROM clients WHERE solde_credit > 0 ORDER BY id') as $client) {
            $this->addSql(
                "INSERT INTO mouvement_solde_client (public_id, client_id, type, montant, solde_apres, par_nom, cree_le) VALUES (UNHEX(?), ?, 'reprise', ?, ?, 'migration', ?)",
                [bin2hex(Uuid::v7()->toBinary()), (int) $client['id'], (int) $client['solde_credit'], (int) $client['solde_credit'], $maintenant],
            );
        }

        // Monnaie ajoutée à la main sur une fiche avant le relevé : c'était déjà une avance du client.
        $this->addSql("UPDATE mouvement_tresorerie SET categorie = 'avance_client' WHERE categorie = 'ajustement' AND libelle LIKE 'Monnaie non rendue — %'");
    }

    public function down(Schema $schema): void
    {
        // Sans le relevé, l'avance client redevient un ajustement (neutre lui aussi).
        $this->addSql("UPDATE mouvement_tresorerie SET categorie = 'ajustement' WHERE categorie = 'avance_client'");
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA078510D4DE');
        $this->addSql('DROP INDEX IDX_E73FAA078510D4DE ON transaction_orange_money');
        $this->addSql('ALTER TABLE transaction_orange_money DROP depot_id');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4C19EB6921');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4C82EA2E54');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4C2A4C4478');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4CC1DCAF54');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4C8510D4DE');
        $this->addSql('ALTER TABLE mouvement_solde_client DROP FOREIGN KEY FK_4EE0E4C468486AA');
        $this->addSql('ALTER TABLE depot_solde_client DROP FOREIGN KEY FK_7966173F19EB6921');
        $this->addSql('ALTER TABLE depot_solde_client DROP FOREIGN KEY FK_7966173F167FABE8');
        $this->addSql('DROP TABLE mouvement_solde_client');
        $this->addSql('DROP TABLE depot_solde_client');
    }
}
